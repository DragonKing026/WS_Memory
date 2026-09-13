<?php

declare(strict_types=1);

namespace App\Domain\Memory;

use App\Domain\Space\SpaceId;

/**
 * Port: our own record of what of ours is in the palace.
 *
 * The palace could answer "which wing is this drawer in?" itself, so the table
 * behind this port looks redundant until you ask where permissions are decided.
 * They are decided in SQL, before the semantic query runs — never on the rows
 * coming back from it (inviolable rule 3). That is what this registry is for,
 * and listing, statistics and audit come along for free.
 *
 * It also owns the transaction. A write is only real once it is booked here, so
 * the bookkeeper draws the boundary: see self::transactional().
 */
interface MemoryRegistry
{
    public function register(MemoryWrite $write): void;

    /**
     * Which space each identifier belongs to.
     *
     * Identifiers absent from the returned map are unknown to us, and the
     * difference between "unknown" and "forbidden" is not one a caller should
     * act on: both mean the content does not leave the building.
     *
     * @param list<DrawerId> $ids
     *
     * @return array<string, SpaceId> keyed by identifier
     */
    public function spacesFor(array $ids): array;

    public function spaceFor(DrawerId $id): ?SpaceId;

    /**
     * Who wrote each of these, and whether anybody has checked it.
     *
     * Separate from spacesFor() because it answers a different question and is
     * needed at a different moment: permissions are decided before the palace is
     * asked, this is read after, to describe what came back. Folding the two into
     * one call would mean fetching authorship for content nobody may see.
     *
     * Identifiers absent from the map are unknown to us — the same non-answer as
     * everywhere else in this port.
     *
     * @param list<DrawerId> $ids
     *
     * @return array<string, EntryFacts> keyed by identifier
     */
    public function describe(array $ids): array;

    /**
     * The drawer a document's content currently lives in, if it has been published.
     *
     * One row per document, which the schema enforces. Without this lookup a
     * republish could not find what to update and would file a second copy.
     */
    public function drawerForDocument(string $documentId): ?DrawerId;

    /**
     * Points a document's existing row at a different drawer.
     *
     * Only needed when a republish had to file a fresh drawer because the old one
     * was gone. Kept separate from register() so that the ordinary path cannot
     * accidentally move a row.
     */
    public function rebind(DrawerId $from, DrawerId $to): void;

    /**
     * The row a local drawer already has here, if it has one.
     *
     * The lookup that makes republishing safe. `ws.memory_entries` holds the pair
     * (owner, replica, local drawer id) unique where the replica is set, so this
     * either finds the row a second publication must update or says there is none.
     * Asked before writing rather than discovering it through a constraint
     * violation, because the violation would abort a whole batch over its most
     * ordinary event: an outbox resending something that already arrived (D-015).
     *
     * **The owner is part of the question, not context.** Without it the lookup
     * matched on a pair the caller supplies in full — so naming somebody else's
     * replica and one of their local drawer ids returned *their* row, and the
     * republication path then overwrote their drawer and moved its registry row
     * into the caller's own space. Two people are also allowed to hold the same
     * pair: replica names are chosen locally and nothing stops two laptops from
     * picking one name, which without the owner would let the first publisher
     * block the second for ever.
     */
    public function bindingForSource(
        string $ownerUserId,
        string $sourceReplica,
        string $sourceDrawerId,
    ): ?SourceBinding;

    /**
     * Whether this space already holds content with this hash.
     *
     * Answered by the `(space_id, content_hash)` index, which is deliberately not
     * unique: dropping duplicates is a publishing policy (D-014), not an invariant
     * of the data. Two people recording the same sentence through the wiki must
     * not meet a failed write — so the check lives here, at the one call site that
     * wants it, rather than in the table where it would apply to everybody.
     */
    public function drawerWithContent(SpaceId $space, string $contentHash): ?DrawerId;

    /**
     * Rewrites the row of a drawer we already know, matched by its identifier.
     *
     * Kept apart from register() for the same reason rebind() is: the ordinary
     * path must not be able to overwrite a row by accident. This one is reached
     * only when bindingForSource() has already found something to update.
     *
     * @throws \DomainException if there is no such row
     */
    public function refresh(MemoryWrite $write): void;

    /**
     * Every drawer booked as part of one publication batch.
     *
     * The batch is the unit of undoing (D-014), and this is how undoing finds what
     * to remove. Ordered so that a partial failure retries in the same order and
     * makes progress, rather than starting somewhere new each time.
     *
     * @return list<DrawerId>
     */
    public function drawersInBatch(string $batchId): array;

    /**
     * Unbooks these drawers.
     *
     * Only ever called after the palace has been asked to delete them, and that
     * order is deliberate. A row pointing at a drawer that is gone is a search
     * result nobody can open; a drawer no row points at is invisible, because the
     * second filtering layer drops what the registry does not know (D-020). Of the
     * two halves of an interrupted deletion, the second is the survivable one.
     *
     * @param list<DrawerId> $drawers
     *
     * @return int how many rows went
     */
    public function forget(array $drawers): int;

    /**
     * How many entries each of these spaces holds.
     *
     * Answered from our own table rather than from the palace, which is the whole
     * reason the table exists: an agent asking what it can see must not cost a
     * semantic query, and a space with no entries has to appear with a zero
     * rather than vanish from the answer.
     *
     * @param list<SpaceId> $spaces
     *
     * @return array<string, int> keyed by space slug, one key per requested space
     */
    public function countsFor(array $spaces): array;

    /**
     * Runs the given work inside one database transaction.
     *
     * The palace itself takes no part in it — it speaks HTTP and cannot be
     * rolled back. That asymmetry forces a choice of failure mode, and we choose
     * this one: the palace may end up holding a drawer no row points at, never
     * the reverse (D-020). An unreferenced drawer is invisible, because the
     * second filtering layer drops what the registry does not know; an
     * unreferenced row would be a result nobody can open.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function transactional(\Closure $work): mixed;
}
