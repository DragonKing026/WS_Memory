<?php

declare(strict_types=1);

namespace App\Tests\Application\Publishing;

use App\Application\Memory\MemoryService;
use App\Application\Publishing\PublicationRequest;
use App\Application\Publishing\PublishService;
use App\Domain\Identity\Actor;
use App\Domain\Memory\AcceptOutcome;
use App\Domain\Memory\MemoryAccessDenied;
use App\Domain\Publishing\IncomingDrawer;
use App\Domain\Publishing\LandingReason;
use App\Domain\Publishing\LandingRule;
use App\Domain\Publishing\Mirror;
use App\Domain\Publishing\PatternSecretScanner;
use App\Domain\Publishing\PublishBatchStatus;
use App\Domain\Publishing\PublishMode;
use App\Domain\Publishing\PublishRefused;
use App\Domain\Publishing\SkipReason;
use App\Domain\Space\SpaceAccessResolver;
use App\Domain\Space\SpaceId;
use App\Domain\Space\SpaceRole;
use App\Tests\Application\Memory\FakeSpaceCatalog;
use App\Tests\Application\Memory\FixedMemberships;
use App\Tests\Application\Memory\InMemoryMemoryRegistry;
use App\Tests\Application\Memory\InMemoryMemoryStore;
use App\Tests\Application\Memory\RecordingAuditTrail;
use App\Tests\Application\Memory\RecordingLexicalIndex;
use App\Tests\Application\Memory\RecordingMemoryBrowser;
use PHPUnit\Framework\TestCase;

/**
 * The bridge from a local palace, tested where its decisions are made.
 *
 * Everything asserted here fails silently if it is wrong, which is why it is
 * asserted at all. Publication runs unattended since D-014, so nobody is watching:
 * a duplicate that slips through looks like a busy colleague, content landing in a
 * team space instead of a private one looks like a working feature, and a batch that
 * half-committed looks fine until somebody presses undo.
 *
 * Kept off HTTP and off the database on purpose. What is interesting is the routing,
 * the refusals and the transaction — none of which is visible through a status code,
 * and all of which a test needing Docker would run rarely.
 */
final class PublishServiceTest extends TestCase
{
    private const OWNER = 'user-1';
    private const COLLEAGUE = 'user-2';
    private const PRIVATE_SPACE = 'priv_user-1';
    private const TEAM_SPACE = 'wiedza';
    private const REPLICA = 'laptop-artura';

    private InMemoryMemoryStore $palace;
    private InMemoryMemoryRegistry $registry;
    private InMemoryPublishBatchRepository $batches;
    private FakeMirrorDirectory $mirrors;
    private RecordingAuditTrail $audit;

    protected function setUp(): void
    {
        $this->palace = new InMemoryMemoryStore();
        $this->registry = new InMemoryMemoryRegistry();
        $this->batches = new InMemoryPublishBatchRepository();
        $this->mirrors = new FakeMirrorDirectory();
        $this->audit = new RecordingAuditTrail();
    }

    // ------------------------------------------------- idempotency and dedup

    /**
     * The central promise of the outbox: resending is free.
     *
     * Without it, a laptop that was offline and retried would double everything it
     * ever sent — and nothing downstream could tell the copies apart from a colleague
     * filing the same note twice.
     */
    public function testTheSameLocalDrawerSentTwiceUpdatesOneRowInsteadOfAddingASecond(): void
    {
        $service = $this->service();

        $first = $service->publish($this->owner(), $this->request([
            $this->drawer('drawer_lokalny_1', 'notatki', 'Pierwsza wersja ustalenia.'),
        ]));

        $second = $service->publish($this->owner(), $this->request([
            $this->drawer('drawer_lokalny_1', 'notatki', 'Poprawiona wersja ustalenia.'),
        ]));

        self::assertSame(AcceptOutcome::Filed, $first->drawers[0]->outcome);
        self::assertSame(AcceptOutcome::Updated, $second->drawers[0]->outcome);
        self::assertCount(1, $this->registry->rows, 'druga publikacja aktualizuje wiersz, nie tworzy drugiego');

        $drawer = $second->drawers[0]->drawer;
        self::assertNotNull($drawer);
        self::assertSame(
            'Poprawiona wersja ustalenia.',
            $this->palace->contentOf($drawer->value),
            'treść w pałacu też jest podmieniona, nie tylko wiersz',
        );
    }

