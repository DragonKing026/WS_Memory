<?php

declare(strict_types=1);

namespace App\Application\Memory;

use App\Domain\Audit\AuditTrail;
use App\Domain\Identity\Actor;
use App\Domain\Memory\AcceptedMemory;
use App\Domain\Memory\AcceptOutcome;
use App\Domain\Memory\DrawerId;
use App\Domain\Memory\KnowledgeFact;
use App\Domain\Memory\LexicalIndex;
use App\Domain\Memory\MemoryAccessDenied;
use App\Domain\Memory\MemoryBrowser;
use App\Domain\Memory\MemoryEntryView;
use App\Domain\Memory\MemoryFragment;
use App\Domain\Memory\MemoryKind;
use App\Domain\Memory\MemoryQuery;
use App\Domain\Memory\MemoryRegistry;
use App\Domain\Memory\MemoryStore;
use App\Domain\Memory\MemoryWrite;
use App\Domain\Memory\PalaceWing;
use App\Domain\Memory\SearchMode;
use App\Domain\Memory\SourceBinding;
use App\Domain\Memory\StoredMemory;
use App\Domain\Publishing\IncomingDrawer;
use App\Domain\Search\SearchHit;
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
        private LexicalIndex $lexical,
        private MemoryBrowser $browser,
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
     * Exact-term search, over the text we hold ourselves (D-029).
     *
     * Deliberately routed through this class rather than letting a controller
     * hold the LexicalIndex: the set of spaces an actor may read is computed in
     * one place, and a second door that computed it again would eventually
     * compute it differently. What differs between the two modes is where the
     * text is; who may see it is decided identically.
     *
     * @param list<SpaceId>|null $inSpaces narrows the set; never widens it
     *
     * @return list<SearchHit>
     */
    public function searchLexically(Actor $actor, MemoryQuery $query, ?array $inSpaces = null): array
    {
        $allowed = $this->readableSpaces($actor, $inSpaces);

        if ([] === $allowed) {
            $this->audit->record('memory.search', $actor, null, [
                'query' => $query->text,
                'mode' => SearchMode::Lexical->value,
                'results' => 0,
            ]);

            return [];
        }

        $hits = $this->lexical->search($allowed, $query);

        $this->audit->record('memory.search', $actor, null, [
            'query' => $query->text,
            'mode' => SearchMode::Lexical->value,
            'spaces' => array_map(static fn (SpaceId $s): string => $s->value, $allowed),
            'results' => \count($hits),
        ]);

        return $hits;
    }

    /**
     * What has been going into memory lately, newest first.
     *
     * Browsing rather than searching, and routed through this class for the same
     * reason as everything else: the set of readable spaces is computed once, here.
     *
     * @param list<SpaceId>|null $inSpaces narrows the set; never widens it
     *
     * @return list<MemoryEntryView>
     */
    public function browse(
        Actor $actor,
        ?array $inSpaces = null,
        ?MemoryKind $kind = null,
        ?\DateTimeImmutable $since = null,
        ?\DateTimeImmutable $before = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $allowed = $this->readableSpaces($actor, $inSpaces);

        if ([] === $allowed) {
            return [];
        }

        $entries = $this->browser->recent($allowed, $kind, $since, $before, $limit, $offset);

        $this->audit->record('memory.browse', $actor, null, [
            'spaces' => array_map(static fn (SpaceId $s): string => $s->value, $allowed),
            'results' => \count($entries),
        ]);

        return $entries;
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
     * Answers with the space it landed in, not only an identifier: a caller that
     * named no space cannot otherwise tell where its own write went (rule 6).
     *
     * @param list<string> $tags
     */
    public function remember(
        Actor $actor,
        string $content,
        ?SpaceId $space = null,
        MemoryKind $kind = MemoryKind::Note,
        array $tags = [],
    ): StoredMemory {
        if ('' === trim($content)) {
            throw new \InvalidArgumentException('Nie zapisujemy pustej treści.');
        }

        $target = $this->writableSpace($actor, $space);
        $wing = $this->wingOf($target);
        // Asked before the transaction opens: MemoryKind::room() throws for a
        // graph fact, and finding that out mid-write would roll back a palace
        // call that should never have been made.
        $room = $kind->room();

        return $this->registry->transactional(function () use ($actor, $content, $target, $wing, $kind, $room, $tags): StoredMemory {
            $drawer = $this->store->store($wing, $kind, $content, $this->palaceAuthor($actor));

            // The palace merges identical content within a wing and answers with the
            // drawer it already holds. Writing the same note twice is ordinary — a
            // retry, or two agents recording the same finding — so it must not be an
            // error: the content IS in memory, which is what the caller wanted.
            //
            // Only when we already know that drawer under the same space, though. The
            // same drawer booked in a different space would mean our registry and the
            // palace disagree about who owns content (integrity rule 5), and that is
            // worth failing loudly over rather than papering over with a happy answer.
            $known = $this->registry->spaceFor($drawer);
            if (null !== $known) {
                if (!$known->equals($target)) {
                    throw new \DomainException(\sprintf(
                        'Szuflada %s jest już zaksięgowana w przestrzeni „%s" — zapis do „%s" zostawiłby dwie prawdy.',
                        $drawer->value,
                        $known->value,
                        $target->value,
                    ));
                }

                $this->audit->record('memory.remember', $actor, $target->value, [
                    'drawer' => $drawer->value,
                    'kind' => $kind->value,
                    'room' => $room,
                    // Recorded rather than hidden: "nothing new was written" is exactly
                    // what somebody reading the trail later needs to know.
                    'duplicate' => true,
                ]);

                return new StoredMemory($drawer, $target, $kind);
            }

            $this->registry->register(MemoryWrite::ofContent($drawer, $target, $kind, $actor, $content, $tags));

            $this->audit->record('memory.remember', $actor, $target->value, [
                'drawer' => $drawer->value,
                'kind' => $kind->value,
                'room' => $room,
            ]);

            return new StoredMemory($drawer, $target, $kind);
        });
    }

    /**
     * Publishes a document's current content into the palace, replacing the copy.
     *
     * The wiki is the source of truth and the palace holds a copy for semantic
     * search (D-004). "A copy" is the operative word: one drawer per document,
     * updated in place. Filing a new drawer per revision would leave every
     * superseded version searchable, and an agent finding old text has no way to
     * tell that a newer one exists.
     *
     * Permission is NOT checked here. The caller is the publishing worker, acting
     * for a document whose write was already authorised — re-checking would mean
     * asking whether the author still has the role they had when they wrote it,
     * and answering "no" by silently dropping content that is already in the wiki.
     */
    public function publishDocument(
        Actor $author,
        SpaceId $space,
        string $documentId,
        string $title,
        string $content,
    ): StoredMemory {
        $wing = $this->wingOf($space);
        $existing = $this->registry->drawerForDocument($documentId);

        if (null === $existing) {
            return $this->registry->transactional(function () use ($author, $space, $wing, $documentId, $title, $content): StoredMemory {
                $drawer = $this->store->store($wing, MemoryKind::Document, $content, $this->palaceAuthor($author));

                $this->registry->register(
                    MemoryWrite::forDocument($drawer, $space, $author, $documentId, $title, $content),
                );

                $this->audit->record('memory.document_published', $author, $space->value, [
                    'document' => $documentId,
                    'drawer' => $drawer->value,
                ]);

                return new StoredMemory($drawer, $space, MemoryKind::Document);
            });
        }

        // Outside a transaction on purpose: the palace call is the slow part and
        // the row is only touched if the identifier changed, which is rare. Wrapping
        // it would hold a row lock for the length of an embedding computation over a
        // whole document.
        $current = $this->store->replace($existing, $wing, MemoryKind::Document, $content, $this->palaceAuthor($author));

        if (!$current->equals($existing)) {
            // The old drawer was gone and a fresh one was filed. Repointing the row
            // is what keeps the document readable; without it the registry would
            // name a drawer that no longer exists and the second filtering layer
            // would drop every result for this document (D-019).
            $this->registry->rebind($existing, $current);
        }

        $this->audit->record('memory.document_published', $author, $space->value, [
            'document' => $documentId,
            'drawer' => $current->value,
            'replaced' => true,
        ]);

        return new StoredMemory($current, $space, MemoryKind::Document);
    }

    /**
     * Runs the given work as ONE publication: all of it is booked, or none is.
     *
     * A thin delegation to the registry's transaction, and it exists so that the
     * bridge from a local palace can own the boundary without owning the registry.
     * The alternative was a transaction per drawer, and that is not a smaller
     * version of this: a batch is the unit of undoing (D-014), so a run that dies
     * on its ninth drawer must leave nothing behind rather than eight rows and a
     * batch nobody can revert as a whole.
     *
     * What it cannot promise is the palace, which speaks HTTP and does not roll
     * back. The asymmetry forces a choice and we make the same one as everywhere
     * else: the palace may end up holding drawers no row points at, never the
     * reverse (D-020). Unreferenced drawers are invisible — the second filtering
     * layer drops what the registry does not know — while unreferenced rows would
     * be results nobody can open.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function asOnePublication(\Closure $work): mixed
    {
        return $this->registry->transactional($work);
    }

    /**
     * Takes one drawer sent up from somebody's local palace (D-010, D-014).
     *
     * Runs INSIDE the caller's transaction — see self::asOnePublication() — and
     * therefore opens none of its own. That is the one thing to remember about this
     * method: called on its own it writes to the palace with no rollback around the
     * bookkeeping.
     *
     * The space is decided before this point by the landing rule, not here, and is
     * still checked here. Not defensive duplication: this is the class that owns
     * "may this actor write there", and a second entry point to memory that skipped
     * it would be a second permission model. A sender whose role was revoked
     * between mapping a wing and sending to it is refused, not redirected.
     *
     * Three ways in, in the order they are asked:
     *
     *  1. the same local drawer arrived before — its row is refreshed, never
     *     duplicated. That is what makes an outbox safe to retry forever (D-015);
     *  2. different local drawer, content already in this space — dropped. Three
     *     people mining one repository would otherwise file one text three times;
     *  3. otherwise it is filed.
     *
     * There is no author parameter and there will not be one. Authorship comes from
     * the token, which is the whole of inviolable rule 2 — and it bites hardest
     * exactly here, on the one endpoint whose caller is a machine describing content
     * it did not itself write.
     */
    public function acceptFromReplica(
        Actor $actor,
        SpaceId $space,
        string $sourceReplica,
        IncomingDrawer $drawer,
        string $publishBatchId,
    ): AcceptedMemory {
        $target = $this->writableSpace($actor, $space);
        $wing = $this->wingOf($target);
        $hash = $drawer->contentHash();

        $binding = $this->registry->bindingForSource($sourceReplica, $drawer->sourceDrawerId);

        if (null !== $binding) {
            return $this->refreshFromReplica($actor, $target, $wing, $sourceReplica, $drawer, $publishBatchId, $binding);
        }

        if (null !== $this->registry->drawerWithContent($target, $hash)) {
            $this->audit->record('memory.publish', $actor, $target->value, [
                'replica' => $sourceReplica,
                'sourceDrawer' => $drawer->sourceDrawerId,
                'batch' => $publishBatchId,
                'outcome' => AcceptOutcome::Duplicate->value,
            ]);

            return new AcceptedMemory(null, $target, AcceptOutcome::Duplicate);
        }

        $filed = $this->store->store(
            $wing,
            MemoryKind::Transcript,
            $drawer->content,
            $this->palaceAuthor($actor),
            $drawer->sourcePath,
        );

        // The palace merges identical content within a wing and may answer with a
        // drawer we already hold — the same case self::remember() handles, and it
        // arrives here for a different reason: two replicas of the same repository
        // sending text our own hash check did not match because ours is over the
        // whole drawer and the palace's is over what it chose to store. Booked
        // already means booked; a second row for one drawer would give "which
        // space is this in?" two answers.
        $known = $this->registry->spaceFor($filed);
        if (null !== $known) {
            if (!$known->equals($target)) {
                throw new \DomainException(\sprintf(
                    'Szuflada %s jest już zaksięgowana w przestrzeni „%s" — publikacja do „%s" zostawiłaby dwie prawdy.',
                    $filed->value,
                    $known->value,
                    $target->value,
                ));
            }

            return new AcceptedMemory($filed, $target, AcceptOutcome::Duplicate);
        }

        $this->registry->register(MemoryWrite::fromReplica(
            $filed,
            $target,
            $actor,
            $sourceReplica,
            $drawer->sourceDrawerId,
            $publishBatchId,
            $drawer->content,
            $drawer->title,
            $drawer->tags,
            $drawer->filedAt,
        ));

        $this->audit->record('memory.publish', $actor, $target->value, [
            'replica' => $sourceReplica,
            'sourceDrawer' => $drawer->sourceDrawerId,
            'drawer' => $filed->value,
            'batch' => $publishBatchId,
            'outcome' => AcceptOutcome::Filed->value,
        ]);

        return new AcceptedMemory($filed, $target, AcceptOutcome::Filed);
    }

    /**
     * What self::acceptFromReplica() would do with this drawer, without doing it.
     *
     * Exists so that `preview=true` is a real answer rather than a plausible one.
     * A preview that reported the landing but not the duplicates would tell somebody
     * about to publish a hundred drawers that a hundred will be filed, and then file
     * four — and the number they saw is the number they will remember.
     *
     * Permission is checked here too, which is the other half of a useful preview:
     * being told beforehand that a wing maps onto a space you cannot write to beats
     * discovering it from a 403 on the real run.
     */
    public function foreseeFromReplica(
        Actor $actor,
        SpaceId $space,
        string $sourceReplica,
        IncomingDrawer $drawer,
    ): AcceptedMemory {
        $target = $this->writableSpace($actor, $space);

        $binding = $this->registry->bindingForSource($sourceReplica, $drawer->sourceDrawerId);
        if (null !== $binding) {
            return new AcceptedMemory($binding->drawer, $target, AcceptOutcome::Updated);
        }

        if (null !== $this->registry->drawerWithContent($target, $drawer->contentHash())) {
            return new AcceptedMemory(null, $target, AcceptOutcome::Duplicate);
        }

        // No identifier: there is no drawer yet, and inventing one for a report
        // would hand the caller something to record that will never exist.
        return new AcceptedMemory(null, $target, AcceptOutcome::Filed);
    }

    /**
     * Undoes one publication batch: drawers out of the palace, rows out of the registry.
     *
     * The order is the point. The palace is asked first and the rows go afterwards,
     * which is the opposite of the order a write uses, and deliberately so. A
     * deletion interrupted halfway leaves either rows pointing at drawers that are
     * gone, or drawers nothing points at. The first is detectable — integrity rule 5
     * reports exactly that — and a repeated revert finishes the job, because a
     * palace asked to forget something twice says so and moves on. The second is
     * invisible content sitting in a database somebody asked to have emptied, and
     * nothing would ever notice it again.
     *
     * Deleting through the engine's own API and never with SQL against the `palace`
     * schema is D-004 read in the other direction: our connection can read those
     * tables, so a DELETE there would appear to work while leaving the vector and
     * the graph behind it.
     *
     * Authorisation is the caller's, and it is ownership of the batch rather than a
     * role in the space — see PublishService, which is the only thing that can see
     * the journal. Requiring `writer` here would block the one person who most needs
     * to undo: somebody whose access was revoked right after publishing by mistake.
     *
     * @return list<DrawerId> what was removed
     */
    public function forgetPublication(Actor $actor, SpaceId $space, string $publishBatchId): array
    {
        $drawers = $this->registry->drawersInBatch($publishBatchId);

        if ([] === $drawers) {
            return [];
        }

        foreach ($drawers as $drawer) {
            $this->store->forget($drawer);
        }

        $this->registry->transactional(fn (): int => $this->registry->forget($drawers));

        $this->audit->record('memory.publish_reverted', $actor, $space->value, [
            'batch' => $publishBatchId,
            'drawers' => \count($drawers),
        ]);

        return $drawers;
    }

    /**
     * A session diary entry — raw material, written by agents about their own work.
     */
    public function diaryWrite(
        Actor $actor,
        string $entry,
        ?SpaceId $space = null,
        ?string $topic = null,
    ): StoredMemory {
        if ('' === trim($entry)) {
            throw new \InvalidArgumentException('Nie zapisujemy pustego wpisu w dzienniku.');
        }

        $target = $this->writableSpace($actor, $space);
        $wing = $this->wingOf($target);
        $author = $this->palaceAuthor($actor);

        return $this->registry->transactional(function () use ($actor, $entry, $target, $wing, $author, $topic): StoredMemory {
            $drawer = $this->store->writeDiary($wing, $author, $entry, $topic);

            $this->registry->register(
                MemoryWrite::ofContent($drawer, $target, MemoryKind::Diary, $actor, $entry),
            );

            $this->audit->record('memory.diary_write', $actor, $target->value, ['drawer' => $drawer->value]);

            return new StoredMemory($drawer, $target, MemoryKind::Diary);
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
     * A local drawer we already hold, arriving again.
     *
     * Updated in place rather than filed afresh, which is the mechanism behind the
     * unique source pair: the outbox may resend the same drawer any number of times
     * and the space keeps one copy of it (D-010, D-015).
     *
     * The space may have changed since last time, and that is handled rather than
     * refused. A wing published while unmapped has its old drawers sitting in the
     * sender's private space; confirming a mapping and resending moves them to the
     * team space, because under D-014 the mapping is what decides visibility and
     * leaving them behind would make a confirmed mapping half-apply. The move has to
     * happen in both places at once — the wing in the palace and the space in the
     * registry — or self::get() would stop handing the content out: it refuses any
     * drawer whose palace wing disagrees with the space we authorised.
     */
    private function refreshFromReplica(
        Actor $actor,
        SpaceId $target,
        PalaceWing $wing,
        string $sourceReplica,
        IncomingDrawer $drawer,
        string $publishBatchId,
        SourceBinding $binding,
    ): AcceptedMemory {
        $current = $this->store->replace(
            $binding->drawer,
            $wing,
            MemoryKind::Transcript,
            $drawer->content,
            $this->palaceAuthor($actor),
        );

        if (!$current->equals($binding->drawer)) {
            // The old drawer was gone — restored from an older backup, deleted by
            // hand — and a fresh one was filed. Repointing the row is what keeps the
            // content readable; without it the registry would name a drawer that no
            // longer exists and the second filtering layer would drop every result
            // for it (D-019).
            $this->registry->rebind($binding->drawer, $current);
        }

        $this->registry->refresh(MemoryWrite::fromReplica(
            $current,
            $target,
            $actor,
            $sourceReplica,
            $drawer->sourceDrawerId,
            $publishBatchId,
            $drawer->content,
            $drawer->title,
            $drawer->tags,
            $drawer->filedAt,
        ));

        $this->audit->record('memory.publish', $actor, $target->value, [
            'replica' => $sourceReplica,
            'sourceDrawer' => $drawer->sourceDrawerId,
            'drawer' => $current->value,
            'batch' => $publishBatchId,
            'outcome' => AcceptOutcome::Updated->value,
            // Worth its own key: a drawer changing space changes who can read it,
            // and that is the one thing in this flow a person may want to query the
            // audit log for afterwards.
            'movedFrom' => $binding->space->equals($target) ? null : $binding->space->value,
        ]);

        return new AcceptedMemory($current, $target, AcceptOutcome::Updated);
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
     *
     * Restricted to letters, digits, underscores and hyphens, and that is not
     * cosmetic: MemPalace uses this same label as a path segment when filing a
     * diary entry and rejects `ws:user/token` outright. One label for every tool
     * beats a label per tool, so the safest form wins everywhere. A live palace
     * taught us this — no double could have.
     */
    private function palaceAuthor(Actor $actor): string
    {
        return $actor->isAgent()
            ? \sprintf('ws_%s__%s', $this->safeLabel($actor->userId), $this->safeLabel((string) $actor->agentTokenId))
            : \sprintf('ws_%s', $this->safeLabel($actor->userId));
    }

    private function safeLabel(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '-', $value) ?? $value;
    }
}
