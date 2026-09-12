<?php

declare(strict_types=1);

namespace App\Application\Memory;

use App\Domain\Audit\AuditTrail;
use App\Domain\Identity\Actor;
use App\Domain\Memory\DrawerId;
use App\Domain\Memory\KnowledgeFact;
use App\Domain\Memory\MemoryAccessDenied;
use App\Domain\Memory\MemoryFragment;
use App\Domain\Memory\MemoryKind;
use App\Domain\Memory\MemoryQuery;
use App\Domain\Memory\MemoryRegistry;
use App\Domain\Memory\MemoryStore;
use App\Domain\Memory\MemoryWrite;
use App\Domain\Memory\PalaceWing;
use App\Domain\Space\SpaceAccessResolver;
use App\Domain\Space\SpaceCatalog;
use App\Domain\Space\SpaceId;

/**
 * The only way in and out of memory.
 *
 * Both surfaces — REST /api for people, MCP /mcp for agents (D-008) — call this
 * class and nothing below it. That is the whole point: the permission rules are
 * written once here, so choosing a different door cannot get you a different
 * answer. Nothing above this layer may hold a MemoryStore.
 *
 * Three rules are enforced here rather than trusted:
 *
 *  - a read reaches the palace once per allowed space, each call carrying a
 *    wing, and never once without one (inviolable rule 3);
 *  - what comes back is checked a second time against the registry, because the
 *    palace is a separate process and its answer is not evidence of ownership;
 *  - a write that names no space lands in the actor's private one (rule 6),
 *    and is only real once it is booked in the registry.
 *
 * A read outside one's permissions answers empty; a write outside them raises
 * MemoryAccessDenied. The asymmetry is deliberate and explained in docs/03.
 */