    /**
     * Three people mining one repository send one text three times (D-014).
     *
     * Within a target space, the second and third copies are dropped — and the drop
     * has to happen here rather than in the table, because two people recording the
     * same sentence through the wiki must not meet a failed write.
     */
    public function testTheSameContentUnderTwoLocalIdentifiersIsStoredOnce(): void
    {
        $service = $this->service();
        $content = "Ustaliliśmy, że publikacja idzie zawsze przez API.\n";

        $service->publish($this->owner(), $this->request([
            $this->drawer('drawer_z_pierwszej_maszyny', 'notatki', $content),
        ]));
        $report = $service->publish($this->owner(), $this->request([
            $this->drawer('drawer_z_drugiej_maszyny', 'notatki', $content),
        ]));

        self::assertSame(AcceptOutcome::Duplicate, $report->drawers[0]->outcome);
        self::assertSame(SkipReason::Duplicate, $report->drawers[0]->skipped?->reason);
        self::assertCount(1, $this->registry->rows, 'jedna treść w jednej przestrzeni to jeden wiersz');
        self::assertSame(1, $report->skippedCount());
        // The one assertion that pins the mechanism to `content_hash` rather than to
        // the palace's own merging of identical content. Without our own check the
        // row count would still come out at one — MemPalace would have merged it —
        // but an embedding would have been computed for a drawer we already hold,
        // once per person mining the same repository. This is where the cost is.
        self::assertCount(1, $this->palace->writes, 'powtórzenia nie pytamy pałaca w ogóle');
    }

    // ------------------------------------------------------------ the filter

    /**
     * The server does not trust the client.
     *
     * The plugin runs the same filter before sending. This test is the case where it
     * did not — an old version, a patched one, somebody's own script — and the point
     * is that the content is refused anyway. A filter that only runs on a laptop is
     * a filter that runs sometimes.
     */
    public function testADrawerCarryingAnEnvFileIsRefusedEvenThoughTheClientSentIt(): void
    {
        $service = $this->service();

        $report = $service->publish($this->owner(), $this->request([
            $this->drawer('drawer_z_sekretem', 'projekt', <<<'ENV'
                APP_ENV=prod
                APP_SECRET=3f8a2c91d04b7e65a1f0
                WS_DB_PASSWORD=Kf7pQ2mZ9xL1
                ENV),
            $this->drawer('drawer_bez_sekretu', 'projekt', 'Zwykłe ustalenie z rozmowy.'),
        ]));

        self::assertSame(SkipReason::Secret, $report->drawers[0]->skipped?->reason);
        self::assertNull($report->drawers[0]->outcome, 'odrzucona szuflada nie ma wyniku zapisu');
        self::assertSame(AcceptOutcome::Filed, $report->drawers[1]->outcome, 'reszta partii jedzie dalej');
        self::assertCount(1, $this->registry->rows);
        self::assertCount(0, array_filter(
            $this->palace->writes,
            static fn (array $write): bool => str_contains($write['content'], 'Kf7pQ2mZ9xL1'),
        ), 'sekret nie dotarł nawet do pałaca');
    }

    /**
     * The skip report is part of the batch (D-014), or nobody reads it.
     */
    public function testTheRefusalIsRecordedOnTheBatchWithoutRepeatingTheSecret(): void
    {
        $service = $this->service();

        $report = $service->publish($this->owner(), $this->request([
            $this->drawer('drawer_z_kluczem', 'projekt', "-----BEGIN RSA PRIVATE KEY-----\nMIIEow\n"),
        ]));

        $batch = $report->batches[0];
        self::assertSame(1, $batch->skippedCount());
        self::assertSame(0, $batch->drawerCount);
        self::assertStringContainsString('klucz prywatny', $batch->skipped[0]->describe());
        self::assertStringNotContainsString('MIIEow', json_encode($batch->skipped[0]->toArray(), \JSON_THROW_ON_ERROR));
    }

    // ------------------------------------------------------- landing (D-014)

