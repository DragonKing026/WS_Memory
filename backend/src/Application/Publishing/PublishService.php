<?php

declare(strict_types=1);

namespace App\Application\Publishing;

use App\Application\Memory\MemoryService;
use App\Domain\Audit\AuditTrail;
use App\Domain\Identity\Actor;
use App\Domain\Memory\MemoryAccessDenied;
use App\Domain\Publishing\IncomingDrawer;
use App\Domain\Publishing\Landing;
use App\Domain\Publishing\LandingReason;
use App\Domain\Publishing\LandingRule;
use App\Domain\Publishing\PublishBatch;
use App\Domain\Publishing\PublishBatchRepository;
use App\Domain\Publishing\PublishBatchStatus;
use App\Domain\Publishing\PublishMode;
use App\Domain\Publishing\PublishRefused;
use App\Domain\Publishing\ScanReport;
use App\Domain\Publishing\SecretScanner;
use App\Domain\Publishing\SkippedDrawer;
use App\Domain\Space\SpaceAccessResolver;
use Symfony\Component\Uid\Uuid;

/**
 * The bridge from a local palace into the shared base (D-010, D-014, D-015).
 *
 * This is the only way knowledge enters the system other than somebody writing in
 * the wiki, and since D-014 it runs by itself: an outbox on a laptop sends what was
 * filed locally, nobody watches, and the sender is offline half the time. Three
 * consequences follow, and the whole class is built out of them.
 *
 * **The server does not trust the client.** The plugin runs the same secret filter
 * before sending, and the server runs it again on arrival. Not belt and braces —
 * the client is software on somebody's laptop that anybody can patch, skip or run
 * an old version of, so a filter that only runs there is a filter that runs
 * sometimes. Same for the landing rule: the sender may say which wing content came
 * from, never which space it goes to (except in manual mode, where the space is
 * checked like any other write).
 *
 * **Repeating a publication has to be free.** The outbox retries until the server
 * confirms, so the same drawer arrives twice as a matter of course. The pair
 * (replica, local drawer id) is unique in `ws.memory_entries`, so the second arrival
 * updates the row; the content hash catches the other duplicate — three people
 * mining one repository — within the target space.
 *
 * **A batch is all or nothing.** One transaction covers the registry rows and the
 * batch that accounts for them, because the batch is the unit of undoing: eight
 * rows and no batch would be content nobody can take back.
 *
 * One request becomes one batch PER TARGET SPACE (D-036), not one batch. Under the
 * landing rule a single send routinely splits — mapped wings to team spaces, the
 * rest to the sender's private one — and undoing has to be able to reach one
 * without the other.
 */
