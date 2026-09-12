<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses deactivated accounts — both at sign-in and on every later request.
 *
 * A JWT stays cryptographically valid until it expires, so without this check a
 * dismissed employee would keep reading the knowledge base for as long as their
 * last token lived. Deactivation has to bite at once, which is why the check
 * runs in `checkPostAuth` too: that one fires on every authenticated request,
 * not only when signing in.
 *
 * This is the same property the permission layer already guarantees for roles
 * (a revoked role stops working immediately) — extended to the account itself.
 */
final class ActiveAccountChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        $this->ensureActive($user);
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        $this->ensureActive($user);
    }

    private function ensureActive(UserInterface $user): void
    {
        if ($user instanceof User && !$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('Konto jest nieaktywne.');
        }
    }
}
