<?php

declare(strict_types=1);

namespace App\Application\Invitation;

use App\Domain\Audit\AuditTrail;
use App\Domain\Identity\Actor;
use App\Domain\Space\SpaceRole;
use App\Entity\Invitation;
use App\Entity\Space;
use App\Entity\SpaceMember;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Turns an invitation into an account — together with its private space.
 *
 * Account and private space are created in ONE transaction on purpose. A write
 * that names no space lands in the owner's private one (inviolable rule 6), and
 * an agent's first write may arrive minutes after the account exists. An
 * account without its space would fail that write with an error nobody could
 * act on.
 *
 * The shared space everybody belongs to is joined here too, in the same
 * transaction and for the same reason: an account that exists but reaches no
 * team knowledge is an account somebody has to finish creating by hand.
 * See SharedSpaceForEveryone.
 */
final readonly class AcceptInvitation
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private AuditTrail $audit,
        private SharedSpaceForEveryone $sharedSpace,
    ) {
    }

    public function __invoke(string $plainToken, string $displayName, string $password): User
    {
        $invitation = $this->entityManager->getRepository(Invitation::class)
            ->findOneBy(['tokenHash' => hash('sha256', $plainToken)]);

        // One message for "no such token", "already used" and "expired". The
        // difference is useful to an attacker enumerating tokens and useless to
        // the invited person, who needs a new invitation either way.
        if (null === $invitation || !$invitation->isUsable()) {
            throw new \DomainException('Zaproszenie jest nieważne lub zostało już wykorzystane.');
        }

        $user = new User($invitation->getEmail(), $displayName);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));

        if ($invitation->grantsGlobalAdmin()) {
            $user->promoteToGlobalAdmin();
        }

        $privateSpace = Space::privateFor($user);
        $membership = new SpaceMember($privateSpace, $user, SpaceRole::Admin);

        $invitation->markAccepted();

        $this->entityManager->persist($user);
        $this->entityManager->persist($privateSpace);
        $this->entityManager->persist($membership);

        $this->audit->record(
            action: 'invitation.accepted',
            actor: Actor::human($user->getId()->toRfc4122()),
            spaceSlug: $privateSpace->getSlug(),
            target: ['email' => $user->getEmail()],
        );

        $shared = $this->sharedSpace->admit($user);
        if (null !== $shared) {
            // No actor: nobody granted this. Recording the new account as the actor
            // would read as "they let themselves in", and recording whoever invited
            // them is wrong too — a console invitation has no inviter at all. The
            // entry says what happened: the rule admitted them.
            $this->audit->record(
                action: 'space.member_added',
                spaceSlug: $this->sharedSpace->slug(),
                target: [
                    'member' => $user->getEmail(),
                    'role' => $this->sharedSpace->role()->value,
                    'reason' => 'default_space',
                ],
            );
        }

        $this->entityManager->flush();

        return $user;
    }
}
