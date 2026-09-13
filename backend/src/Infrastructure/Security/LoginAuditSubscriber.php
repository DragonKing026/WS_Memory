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
 *
 * **Only the `login` firewall counts as signing in**, and that guard is the whole
 * point of this class working at all. Every firewall here is stateless, so the `api`
 * one re-authenticates the bearer token on *every single request* and dispatches
 * `LoginSuccessEvent` each time; `mcp` does the same for agent tokens. Without the
 * guard, one person clicking around the application wrote a "user.login" row per HTTP
 * request.
 *
 * It was not a small effect: the audit screen, on the day it was first opened, showed
 * 40 889 entries of which **20 335 were these** — half the log, describing sign-ins
 * that never happened. An audit trail whose majority is fiction is worse than a short
 * one, because the real entries are in there somewhere and nobody will find them. It
 * also meant `last_login_at` said "just now" for anyone with a valid token, and that
 * every read request performed a write.
 */
final readonly class LoginAuditSubscriber
{
    /** The firewall in `security.yaml` that exchanges credentials for a token. */
    private const SIGN_IN_FIREWALL = 'login';

    public function __construct(
        private AuditTrail $audit,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onSuccess(LoginSuccessEvent $event): void
    {
        // See the class comment: on a stateless firewall this fires per request, not
        // per sign-in. Only the firewall that actually checks an e-mail and a password
        // is a sign-in; the rest are a token being presented again.
        if (self::SIGN_IN_FIREWALL !== $event->getFirewallName()) {
            return;
        }

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
