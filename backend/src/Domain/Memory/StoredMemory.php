<?php

declare(strict_types=1);

namespace App\Domain\Memory;

use App\Domain\Space\SpaceId;

/**
 * Where a write ended up.
 *
 * A write returns this rather than a bare identifier because of inviolable rule 6:
 * a caller that named no space does not know where its content went, and "it went
 * somewhere sensible" is not an answer an agent can act on. Told the space, it can
 * say so to the person it is working with — and notice when that is not the space
 * they meant.
 */
final readonly class StoredMemory
{
    public function __construct(
        public DrawerId $drawer,
        public SpaceId $space,
        public MemoryKind $kind,
    ) {
    }
}
