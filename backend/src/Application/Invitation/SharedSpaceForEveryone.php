<?php

declare(strict_types=1);

namespace App\Application\Invitation;

use App\Domain\Space\SpaceRole;
use App\Entity\Space;
use App\Entity\SpaceMember;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one space everybody is in from the moment their account exists.
 *
 * A new account used to land with nothing but its own private space, so the first
 * thing anybody saw was "you do not belong to any team space yet" — and somebody
 * with the administrator role had to add them by hand, once per person, forever.
 * A shared knowledge base whose default state is "you cannot see it" is not a
 * shared knowledge base.
 *
 * This is **not** in tension with D-016. That decision is about an administrator
 * reaching into a space without leaving a trace; this is a stated company policy
 * applied to everyone, recorded in the trail like any other grant. The difference
 * is between a silent exception and a visible rule.
 *
 * The space is created on first use rather than by a migration. A migration would
 * have to hard-code the slug, while this one is configurable — and an instance
 * that never accepts an invitation does not need the space to exist.
 *
 * Set `WS_DEFAULT_SPACE_SLUG` to an empty value to switch the whole thing off;
 * accounts then arrive with their private space only, as before.
 */
final readonly class SharedSpaceForEveryone
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $slug,
        private string $name,
        private string $role,
    ) {
    }

    /**
     * Puts the account in the shared space, creating that space if it is not there.
     *
     * Returns the membership so the caller can record it, or null when no default
     * space is configured — the caller then has nothing to record, which is the
     * point: nothing happened.
     */
    public function admit(User $user): ?SpaceMember
    {
        if ('' === trim($this->slug)) {
            return null;
        }

        $space = $this->findOrCreate();
        $membership = new SpaceMember($space, $user, $this->role());

        $this->entityManager->persist($membership);

        return $membership;
    }

    public function slug(): string
    {
        return trim($this->slug);
    }

    public function role(): SpaceRole
    {
        // An unreadable value is a configuration mistake, and the safe reading of
        // a mistake here is the weakest role rather than the strongest: a person
        // who should have been a reader and became one loses nothing, the other
        // way round is a quiet widening of access for everybody who joins next.
        return SpaceRole::tryFrom(trim($this->role)) ?? SpaceRole::Reader;
    }

    /**
     * Nothing is flushed here, and that is the point.
     *
     * An earlier version flushed the new space immediately so it could catch a
     * unique-key collision and re-read the winner's row. It cannot: Doctrine
     * **closes** the EntityManager on a failed flush, so the recovery path runs
     * against a manager that refuses every further call — and the caller is left
     * half way through creating an account. Sixty tests said so at once.
     *
     * So the space is persisted and left to the caller's single flush, inside the
     * one transaction that also creates the account. The rare true race — two
     * invitations accepted in the same second on an instance where the space does
     * not exist yet — is then rejected by the unique index and that acceptance
     * fails; the person tries again and the second attempt finds the space. Loud
     * and recoverable beats clever and broken.
     */
    private function findOrCreate(): Space
    {
        $space = $this->entityManager->getRepository(Space::class)
            ->findOneBy(['slug' => $this->slug()]);

        if (null !== $space) {
            return $space;
        }

        $space = new Space($this->slug(), trim($this->name));
        $this->entityManager->persist($space);

        return $space;
    }
}
