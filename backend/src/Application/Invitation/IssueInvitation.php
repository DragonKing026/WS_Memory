<?php

declare(strict_types=1);

namespace App\Application\Invitation;

use App\Domain\Audit\AuditTrail;
use App\Domain\Identity\Actor;
use App\Entity\Invitation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Issues an invitation — the only route to a new account.
 *
 * The token is random and stored hashed. The plain value is returned once and
 * never persisted, so a leaked database yields no usable invitations.
 */
final readonly class IssueInvitation
{
    private const VALID_FOR = '+7 days';
    private const TOKEN_BYTES = 32;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditTrail $audit,
    ) {
    }

    public function __invoke(
        string $email,
        bool $grantsGlobalAdmin = false,
        ?User $invitedBy = null,
    ): IssuedInvitation {
        $email = strtolower(trim($email));

        $existing = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => $email]);
        if (null !== $existing) {
            throw new \DomainException("Konto {$email} już istnieje.");
        }

        $plainToken = bin2hex(random_bytes(self::TOKEN_BYTES));
        $expiresAt = new \DateTimeImmutable(self::VALID_FOR);

        $invitation = new Invitation(
            email: $email,
            tokenHash: hash('sha256', $plainToken),
            expiresAt: $expiresAt,
            invitedBy: $invitedBy,
            grantsGlobalAdmin: $grantsGlobalAdmin,
        );

        $this->entityManager->persist($invitation);

        $this->audit->record(
            action: 'invitation.issued',
            actor: $invitedBy ? Actor::human($invitedBy->getId()->toRfc4122()) : null,
            target: ['email' => $email, 'grants_global_admin' => $grantsGlobalAdmin],
        );

        $this->entityManager->flush();

        return new IssuedInvitation(
            invitationId: $invitation->getId()->toRfc4122(),
            email: $email,
            plainToken: $plainToken,
            expiresAt: $expiresAt,
        );
    }
}