final readonly class MemoryService
{
    public function __construct(
        private MemoryStore $store,
        private MemoryRegistry $registry,
        private SpaceAccessResolver $access,
        private SpaceCatalog $spaces,
        private AuditTrail $audit,
    ) {
    }

    /**
     * Semantic search across every space the actor may read.
     *
     * @param list<SpaceId>|null $inSpaces narrows the set; never widens it
     *
     * @return list<MemoryFragment>
     */
    public function search(Actor $actor, MemoryQuery $query, ?array $inSpaces = null): array
    {
        $allowed = $this->readableSpaces($actor, $inSpaces);

        // An empty intersection is an empty result, not an error: telling an
        // agent that the space it asked for exists but is closed to it is
        // itself a disclosure (docs/03). It is also the case where a shortcut
        // like "no spaces, so no filter" would turn into an unfiltered query
        // over everybody's content, which is why the palace is not called.
        if ([] === $allowed) {
            $this->audit->record('memory.search', $actor, null, ['query' => $query->text, 'results' => 0]);

            return [];
        }

        $fragments = [];
        foreach ($allowed as $space) {
            $wing = $this->spaces->wingFor($space);
            if (null === $wing) {
                // A membership pointing at a space that no longer exists. Not
                // an error worth failing a search over, but not something to
                // search under a guessed wing name either.
                continue;
            }

            foreach ($this->store->search($wing, $query) as $fragment) {
                $fragments[] = $fragment->withSpace($space);
            }
        }

        $fragments = $this->keepOnlyRegistered($fragments, $allowed);

        // Re-rank rather than concatenate: one call per wing means the palace
        // ordered each answer on its own, and the caller asked for the best N
        // overall, not the best N from whichever space came first.
        usort(
            $fragments,
            static fn (MemoryFragment $a, MemoryFragment $b): int => ($b->similarity ?? 0.0) <=> ($a->similarity ?? 0.0),
        );
        $fragments = \array_slice($fragments, 0, $query->limit);

        $this->audit->record('memory.search', $actor, null, [
            'query' => $query->text,
            'spaces' => array_map(static fn (SpaceId $s): string => $s->value, $allowed),
            'results' => \count($fragments),
        ]);

        return $fragments;
    }

    /**
     * One piece of content, or null if the actor may not have it.
     *
     * Null covers both "no such drawer" and "not yours" on purpose — see the
     * matching test. The ownership check runs against our registry BEFORE the
     * palace is asked, so forbidden content is never fetched, not merely never
     * returned.
     */
    public function get(Actor $actor, DrawerId $drawer): ?MemoryFragment
    {
        $space = $this->registry->spaceFor($drawer);
        if (null === $space || !$this->access->canRead($actor, $space)) {
            return null;
        }

        $fragment = $this->store->fetch($drawer);
        if (null === $fragment) {
            return null;
        }

        // The palace's own wing must agree with the space we just authorised.
        // A mismatch means our registry and the palace have drifted apart
        // (integrity rule 5) — the periodic check reports it; here we simply
        // do not hand out content on the strength of a stale row.
        $wing = $this->spaces->wingFor($space);
        if (null === $wing || !$fragment->wing->equals($wing)) {
            return null;
        }

        $this->audit->record('memory.read', $actor, $space->value, ['drawer' => $drawer->value]);

        return $fragment->withSpace($space);
    }

    /**
     * Files content into the palace and books it in the registry.
     *
     * @param list<string> $tags
     */
    public function remember(
        Actor $actor,
        string $content,
        ?SpaceId $space = null,
        MemoryKind $kind = MemoryKind::Note,
        array $tags = [],
    ): DrawerId {
        if ('' === trim($content)) {
            throw new \InvalidArgumentException('Nie zapisujemy pustej treści.');
        }

        $target = $this->writableSpace($actor, $space);
        $wing = $this->wingOf($target);
        // Asked before the transaction opens: MemoryKind::room() throws for a
        // graph fact, and finding that out mid-write would roll back a palace
        // call that should never have been made.
        $room = $kind->room();

        return $this->registry->transactional(function () use ($actor, $content, $target, $wing, $kind, $room, $tags): DrawerId {
            $drawer = $this->store->store($wing, $kind, $content, $this->palaceAuthor($actor));

            $this->registry->register(MemoryWrite::ofContent($drawer, $target, $kind, $actor, $content, $tags));

            $this->audit->record('memory.remember', $actor, $target->value, [
                'drawer' => $drawer->value,
                'kind' => $kind->value,
                'room' => $room,
            ]);

            return $drawer;
        });
    }

    /**
     * A session diary entry — raw material, written by agents about their own work.
     */
    public function diaryWrite(
        Actor $actor,
        string $entry,
        ?SpaceId $space = null,
        ?string $topic = null,
    ): DrawerId {
        if ('' === trim($entry)) {
            throw new \InvalidArgumentException('Nie zapisujemy pustego wpisu w dzienniku.');
        }

        $target = $this->writableSpace($actor, $space);
        $wing = $this->wingOf($target);
        $author = $this->palaceAuthor($actor);

        return $this->registry->transactional(function () use ($actor, $entry, $target, $wing, $author, $topic): DrawerId {
            $drawer = $this->store->writeDiary($wing, $author, $entry, $topic);

            $this->registry->register(
                MemoryWrite::ofContent($drawer, $target, MemoryKind::Diary, $actor, $entry),
            );

            $this->audit->record('memory.diary_write', $actor, $target->value, ['drawer' => $drawer->value]);

            return $drawer;
        });
    }

    /**
     * Facts about an entity, from every space the actor may read.
     *
     * @param 'outgoing'|'incoming'|'both'|null $direction
     * @param list<SpaceId>|null               $inSpaces
     *
     * @return list<KnowledgeFact>
     */
    public function kgQuery(
        Actor $actor,
        string $entity,
        ?string $direction = null,
        ?array $inSpaces = null,
    ): array {
        $allowed = $this->readableSpaces($actor, $inSpaces);
        if ([] === $allowed || '' === trim($entity)) {
            return [];
        }

        $facts = [];
        foreach ($allowed as $space) {
            $wing = $this->spaces->wingFor($space);
            if (null === $wing) {
                continue;
            }

            // The query itself is scoped, so facts from other spaces are not
            // fetched and discarded — they are not matched (D-021).
            foreach ($this->store->queryFacts($wing->qualify($entity), $direction) as $scoped) {
                $fact = $scoped->unscopedFrom($wing);
                if (null !== $fact) {
                    $facts[] = $fact;
                }
            }
        }

        $this->audit->record('memory.kg_query', $actor, null, [
            'entity' => $entity,
            'facts' => \count($facts),
        ]);

        return $facts;
    }

    /**
     * Records a fact in the graph, scoped to one space.
     */
    public function kgAdd(Actor $actor, KnowledgeFact $fact, ?SpaceId $space = null): void
    {
        $target = $this->writableSpace($actor, $space);
        $wing = $this->wingOf($target);

        $this->registry->transactional(function () use ($actor, $fact, $target, $wing): null {
            $this->store->addFact($fact->scopedTo($wing));

            // The registry row is keyed by the UNSCOPED fact plus the space.
            // Encoding the wing twice would make the row unfindable from a
            // query that only knows the bare entity name.
            $this->registry->register(new MemoryWrite(
                DrawerId::forFact($fact, $target),
                $target,
                MemoryKind::KgFact,
                $actor,
                $fact->describe(),
                hash('sha256', $fact->fingerprint()),
            ));

            $this->audit->record('memory.kg_add', $actor, $target->value, ['fact' => $fact->describe()]);

            return null;
        });
    }

    /**
     * The spaces a read may touch: the actor's own, optionally narrowed.
     *
     * @param list<SpaceId>|null $requested
     *
     * @return list<SpaceId>
     */
    private function readableSpaces(Actor $actor, ?array $requested): array
    {
        $allowed = $this->access->allowedSpaces($actor);

        if (null === $requested) {
            return $allowed;
        }

        $wanted = array_map(static fn (SpaceId $s): string => $s->value, $requested);

        // Intersection, never union — exactly as an agent token's scope works.
        // A space the actor cannot read gains nothing by being asked for.
        return array_values(array_filter(
            $allowed,
            static fn (SpaceId $space): bool => \in_array($space->value, $wanted, true),
        ));
    }

    /**
     * Where a write goes, and whether it may go there at all.
     */
    private function writableSpace(Actor $actor, ?SpaceId $space): SpaceId
    {
        $target = $space ?? $this->spaces->privateSpaceOf($actor->userId);

        if (null === $target) {
            // Every account gets its private space when the invitation is
            // accepted, so this means a broken account rather than a missing
            // argument — and saying so beats filing content somewhere else.
            throw new \DomainException('Konto nie ma prywatnej przestrzeni — zapis bez wskazanej przestrzeni jest niemożliwy.');
        }

        if (!$this->access->canWrite($actor, $target)) {
            throw MemoryAccessDenied::write($target);
        }

        return $target;
    }

    private function wingOf(SpaceId $space): PalaceWing
    {
        return $this->spaces->wingFor($space)
            ?? throw new \DomainException(\sprintf('Przestrzeń „%s" nie ma przypisanego skrzydła w pałacu.', $space->value));
    }

    /**
     * Drops everything the registry does not place in an allowed space.
     *
     * The second of two layers (D-019). The first — the wing filter — already
     * limited the question; this one checks the answer, because the palace is a
     * separate process that can be wrong, upgraded, or restored from a backup
     * older than our own table. Content the registry does not know is dropped
     * rather than reported: it is invisible by construction, which is the safe
     * direction to fail in.
     *
     * @param list<MemoryFragment> $fragments
     * @param list<SpaceId>        $allowed
     *
     * @return list<MemoryFragment>
     */
    private function keepOnlyRegistered(array $fragments, array $allowed): array
    {
        if ([] === $fragments) {
            return [];
        }

        $registered = $this->registry->spacesFor(array_map(
            static fn (MemoryFragment $f): DrawerId => $f->id,
            $fragments,
        ));
        $allowedSlugs = array_map(static fn (SpaceId $s): string => $s->value, $allowed);

        return array_values(array_filter($fragments, static function (MemoryFragment $fragment) use ($registered, $allowedSlugs): bool {
            $booked = $registered[$fragment->id->value] ?? null;

            return null !== $booked
                && \in_array($booked->value, $allowedSlugs, true)
                // The space we searched under must be the space it is booked in.
                // A difference means the wing-to-space mapping is ambiguous, and
                // an ambiguous mapping is not a basis for handing out content.
                && $booked->value === $fragment->space?->value;
        }));
    }

    /**
     * How this actor is recorded in the palace.
     *
     * Prefixed, because the palace also holds content filed by its own miner and
     * by local palaces; "who filed this" has to stay answerable after the fact.
     * Never taken from a request parameter — there is no author argument
     * anywhere in this class, which is what inviolable rule 2 asks for.
     */
    private function palaceAuthor(Actor $actor): string
    {
        return $actor->isAgent()
            ? \sprintf('ws:%s/%s', $actor->userId, (string) $actor->agentTokenId)
            : \sprintf('ws:%s', $actor->userId);
    }
}
