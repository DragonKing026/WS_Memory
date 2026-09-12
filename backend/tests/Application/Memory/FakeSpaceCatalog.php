<?php

declare(strict_types=1);

namespace App\Tests\Application\Memory;

use App\Domain\Memory\PalaceWing;
use App\Domain\Space\SpaceCatalog;
use App\Domain\Space\SpaceId;

/**
 * Spaces and their wings, without a database.
 *
 * Wings are deliberately NOT equal to slugs here ("alfa" maps to
 * "wing_alfa"). If a test passed only because the two happen to be the same
 * string, it would keep passing after somebody used a slug where a wing was
 * meant — and that mistake means a query filtered by a wing that does not
 * exist, which returns nothing rather than failing.
 */
final class FakeSpaceCatalog implements SpaceCatalog
{
    /** @param array<string, string> $wings slug => wing */
    public function __construct(
        private array $wings = [],
        private ?string $privateSpaceOwner = null,
        private ?string $privateSpaceSlug = null,
    ) {
    }

    public function wingFor(SpaceId $space): ?PalaceWing
    {
        return isset($this->wings[$space->value]) ? new PalaceWing($this->wings[$space->value]) : null;
    }

    public function privateSpaceOf(string $userId): ?SpaceId
    {
        return $userId === $this->privateSpaceOwner && null !== $this->privateSpaceSlug
            ? new SpaceId($this->privateSpaceSlug)
            : null;
    }
}