    /**
     * The default, and the reason "send everything" is safe rather than reckless.
     */
    public function testAnUnmappedWingLandsInTheOwnersPrivateSpace(): void
    {
        $report = $this->service()->publish($this->owner(), $this->request([
            $this->drawer('drawer_1', 'jakies-skrzydlo', 'Transkrypt rozmowy.'),
        ]));

        self::assertSame(self::PRIVATE_SPACE, $report->drawers[0]->space->value);
        self::assertSame(LandingReason::Unmapped, $report->drawers[0]->landing);
        self::assertSame(PublishMode::Selective, $report->batches[0]->mode);
    }

    /**
     * A colleague on the team must not be able to see it.
     *
     * The negative half of the rule above, and the one that matters: "it went to the
     * private space" is worth nothing unless the private space is actually private.
     * Asserted through the permission resolver rather than by reading a slug, because
     * the slug looking private is exactly the thing that would still be true if the
     * space were readable by everybody.
     */
    public function testContentThatLandedPrivatelyIsInvisibleToAnotherMemberOfTheTeam(): void
    {
        $report = $this->service()->publish($this->owner(), $this->request([
            $this->drawer('drawer_1', 'jakies-skrzydlo', 'Transkrypt rozmowy o wynagrodzeniach.'),
        ]));

        $drawer = $report->drawers[0]->drawer;
        self::assertNotNull($drawer);

        // Asked through the production read path, not by comparing slugs. A slug that
        // merely looks private would still look private if the space were readable by
        // everybody, which is exactly the bug this test has to be able to catch.
        $memory = $this->memoryService();

        self::assertNull(
            $memory->get(Actor::human(self::COLLEAGUE), $drawer),
            'kolega z zespołu nie dostaje treści z prywatnej przestrzeni właściciela',
        );
        self::assertNotNull(
            $memory->get($this->owner(), $drawer),
            'a właściciel dostaje — inaczej test przechodziłby dla treści, której nie ma',
        );
        self::assertSame(
            [self::TEAM_SPACE],
            array_map(
                static fn (SpaceId $space): string => $space->value,
                $this->access()->allowedSpaces(Actor::human(self::COLLEAGUE)),
            ),
            'kolega ma dostęp wyłącznie do przestrzeni zespołowej',
        );
    }

    public function testAConfirmedMappingSendsContentToTheTeamSpace(): void
    {
        $this->mirrors->given($this->mirror(isConfirmed: true));

        $report = $this->service()->publish($this->owner(), $this->request([
            $this->drawer('drawer_1', 'ws-memory', 'Ustalenie warte pokazania zespołowi.'),
        ]));

        self::assertSame(self::TEAM_SPACE, $report->drawers[0]->space->value);
        self::assertSame(LandingReason::Mapped, $report->drawers[0]->landing);
        self::assertSame(PublishMode::Mirror, $report->batches[0]->mode);
        self::assertSame('mirror-1', $report->batches[0]->mirrorId);
    }

    /**
     * The single confirmation D-014 asks for actually gates something.
     *
     * A mapping somebody proposed and nobody agreed to routes nothing. Were this
     * wrong, proposing a mapping would publish to a team — which is the one outcome
     * the whole rule exists to prevent.
     */
    public function testAMappingNobodyConfirmedSendsNothingToTheTeamSpace(): void
    {
        $this->mirrors->given($this->mirror(isConfirmed: false));

        $report = $this->service()->publish($this->owner(), $this->request([
            $this->drawer('drawer_1', 'ws-memory', 'Treść, której zespół nie ma jeszcze widzieć.'),
        ]));

        self::assertSame(self::PRIVATE_SPACE, $report->drawers[0]->space->value);
        self::assertSame(LandingReason::Unconfirmed, $report->drawers[0]->landing);
        self::assertSame(
            self::PRIVATE_SPACE,
            $report->batches[0]->space->value,
            'partia też należy do przestrzeni prywatnej',
        );
    }

