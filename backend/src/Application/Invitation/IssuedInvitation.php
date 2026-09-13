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
        return rtrim($baseUrl, '/') . $this->acceptPath();
    }

    /**
     * The same link without a host, for a caller that already has one.
     *
     * The administration screen is served from the same origin as the API, so an
     * absolute URL there would be the configured public address rather than the one
     * the administrator is actually looking at — and when those two differ (a
     * tunnel, a staging host, a reverse proxy added later) the absolute form is the
     * broken one. A path is correct in every case, and the browser resolves it.
     */
    public function acceptPath(): string
    {
        return '/zaproszenie/' . $this->plainToken;
    }
}
