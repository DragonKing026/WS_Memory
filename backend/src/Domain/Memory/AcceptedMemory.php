<?php

declare(strict_types=1);

namespace App\Domain\Memory;

use App\Domain\Space\SpaceId;

/**
 * The answer to "I sent you this drawer" — where it is now, and what changed.
 *
 * Reports the space for the same reason StoredMemory does (inviolable rule 6):
 * a sender that named no space cannot otherwise tell where its content went, and
 * with publication running unwatched that is the only moment anybody could
 * notice it went somewhere unintended.
 *
 * `drawer` is null only for AcceptOutcome::Duplicate, where nothing was written.
 * Deliberately not the existing drawer's identifier: a sender handed one would
 * reasonably take it for the drawer ITS content is in and record the pairing,
 * while in fact it belongs to somebody else's copy of the same text.
 */
final readonly class AcceptedMemory
{
    public function __construct(
        public ?DrawerId $drawer,
        public SpaceId $space,
        public AcceptOutcome $outcome,
    ) {
    }
}
