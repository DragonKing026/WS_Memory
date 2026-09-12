<?php

declare(strict_types=1);

namespace App\Domain\Space;

use App\Domain\Identity\Actor;

/**
 * The single place that decides who may see and change what.
 *
 * Every read of the palace and every write goes through here, because a
 * permission rule duplicated in two places is a permission rule that will
 * eventually differ in two places — and the difference shows up as a leak, not
 * as an error (inviolable rule 3).
 *
 * Deliberately holds no cache: a revoked role must stop granting access at
 * once, not at the end of some time-to-live.
 */
final readonly class SpaceAccessResolver
{
    public function __construct(private SpaceMembershipRepository $memberships)
    {
    }

    /**
     * Spaces this actor may read.
     *
     * @return list<SpaceId>
     */
    public function allowedSpaces(Actor $actor): array
    {
        $slugs = array_keys($this->effectiveRoles($actor));

        return array_map(static fn (string $slug): SpaceId => new SpaceId($slug), $slugs);
    }

    public function roleIn(Actor $actor, SpaceId $space): ?SpaceRole
    {
        return $this->effectiveRoles($actor)[$space->value] ?? null;
    }

    public function canRead(Actor $actor, SpaceId $space): bool
    {
        return null !== $this->roleIn($actor, $space);
    }

    public function canWrite(Actor $actor, SpaceId $space): bool
    {
        return $this->roleIn($actor, $space)?->isAtLeast(SpaceRole::Writer) ?? false;
    }

    public function canAdminister(Actor $actor, SpaceId $space): bool
    {
        return $this->roleIn($actor, $space)?->isAtLeast(SpaceRole::Admin) ?? false;
    }

    /**
     * The owner's roles narrowed by the token scope.
     *
     * A global administrator gains nothing here on purpose. Administering
     * accounts and spaces is one thing; reading other people's private content
     * unnoticed is another. An administrator who needs access grants themselves
     * membership — and that action lands in the audit log, which a silent read
     * never would (D-016).
     *
     * @return array<string, SpaceRole>
     */
    private function effectiveRoles(Actor $actor): array
    {
        $roles = $this->memberships->rolesFor($actor->userId);

        if (null === $actor->spaceScope) {
            return $roles;
        }

        $scoped = [];
        foreach ($actor->spaceScope as $space) {
            // Intersection, never union: a scope may only narrow. A token
            // naming a space its owner cannot reach gains nothing by it.
            if (isset($roles[$space->value])) {
                $scoped[$space->value] = $roles[$space->value];
            }
        }

        return $scoped;
    }
}