final readonly class PublishService
{
    /**
     * Drawers one request may carry.
     *
     * A limit rather than none, because the transaction holds row locks for the
     * length of every embedding computation in the batch, and the palace is called
     * once per drawer. The sender can split, and telling it to is better than a
     * request that times out after fifteen minutes with nothing written.
     */
    public const MAX_DRAWERS = 200;

    /** As wide as `ws.publish_batches.source_replica` and `memory_entries.source_replica`. */
    private const MAX_REPLICA_LENGTH = 100;

    public function __construct(
        private MemoryService $memory,
        private LandingRule $landing,
        private SecretScanner $secrets,
        private PublishBatchRepository $batches,
        private SpaceAccessResolver $access,
        private AuditTrail $audit,
    ) {
    }

    /**
     * Takes a batch of drawers from a local palace.
     *
     * @throws PublishRefused     when the request itself cannot be acted on
     * @throws MemoryAccessDenied when a target space is closed to this actor
     */
    public function publish(Actor $actor, PublicationRequest $request): PublishReport
    {
        $replica = trim($request->sourceReplica);
        if ('' === $replica) {
            throw PublishRefused::malformed('Publikacja musi podać identyfikator repliki (replica.json lokalnego pałaca).');
        }
        if (mb_strlen($replica) > self::MAX_REPLICA_LENGTH) {
            throw PublishRefused::malformed(\sprintf(
                'Identyfikator repliki jest dłuższy niż %d znaków.',
                self::MAX_REPLICA_LENGTH,
            ));
        }
        if ([] === $request->drawers) {
            throw PublishRefused::malformed('Partia bez szuflad — nie ma czego publikować.');
        }
        if (\count($request->drawers) > self::MAX_DRAWERS) {
            throw PublishRefused::malformed(\sprintf(
                'Partia ma %d szuflad, a maksimum to %d — podziel wysyłkę.',
                \count($request->drawers),
                self::MAX_DRAWERS,
            ));
        }

        // Everything below this line happens before a single write. Routing,
        // scanning and the permission check are all decided up front, so a batch
        // that is going to be refused is refused having changed nothing — rather
        // than halfway through, with the first four drawers already published.
        $routed = [];
        foreach ($request->drawers as $drawer) {
            $landing = $this->landing->decide(
                $actor,
                $drawer->sourceWing,
                $drawer->sourceRoom,
                $replica,
                $request->space,
            );

            $routed[] = [$drawer, $landing, $this->secrets->scan($drawer->content, $drawer->sourcePath)];
        }

        $this->refuseUnlessWritable($actor, $routed);

        return $request->preview
            ? $this->foresee($actor, $replica, $routed)
            : $this->apply($actor, $replica, $routed);
    }

    /**
     * Undoes one batch: the drawers leave the palace, their rows leave the registry,
     * the batch stays with the status `reverted`.
     *
     * Ownership of the batch is the authorisation, deliberately, and not a role in
     * the target space. Undo exists for the moment somebody notices content went
     * somewhere it should not have, and requiring `writer` would lock out exactly
     * the person who needs it most: whoever published by mistake and had their
     * access removed as the first response.
     *
     * Idempotent by construction. A second call after a partial failure finds the
     * already-deleted drawers missing from the palace, says so, and finishes the
     * job; a second call after a complete one is refused as already reverted — 409,
     * not 403, so a retrying client stops rather than giving up on everything.
     *
     * @throws PublishRefused
     */
    public function revert(Actor $actor, string $batchId): RevertReport
    {
        $batch = $this->batches->find($batchId) ?? throw PublishRefused::unknownBatch($batchId);

        // An agent acts for its owner, so the comparison is on the person: a token
        // may undo what its owner published, which is the same rule as everywhere
        // else (inviolable rule 4 — never more than the owner, and here exactly).
        if ($batch->userId !== $actor->userId) {
            throw PublishRefused::notYourBatch($batchId);
        }

        if (PublishBatchStatus::Reverted === $batch->status) {
            throw PublishRefused::alreadyReverted($batchId);
        }
        if (!$batch->isRevertable()) {
            throw PublishRefused::notApplied($batchId);
        }

        $removed = $this->memory->forgetPublication($actor, $batch->space, $batchId);

        // Marked afterwards, never before. A failure between the two leaves a batch
        // still `applied` with nothing in it — harmless, and a retry completes it.
        // The other order would leave a batch reported as undone whose drawers are
        // still there, and the person reading that report would stop looking.
        $this->batches->markReverted($batchId, new \DateTimeImmutable());

        $this->audit->record('publish.revert', $actor, $batch->space->value, [
            'batch' => $batchId,
            'drawers' => \count($removed),
            'replica' => $batch->sourceReplica,
        ]);

        return new RevertReport($batchId, $batch->space, \count($removed));
    }

    /**
     * A dry run: the same routing, the same filter, the same duplicate check, no writes.
     *
     * @param list<array{IncomingDrawer, Landing, ScanReport}> $routed
     */
    private function foresee(Actor $actor, string $replica, array $routed): PublishReport
    {
        $drawers = [];
        foreach ($routed as [$drawer, $landing, $scan]) {
            $drawers[] = $scan->isClean()
                ? DrawerReport::accepted(
                    $drawer->sourceDrawerId,
                    $landing,
                    $this->memory->foreseeFromReplica($actor, $landing->space, $replica, $drawer),
                    null,
                )
                : DrawerReport::refused(
                    $drawer->sourceDrawerId,
                    $landing,
                    SkippedDrawer::secret($drawer->sourceDrawerId, $scan),
                    null,
                );
        }

        $this->audit->record('publish.preview', $actor, null, [
            'replica' => $replica,
            'drawers' => \count($routed),
        ]);

        // No batches: a preview books nothing, which is the point of it. Reporting
        // an empty list beats inventing identifiers a caller might try to revert.
        return new PublishReport($replica, true, [], $drawers);
    }

    /**
     * The real thing, in one transaction over every batch the request produced.
     *
     * @param list<array{IncomingDrawer, Landing, ScanReport}> $routed
     */
    private function apply(Actor $actor, string $replica, array $routed): PublishReport
    {
        $groups = $this->groupBySpace($routed);

        /** @var array{list<PublishBatch>, list<DrawerReport>} $result */
        $result = $this->memory->asOnePublication(function () use ($actor, $replica, $groups): array {
            $batches = [];
            $reports = [];

            foreach ($groups as $group) {
                $batchId = Uuid::v7()->toRfc4122();
                $written = 0;
                $skipped = [];

                foreach ($group as [$drawer, $landing, $scan]) {
                    if (!$scan->isClean()) {
                        // The server refuses it even though the client sent it. The
                        // client runs the same filter; this is what happens when it
                        // did not, or ran an older one, or somebody patched it out.
                        $refusal = SkippedDrawer::secret($drawer->sourceDrawerId, $scan);
                        $skipped[] = $refusal;
                        $reports[] = DrawerReport::refused($drawer->sourceDrawerId, $landing, $refusal, $batchId);

                        continue;
                    }

                    $accepted = $this->memory->acceptFromReplica($actor, $landing->space, $replica, $drawer, $batchId);
                    $report = DrawerReport::accepted($drawer->sourceDrawerId, $landing, $accepted, $batchId);

                    if ($report->wasWritten()) {
                        ++$written;
                    } elseif (null !== $report->skipped) {
                        $skipped[] = $report->skipped;
                    }

                    $reports[] = $report;
                }

                $batch = $this->batchFor($actor, $replica, $group, $batchId, $written, $skipped);
                $this->batches->record($batch);
                $batches[] = $batch;
            }

            return [$batches, $reports];
        });

        [$batches, $reports] = $result;

        $report = new PublishReport($replica, false, $batches, $reports);

        $this->audit->record('publish.apply', $actor, null, [
            'replica' => $replica,
            'batches' => array_map(static fn (PublishBatch $b): string => $b->id, $batches),
            'written' => $report->writtenCount(),
            'skipped' => $report->skippedCount(),
        ]);

        return $report;
    }

    /**
     * Refuses the whole request if any target space is closed to this actor.
     *
     * Checked here as well as inside MemoryService, and the difference is WHEN. The
     * one in MemoryService protects memory; this one protects the batch. Without it
     * a request routing to two spaces would publish to the first and then throw on
     * the second, leaving the rest of the batch unsent and the sender's watermark in
     * a state nobody designed.
     *
     * @param list<array{IncomingDrawer, Landing, ScanReport}> $routed
     *
     * @throws MemoryAccessDenied
     */
    private function refuseUnlessWritable(Actor $actor, array $routed): void
    {
        $checked = [];
        foreach ($routed as [, $landing]) {
            if (isset($checked[$landing->space->value])) {
                continue;
            }
            $checked[$landing->space->value] = true;

            if (!$this->access->canWrite($actor, $landing->space)) {
                throw MemoryAccessDenied::write($landing->space);
            }
        }
    }

    /**
     * Splits a request into one group per target space, keeping the order within each.
     *
     * @param list<array{IncomingDrawer, Landing, ScanReport}> $routed
     *
     * @return list<list<array{IncomingDrawer, Landing, ScanReport}>>
     */
    private function groupBySpace(array $routed): array
    {
        $groups = [];
        foreach ($routed as $entry) {
            $groups[$entry[1]->space->value][] = $entry;
        }

        return array_values($groups);
    }

    /**
     * @param list<array{IncomingDrawer, Landing, ScanReport}> $group
     * @param list<SkippedDrawer>                             $skipped
     */
    private function batchFor(
        Actor $actor,
        string $replica,
        array $group,
        string $batchId,
        int $written,
        array $skipped,
    ): PublishBatch {
        $space = $group[0][1]->space;

        // A mapping may own the group even if not every drawer in it came through
        // one: somebody can map a wing onto their own private space, where mapped
        // and unmapped content then meets. The batch names the mapping if any drawer
        // used it, because that is the fact a reader wants — "did a mapping put this
        // here" — and the mode follows the same answer.
        $mirrorId = null;
        $mode = PublishMode::Selective;
        foreach ($group as [, $landing]) {
            if (LandingReason::Mapped === $landing->reason) {
                $mirrorId = $landing->mirrorId;
                $mode = PublishMode::Mirror;

                break;
            }
        }

        return new PublishBatch(
            id: $batchId,
            userId: $actor->userId,
            agentTokenId: $actor->agentTokenId,
            mirrorId: $mirrorId,
            space: $space,
            sourceReplica: $replica,
            mode: $mode,
            status: PublishBatchStatus::Applied,
            drawerCount: $written,
            skipped: $skipped,
            createdAt: new \DateTimeImmutable(),
        );
    }
}
