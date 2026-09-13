<?php

declare(strict_types=1);

namespace App\Domain\Memory;

use App\Domain\Search\SearchHit;
use App\Domain\Space\SpaceId;

/**
 * Port: exact-term search over the text we hold ourselves.
 *
 * This exists because the palace has no lexical mode (D-029). It is deliberately
 * NOT a second MemoryStore: it answers from our own tables, its coverage is
 * different, and pretending the two are interchangeable is exactly the mistake
 * that would produce a search screen quietly missing half the base.
 *
 * **What it can see, stated here because callers must be able to say so:**
 *
 *   - documents — the full content of the current revision;
 *   - everything else (notes, diary, transcripts) — the title and tags only.
 *
 * The body of a note lives in the palace and nowhere else, so no implementation
 * of this port can search it. A UI built on this must say so rather than imply
 * full coverage.
 *
 * Like MemoryStore::search, the "where" is a mandatory argument rather than a
 * field on the query: a query object able to carry its own scope is a query
 * object somebody will eventually build from a request payload, and the whole
 * permission model would then rest on nobody ever doing that (inviolable rule 3).
 */
interface LexicalIndex
{
    /**
     * Matches within the given spaces, best first.
     *
     * @param non-empty-list<SpaceId> $spaces the spaces to search; never empty,
     *                                        because an empty list here would be
     *                                        indistinguishable from "no filter"
     *                                        and would search everybody's content
     *
     * @return list<SearchHit> each with `mode` set to SearchMode::Lexical
     *
     * @throws \InvalidArgumentException if $spaces is empty
     */
    public function search(array $spaces, MemoryQuery $query): array;
}
