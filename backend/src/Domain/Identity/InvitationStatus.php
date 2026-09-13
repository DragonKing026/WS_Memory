<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Where an invitation stands — derived, never stored.
 *
 * There is no `status` column and there must not be one: it would be a copy of
 * `accepted_at` and `expires_at` that goes stale by itself. An invitation becomes
 * expired because time passed, with nobody around to run an UPDATE, so a stored
 * status would be wrong for every invitation that nobody looked at.
 *
 * The values are Polish because they are read verbatim by the interface; the case
 * names are English like the rest of the code.
 */
enum InvitationStatus: string
{
    case Pending = 'oczekuje';
    case Accepted = 'przyjete';
    case Expired = 'wygasle';

    /**
     * Acceptance wins over expiry, deliberately.
     *
     * An invitation accepted on its last day is still an accepted invitation a
     * week later, and showing it as expired would suggest to an administrator
     * that the account was never created.
     */
    public static function of(
        ?\DateTimeImmutable $acceptedAt,
        \DateTimeImmutable $expiresAt,
        ?\DateTimeImmutable $now = null,
    ): self {
        if (null !== $acceptedAt) {
            return self::Accepted;
        }

        return ($now ?? new \DateTimeImmutable()) > $expiresAt ? self::Expired : self::Pending;
    }
}
