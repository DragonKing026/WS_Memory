<?php

declare(strict_types=1);

namespace App\Domain\Search;

use App\Domain\Memory\DrawerId;
use App\Domain\Memory\MemoryKind;
use App\Domain\Memory\SearchMode;
use App\Domain\Space\SpaceId;

/**
 * One search result, as a person needs to read it.
 *
 * A separate type from MemoryFragment, for two reasons that are not stylistic.
 *
 * **It carries who wrote it and whether anybody checked.** A fragment does not,
 * and cannot: authorship and verification live in our tables, not in the palace.
 * That information is not decoration — in a base half-written by agents it
 * decides how the reader should treat what they are reading, so it belongs in
 * the result type rather than in an optional lookup somebody may forget.
 *
 * **Its identifier is optional.** A document is searchable the moment it is
 * saved, while its drawer is filed by the worker a moment later (see
 * PublishDocumentHandler). Requiring a DrawerId would mean a person who just
 * wrote a page cannot find it for a few seconds, which is precisely when they
 * are most likely to look.
 *
 * `score` is comparable only within one `mode`: cosine similarity and ts_rank are
 * different scales with different ranges. Presenting them as one number, or
 * sorting a mixed list by it, produces an order that means nothing — hence the
 * mode travels with the score rather than beside it.
 */
final readonly class SearchHit
{
    public function __construct(
        public SpaceId $space,
        public MemoryKind $kind,
        public string $title,
        public Snippet $snippet,
        public SearchMode $mode,
        public ?float $score,
        /**
         * Below the relevance threshold for its mode.
         *
         * Semantic search always answers, just progressively worse, so "no good
         * matches" has to be expressed as a property of the results rather than
         * as an empty list. The interface separates these out instead of letting
         * them pose as answers (TODO-007).
         */
        public bool $weak,
        public bool $byAi,
        public bool $verified,
        public ?DrawerId $drawer = null,
        public ?string $documentSlug = null,
        public ?\DateTimeImmutable $at = null,
    ) {
    }
}
