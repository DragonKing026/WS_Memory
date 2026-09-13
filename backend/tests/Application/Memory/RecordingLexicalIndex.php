<?php

declare(strict_types=1);

namespace App\Tests\Application\Memory;

use App\Domain\Memory\LexicalIndex;
use App\Domain\Memory\MemoryQuery;
use App\Domain\Space\SpaceId;
use App\Domain\Search\SearchHit;

/**
 * The lexical index without a database, remembering what it was asked.
 *
 * The spaces it was called with are the point. The permission rule for lexical
 * search is not "the results are filtered afterwards" but "the query only ever
 * runs over allowed spaces", and the only way to assert that is to look at the
 * arguments rather than at the answer — a fake returning nothing would pass
 * either way.
 */
final class RecordingLexicalIndex implements LexicalIndex
{
    /** @var list<list<string>> one entry per call, space slugs as asked for */
    public array $askedFor = [];

    /** @var list<SearchHit> */
    public array $answer = [];

    public function search(array $spaces, MemoryQuery $query): array
    {
        // Mirrors the real adapter's guard, so a caller that loses the space
        // list fails in the tests too rather than only in production.
        // @phpstan-ignore identical.alwaysFalse
        if ([] === $spaces) {
            throw new \InvalidArgumentException('Wyszukiwanie leksykalne wymaga wskazania przestrzeni.');
        }

        $this->askedFor[] = array_map(static fn (SpaceId $s): string => $s->value, $spaces);

        return $this->answer;
    }
}
