<?php

declare(strict_types=1);

namespace App\Domain\Publishing;

use App\Domain\Space\SpaceId;

/**
 * Where one drawer is going, and why.
 *
 * The reason travels with the space because publication happens without anybody
 * watching (D-014), so the report is the only place the decision is ever
 * visible. A sender told only "priv_ab12…" cannot tell a wing they never mapped
 * from a mapping they forgot to confirm, and the second is a two-second fix that
 * looks exactly like the first.
 */
final readonly class Landing
{
    public function __construct(
        public SpaceId $space,
        public LandingReason $reason,
        /** The mapping that decided this, when one did. */
        public ?string $mirrorId = null,
    ) {
    }

    public function mode(): PublishMode
    {
        return $this->reason->mode();
    }

    /**
     * Whether this landing is visible to anybody but the sender.
     *
     * Not the same question as "is the space private": a space could be shared
     * with one other person. This answers the one D-014 promises — that nothing
     * reaches a team without a confirmed mapping.
     */
    public function reachesTheTeam(): bool
    {
        return LandingReason::Mapped === $this->reason || LandingReason::Requested === $this->reason;
    }
}