    public function testAPausedMappingSendsNothingToTheTeamSpaceEither(): void
    {
        $this->mirrors->given($this->mirror(isConfirmed: true, pausedAt: new \DateTimeImmutable()));

        $report = $this->service()->publish($this->owner(), $this->request([
            $this->drawer('drawer_1', 'ws-memory', 'Treść z czasu przerwy.'),
        ]));

        self::assertSame(self::PRIVATE_SPACE, $report->drawers[0]->space->value);
        self::assertSame(LandingReason::Paused, $report->drawers[0]->landing);
    }

    public function testAnExcludedRoomStaysOutOfTheTeamSpace(): void
    {
        $this->mirrors->given($this->mirror(isConfirmed: true, excludedRooms: ['diary']));

        $report = $this->service()->publish($this->owner(), $this->request([
            $this->drawer('drawer_1', 'ws-memory', 'Wpis dziennika.', room: 'diary'),
            $this->drawer('drawer_2', 'ws-memory', 'Notatka techniczna.', room: 'technical'),
        ]));

        self::assertSame(self::PRIVATE_SPACE, $report->drawers[0]->space->value);
        self::assertSame(LandingReason::ExcludedRoom, $report->drawers[0]->landing);
        self::assertSame(self::TEAM_SPACE, $report->drawers[1]->space->value);
        self::assertCount(2, $report->batches, 'dwie przestrzenie docelowe to dwie partie (D-036)');
    }

    // ---------------------------------------------------------- permissions

    /**
     * Named or mapped, a space one cannot write to is refused.
     *
     * Refused out loud rather than redirected: an outbox told "fine" while its
     * content went somewhere else has no way to notice, and would keep sending.
     */
    public function testPublishingToASpaceWithoutTheWriterRoleIsRefused(): void
    {
        $service = $this->service([
            self::OWNER => [self::PRIVATE_SPACE => SpaceRole::Admin, self::TEAM_SPACE => SpaceRole::Reader],
        ]);

        $this->expectException(MemoryAccessDenied::class);

        $service->publish($this->owner(), $this->request(
            [$this->drawer('drawer_1', 'ws-memory', 'Treść do przestrzeni bez roli piszącego.')],
            space: new SpaceId(self::TEAM_SPACE),
        ));
    }

    public function testNothingIsWrittenWhenOneTargetSpaceIsClosed(): void
    {
        $this->mirrors->given($this->mirror(isConfirmed: true));
        $service = $this->service([
            self::OWNER => [self::PRIVATE_SPACE => SpaceRole::Admin, self::TEAM_SPACE => SpaceRole::Reader],
        ]);

        try {
            $service->publish($this->owner(), $this->request([
                $this->drawer('drawer_prywatny', 'inne-skrzydlo', 'Treść do przestrzeni prywatnej.'),
                $this->drawer('drawer_zespolowy', 'ws-memory', 'Treść do przestrzeni zespołowej.'),
            ]));
            self::fail('oczekiwano odmowy');
        } catch (MemoryAccessDenied) {
            // Expected: the point is what did NOT happen.
        }

        self::assertSame([], $this->registry->rows, 'odmowa dla jednej przestrzeni wstrzymuje całą partię');
        self::assertSame([], $this->palace->writes);
    }

    // ---------------------------------------------------------------- preview

    public function testAPreviewReportsTheLandingAndWritesNothing(): void
    {
        $this->mirrors->given($this->mirror(isConfirmed: true));

        $report = $this->service()->publish($this->owner(), $this->request(
            [$this->drawer('drawer_1', 'ws-memory', 'Treść do podglądu.')],
            preview: true,
        ));

        self::assertTrue($report->preview);
        self::assertSame(self::TEAM_SPACE, $report->drawers[0]->space->value);
        self::assertSame(AcceptOutcome::Filed, $report->drawers[0]->outcome, 'podgląd mówi, co BY się stało');
        self::assertNull($report->drawers[0]->drawer, 'a szuflady jeszcze nie ma');
        self::assertSame([], $report->batches, 'podgląd nie zakłada partii');
        self::assertSame([], $this->registry->rows);
        self::assertSame([], $this->palace->writes);
        self::assertSame([], $this->batches->batches);
    }

