<?php

declare(strict_types=1);

namespace App\Tests\Application\Memory;

use App\Domain\Space\SpaceMembershipRepository;
use App\Domain\Space\SpaceRole;

/**
 * Memberships as a literal array, so permission tests need no database.
 */
final class FixedMemberships implements SpaceMembershipRepository
{
    /** @param array<string, array<string, SpaceRole>> $memberships */
    public function __construct(private array $memberships = [])
    {
    }

    public function rolesFor(string $userId): array
    {
        return $this->memberships[$userId] ?? [];
    }
}
