<?php

declare(strict_types=1);

namespace App\Domain\Space;

use App\Domain\Memory\PalaceWing;

/**
 * Port: what a space is, as opposed to who may enter it.
 *
 * Kept apart from SpaceMembershipRepository because the two answer different
 * questions and change for different reasons. Memberships are read on the
 * permission path of every request; this catalogue is read once a space is
 * already known to be allowed, to translate our vocabulary into the palace's.
 */
interface SpaceCatalog
{
    /**
     * The palace wing a space maps onto, or null if there is no such space.
     *
     * Usually equal to the slug, but `spaces.palace_wing` is a separate column
     * and may differ — a space can be renamed without rewriting every drawer
     * ever filed under its old name.
     */
    public function wingFor(SpaceId $space): ?PalaceWing;

    /**
     * The user's private space — where a write that names no space lands
     * (inviolable rule 6).
     *
     * Null would mean an account without its private space, which
     * AcceptInvitation makes impossible; a caller that still gets null is
     * looking at a broken account and should say so rather than guess.
     */
    public function privateSpaceOf(string $userId): ?SpaceId;
}
