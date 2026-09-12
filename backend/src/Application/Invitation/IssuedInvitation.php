<?php

declare(strict_types=1);

namespace App\Application\Invitation;

/**
 * The result of issuing an invitation.
 *
 * Carries the plain token, which exists in this object and nowhere else — the
 * database holds only its hash. Whoever receives this is responsible for
 * delivering it; there is no second chance to read it.
 */
final readonly class IssuedInvitation
{
    public function __construct(
        public string $invitationId,
        public string $email,
        public string $plainToken,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    public function acceptUrl(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . '/zaproszenie/' . $this->plainToken;
    }
}
