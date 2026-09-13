<?php

declare(strict_types=1);

namespace App\Domain\Memory;

use App\Domain\Space\SpaceId;

/**
 * The row a local drawer already has here, if it has one.
 *
 * Two fields, and the second is the one people forget. Knowing that the source
 * pair is already booked tells a republication to update rather than insert;
 * knowing WHICH space it is booked in tells it whether updating is even the right
 * thing. A wing mapped to a team space today that was unmapped last week has its
 * old drawers sitting in the sender's private space, and quietly rewriting those
 * rows to point at the team space would publish a week of private content that
 * nobody re-sent.
 */
final readonly class SourceBinding
{
    public function __construct(
        public DrawerId $drawer,
        public SpaceId $space,
    ) {
    }
}
