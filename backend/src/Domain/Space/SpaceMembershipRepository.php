<?php

declare(strict_types=1);

namespace App\Domain\Space;

/**
 * Port: where a user's space memberships come from.
 *
 * Declared in the domain and implemented by infrastructure, so permission rules
 * can be unit-tested without a database. That matters here more than elsewhere:
 * the negative cases ("a stranger sees nothing") are the ones worth running on
 * every commit, and they must not depend on a container being up.
 */
interface SpaceMembershipRepository
{
    /**
     * Roles the user holds, keyed by space identifier.
     *
     * @return array<string, SpaceRole>
     */
    public function rolesFor(string $userId): array;
}
