<?php

declare(strict_types=1);

namespace App\Domain\Memory;

use App\Domain\Space\SpaceId;

/**
 * One row of raw memory, as it appears in a listing.
 *
 * Deliberately not a SearchHit. A search result exists in answer to a question and
 * carries how well it answered it — a mode and a relevance. A listing answers no
 * question; it shows what is there, newest first. Reusing the search type here would
 * mean inventing a mode and a score for rows that have neither, and an invented number
 * on screen is worse than no number.
 *
 * What both types do share is the part that decides how a reader should treat the
 * content: who wrote it and whether anybody checked.
 */
final readonly class MemoryEntryView
{
    /** @param list<string> $tags */
    public function __construct(
        public DrawerId $drawer,
        public SpaceId $space,
        public MemoryKind $kind,
        public string $title,
        public array $tags,
        public bool $byAi,
        public bool $verified,
        /** Set only for a document — raw memory has no page of its own to open. */
        public ?string $documentSlug,
        public \DateTimeImmutable $filedAt,
    ) {
    }
}
