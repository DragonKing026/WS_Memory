<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * One row of the invitation list.
 *
 * Holds no token and no token hash. The plain value exists only in the answer to
 * the request that issued the invitation, and the hash is of no use to a screen —
 * putting either here would mean a listing endpoint that hands out credentials.
 *
 * `invitedBy` is the inviter's display name, resolved when the row is read, and
 * null when the invitation came from the console before any account existed. A
 * name is the only form of that fact an administrator can act on; a UUID next to
 * an invitation answers "who let this person in" with "no idea".
 */
final readonly class InvitationSummary
{
    public function __construct(
        public string $id,
        public string $email,
        public ?string $invitedBy,
        public bool $grantsGlobalAdmin,
        public InvitationStatus $status,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
        public ?\DateTimeImmutable $acceptedAt,
    ) {
    }
}
