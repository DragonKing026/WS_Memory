<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Audit\AuditTrail;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Sets a new password on an account that already exists.
 *
 * This is the way back into an installation. Until it existed, an administrator who
 * had lost their password could only be recovered by issuing a fresh invitation to
 * a different address — the account stayed locked and a second one appeared next to
 * it, which is repair by accumulation.
 *
 * **The audit entry has no actor, and that is the fact it records.** Nobody is
 * signed in on a console; writing the account itself as the actor would read as "they
 * reset their own password", which is precisely what did not happen, and there is no
 * other identity to name — whoever ran the command is identified by having a shell on
 * the server, not by an account in this database. The same reasoning as the
 * actorless `space.member_added` entry in AcceptInvitation.
 *
 * The password is **not** validated here. Validation belongs at the edge, where a
 * refusal can be a field-level message, and it is the same rule on every edge:
 * PasswordPolicy. A service that validated as well would let the two drift, because
 * only one of them would be the one anybody maintains.
 *
 * A deactivated account is **not** refused. Setting its password changes nothing
 * about whether it can sign in — ActiveAccountChecker still turns it away — so the
 * entry cannot overstate what was granted, which is exactly why granting a role to
 * a deactivated account *is* refused elsewhere: there, the entry would claim access
 * that nobody has. Whoever runs the command is told the account is off.
 */
final readonly class ResetUserPassword
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private AuditTrail $audit,
    ) {
    }

    public function __invoke(User $user, string $plainPassword): void
    {
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $plainPassword));

        $this->audit->record(
            action: 'user.password_reset',
            target: [
                'user' => $user->getEmail(),
                // Whether the account can actually be used with the new password.
                // An incident review reading "password reset" on a switched-off
                // account would otherwise have to go and look.
                'account_active' => $user->isActive(),
            ],
        );

        $this->entityManager->flush();
    }
}