    /**
     * A preview that reported the routing but not the duplicates would tell somebody
     * a hundred drawers will be filed, and then file four.
     */
    public function testAPreviewAlsoReportsWhatWouldBeSkippedAsADuplicate(): void
    {
        $service = $this->service();
        $content = 'Ta sama treść dwa razy.';

        $service->publish($this->owner(), $this->request([$this->drawer('drawer_1', 'notatki', $content)]));

        $report = $service->publish($this->owner(), $this->request(
            [$this->drawer('drawer_2', 'notatki', $content)],
            preview: true,
        ));

        self::assertSame(AcceptOutcome::Duplicate, $report->drawers[0]->outcome);
    }

    // --------------------------------------------------------- the whole batch

    /**
     * A batch is all or nothing, because a batch is the unit of undoing.
     *
     * The failure is injected where it actually happens: the drawers are already in
     * the palace and booked when the batch row fails. Eight rows and no batch would
     * be content nobody can take back.
     */
    public function testAFailureHalfwayThroughABatchLeavesNoRowsBehind(): void
    {
        $service = $this->service();
        $this->batches->failOnRecord = true;

        try {
            $service->publish($this->owner(), $this->request([
                $this->drawer('drawer_1', 'notatki', 'Pierwsza szuflada partii.'),
                $this->drawer('drawer_2', 'notatki', 'Druga szuflada partii.'),
            ]));
            self::fail('oczekiwano wyjątku z zapisu partii');
        } catch (\RuntimeException) {
            // Expected.
        }

        self::assertSame([], $this->registry->rows, 'żadnego połowicznego stanu w rejestrze');
        self::assertSame([], $this->batches->batches);
        // The drawers themselves may well be in the palace, and that is the chosen
        // failure mode (D-020): content nothing points at is invisible, because the
        // second filtering layer drops what the registry does not know. The reverse —
        // rows pointing at drawers that do not exist — would be results nobody can open.
        self::assertCount(2, $this->palace->writes);
    }

    public function testABatchIsWrittenInsideExactlyOneTransaction(): void
    {
        $this->service()->publish($this->owner(), $this->request([
            $this->drawer('drawer_1', 'notatki', 'Pierwsza.'),
            $this->drawer('drawer_2', 'notatki', 'Druga.'),
        ]));

        self::assertSame(1, $this->registry->transactions, 'jedna transakcja na całą partię, nie jedna na szufladę');
    }

    // -------------------------------------------------------------- reverting

    public function testRevertingABatchRemovesTheDrawersAndLeavesTheBatchAsReverted(): void
    {
        $service = $this->service();
        $report = $service->publish($this->owner(), $this->request([
            $this->drawer('drawer_1', 'notatki', 'Poszło nie tam, gdzie miało.'),
            $this->drawer('drawer_2', 'notatki', 'To samo z drugą.'),
        ]));

        $batchId = $report->batches[0]->id;
        $result = $service->revert($this->owner(), $batchId);

        self::assertSame(2, $result->removedDrawers);
        self::assertSame([], $this->registry->rows, 'wiersze rejestru znikają');
        self::assertCount(2, $this->palace->forgotten, 'szuflady usunięte przez API pałaca, nie SQL-em');
        $reverted = $this->batches->find($batchId);
        self::assertNotNull($reverted);
        self::assertSame(PublishBatchStatus::Reverted, $reverted->status);
        self::assertNotNull($reverted->revertedAt);
    }

    public function testRevertingTwiceIsRefusedRatherThanRepeated(): void
    {
        $service = $this->service();
        $report = $service->publish($this->owner(), $this->request([
            $this->drawer('drawer_1', 'notatki', 'Treść.'),
        ]));

        $service->revert($this->owner(), $report->batches[0]->id);

        $this->expectException(PublishRefused::class);
        $service->revert($this->owner(), $report->batches[0]->id);
    }

    /**
     * Somebody else's batch answers "no such batch", not "forbidden".
     *
     * Telling a caller that a batch exists but belongs to another person is itself a
     * disclosure — the same rule the document routes follow.
     */
    public function testSomebodyElsesBatchCannotBeReverted(): void
    {
        $service = $this->service();
        $report = $service->publish($this->owner(), $this->request([
            $this->drawer('drawer_1', 'notatki', 'Treść właściciela.'),
        ]));

        $this->expectException(PublishRefused::class);
        $service->revert(Actor::human(self::COLLEAGUE), $report->batches[0]->id);
    }

