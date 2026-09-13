<?php

declare(strict_types=1);

namespace App\Domain\Memory;

/**
 * What we know about a drawer that the palace does not.
 *
 * The palace stores content and an author label; it has no idea whether a human
 * or an agent wrote something, nor whether anybody has since checked it. Those
 * facts live in our tables, and they are the difference between a search result
 * a reader can weigh and one they have to take on faith — in a base half-written
 * by agents, that is the whole difference.
 *
 * `documentSlug` is what makes a result openable: a hit with no slug is raw
 * memory and has no page of its own, which the interface has to show rather than
 * offer a link that goes nowhere.
 */
final readonly class EntryFacts
{
    public function __construct(
        public MemoryKind $kind,
        public string $title,
        public bool $byAi,
        public bool $verified,
        public ?string $documentSlug = null,
        public ?\DateTimeImmutable $filedAt = null,
    ) {
    }
}
