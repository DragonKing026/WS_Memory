<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Audit\AuditTrail;
use App\Domain\Identity\Actor;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Records sign-ins and failed attempts.
 *
 * Hung off security events rather than written into the controller, because
 * the sign-in controller is never executed — the firewall answers first. A
 * trail that misses the most common event in the system would be worse than
 * none, because it would look complete.
 */
final readonly class LoginAuditSubscriber
{
    public function __construct(
        private AuditTrail $audit,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $user->recordLogin();

        $this->audit->record(
            action: 'user.login',
            actor: Actor::human($user->getId()->toRfc4122(), $user->isGlobalAdmin()),
            target: ['email' => $user->getEmail()],
        );

        $this->entityManager->flush();
    }

    #[AsEventListener(event: LoginFailureEvent::class)]
    public function onFailure(LoginFailureEvent $event): void
    {
        // No actor: at this point we have a claimed identity, not a confirmed
        // one. Recording it as the actor would let anyone forge audit entries
        // by typing somebody else's address into the login form.
        $this->audit->record(
            action: 'user.login_failed',
            target: ['claimed_email' => $event->getRequest()->getPayload()->get('email')],
        );

        $this->entityManager->flush();
    }
}
