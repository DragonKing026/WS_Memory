<?php

declare(strict_types=1);

namespace App\Domain\Memory;

use App\Domain\Space\SpaceId;

/**
 * Port: what is in memory, newest first — without asking a question.
 *
 * Browsing and searching are different needs and this is a different port for that
 * reason. Search answers "does anybody know about X"; browsing answers "what has been
 * going into memory lately", which is how a person notices that an agent has been
 * filing nonsense, or that a topic nobody owns is quietly accumulating notes.
 *
 * Answered entirely from our own registry, never from the palace. The registry knows
 * what is ours and where it belongs (that is what it is for), and a listing is exactly
 * the case where asking the palace would mean fetching content in order to show a
 * title.
 *
 * As everywhere on this boundary, the spaces are a mandatory, non-empty argument.
 */
interface MemoryBrowser
{
    /**
     * @param non-empty-list<SpaceId> $spaces
     *
     * @return list<MemoryEntryView>
     *
     * @throws \InvalidArgumentException if $spaces is empty
     */
    public function recent(
        array $spaces,
        ?MemoryKind $kind = null,
        ?\DateTimeImmutable $since = null,
        ?\DateTimeImmutable $before = null,
        int $limit = 50,
        int $offset = 0,
    ): array;
}
