<?php

declare(strict_types=1);

namespace App\Tests\Application\Memory;

use App\Domain\Memory\MemoryBrowser;
use App\Domain\Memory\MemoryEntryView;
use App\Domain\Memory\MemoryKind;
use App\Domain\Space\SpaceId;

/**
 * The memory listing without a database, remembering what it was asked.
 *
 * As with the lexical index, the spaces it was called with are the point: the rule is
 * that the query only ever runs over allowed spaces, and that can only be asserted by
 * looking at the arguments — a fake returning nothing would pass either way.
 */
final class RecordingMemoryBrowser implements MemoryBrowser
{
    /** @var list<list<string>> one entry per call, space slugs as asked for */
    public array $askedFor = [];

    /** @var list<MemoryEntryView> */
    public array $answer = [];

    public function recent(
        array $spaces,
        ?MemoryKind $kind = null,
        ?\DateTimeImmutable $since = null,
        ?\DateTimeImmutable $before = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        // Mirrors the real adapter's guard, so a caller that loses the space list
        // fails in the tests too rather than only in production.
        // @phpstan-ignore identical.alwaysFalse
        if ([] === $spaces) {
            throw new \InvalidArgumentException('Przeglądanie pamięci wymaga wskazania przestrzeni.');
        }

        $this->askedFor[] = array_map(static fn (SpaceId $s): string => $s->value, $spaces);

        return $this->answer;
    }
}
