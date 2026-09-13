<?php

declare(strict_types=1);

namespace App\Domain\Publishing;

use App\Domain\Space\SpaceId;

/**
 * A mapping from one wing of one local palace onto one team space.
 *
 * What a mirror does is narrower than the name suggests. It does NOT decide
 * whether content reaches the server — since D-014 everything does — it decides
 * whether the content becomes visible to other people. That is why confirmation
 * belongs here and not to publication: a human confirms once, and the thing they
 * are confirming is visibility (D-014).
 *
 * Hence self::routes(): four different states all mean "not to the team space",
 * and every one of them has to be checked, because each is a way somebody said
 * no. An unconfirmed mapping nobody agreed to, a paused one somebody switched
 * off for the afternoon, a deactivated one, an excluded room — treat any of them
 * as permission and the promise made in D-014 is broken in exactly the way that
 * makes people turn publishing off.
 */
final readonly class Mirror
{
    /**
     * @param list<string> $excludedRooms rooms of this wing that stay out of the space
     */
    public function __construct(
        public string $id,
        public string $userId,
        public string $sourceReplica,
        public string $sourceWing,
        public SpaceId $space,
        public array $excludedRooms = [],
        public bool $isActive = true,
        public bool $isConfirmed = false,
        public ?\DateTimeImmutable $pausedAt = null,
    ) {
    }

    /**
     * Whether this mapping sends the given room to its space.
     */
    public function routes(?string $room): bool
    {
        if (!$this->isConfirmed || !$this->isActive || null !== $this->pausedAt) {
            return false;
        }

        return null === $room || !\in_array($room, $this->excludedRooms, true);
    }

    /**
     * Why this mapping did not route — for a sender who needs to know what to fix.
     *
     * Answers null when it did route, so the caller cannot accidentally report a
     * reason for a success.
     */
    public function refusalReason(?string $room): ?LandingReason
    {
        if (!$this->isConfirmed) {
            return LandingReason::Unconfirmed;
        }
        if (!$this->isActive || null !== $this->pausedAt) {
            return LandingReason::Paused;
        }
        if (null !== $room && \in_array($room, $this->excludedRooms, true)) {
            return LandingReason::ExcludedRoom;
        }

        return null;
    }
}
