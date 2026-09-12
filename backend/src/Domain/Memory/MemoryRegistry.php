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