    public function testAnUnknownBatchIsRefused(): void
    {
        $this->expectException(PublishRefused::class);
        $this->service()->revert($this->owner(), 'nie-ma-takiej-partii');
    }

    // --------------------------------------------------- malformed requests

    public function testAPublicationWithoutAReplicaIdentifierIsRefused(): void
    {
        $this->expectException(PublishRefused::class);
        $this->service()->publish($this->owner(), new PublicationRequest('', [
            $this->drawer('drawer_1', 'notatki', 'Treść.'),
        ]));
    }

    public function testAnEmptyBatchIsRefused(): void
    {
        $this->expectException(PublishRefused::class);
        $this->service()->publish($this->owner(), new PublicationRequest(self::REPLICA, []));
    }

    public function testABatchLargerThanTheLimitIsRefusedSoTheSenderSplitsIt(): void
    {
        $drawers = [];
        for ($i = 0; $i <= PublishService::MAX_DRAWERS; ++$i) {
            $drawers[] = $this->drawer('drawer_' . $i, 'notatki', 'Treść nr ' . $i);
        }

        $this->expectException(PublishRefused::class);
        $this->service()->publish($this->owner(), $this->request($drawers));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, array<string, SpaceRole>>|null $memberships
     */
    private function service(?array $memberships = null): PublishService
    {
        return new PublishService(
            $this->memoryService($memberships),
            new LandingRule($this->mirrors, $this->catalog()),
            new PatternSecretScanner(),
            $this->batches,
            $this->access($memberships),
            $this->audit,
        );
    }

    /**
     * @param array<string, array<string, SpaceRole>>|null $memberships
     */
    private function memoryService(?array $memberships = null): MemoryService
    {
        return new MemoryService(
            $this->palace,
            new RecordingLexicalIndex(),
            new RecordingMemoryBrowser(),
            $this->registry,
            $this->access($memberships),
            $this->catalog(),
            $this->audit,
        );
    }

    /**
     * @param array<string, array<string, SpaceRole>>|null $memberships
     */
    private function access(?array $memberships = null): SpaceAccessResolver
    {
        return new SpaceAccessResolver(new FixedMemberships($memberships ?? [
            self::OWNER => [
                self::PRIVATE_SPACE => SpaceRole::Admin,
                self::TEAM_SPACE => SpaceRole::Writer,
            ],
            // The colleague shares the team space and nothing else. That is what
            // makes the negative test above mean something.
            self::COLLEAGUE => [self::TEAM_SPACE => SpaceRole::Writer],
        ]));
    }

    private function catalog(): FakeSpaceCatalog
    {
        // Wings deliberately differ from slugs, so a test cannot pass by confusing
        // the two — a query filtered by a wing that does not exist returns nothing
        // rather than failing.
        return new FakeSpaceCatalog(
            [self::PRIVATE_SPACE => 'wing_priv_user-1', self::TEAM_SPACE => 'wing_wiedza'],
            self::OWNER,
            self::PRIVATE_SPACE,
        );
    }

    private function owner(): Actor
    {
        return Actor::human(self::OWNER);
    }

    /**
     * @param list<string> $excludedRooms
     */
    private function mirror(
        bool $isConfirmed,
        array $excludedRooms = [],
        ?\DateTimeImmutable $pausedAt = null,
    ): Mirror {
        return new Mirror(
            id: 'mirror-1',
            userId: self::OWNER,
            sourceReplica: self::REPLICA,
            sourceWing: 'ws-memory',
            space: new SpaceId(self::TEAM_SPACE),
            excludedRooms: $excludedRooms,
            isActive: true,
            isConfirmed: $isConfirmed,
            pausedAt: $pausedAt,
        );
    }

    private function drawer(string $id, string $wing, string $content, string $room = 'technical'): IncomingDrawer
    {
        return new IncomingDrawer($id, $wing, $room, $content);
    }

    /**
     * @param list<IncomingDrawer> $drawers
     */
    private function request(array $drawers, ?SpaceId $space = null, bool $preview = false): PublicationRequest
    {
        return new PublicationRequest(self::REPLICA, $drawers, $space, $preview);
    }
}
