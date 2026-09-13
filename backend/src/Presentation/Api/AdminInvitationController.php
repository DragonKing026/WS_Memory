<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Application\Invitation\IssueInvitation;
use App\Application\Invitation\RevokeInvitation;
use App\Domain\Identity\AdministrationRefused;
use App\Domain\Identity\InvitationRoster;
use App\Domain\Identity\InvitationSummary;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Invitations: the only route to a new account, over HTTP.
 *
 * Global administrators only. Issuing an invitation widens the set of people who
 * can read this company's knowledge base — with `admin: true`, the set of people
 * who can widen it further — so every call here is audited with the identity of
 * whoever made it (D-016).
 *
 * The console command `ws:user:invite` does the same thing for the first account of
 * an installation, before anybody can sign in. Both go through IssueInvitation, and
 * the refusals live there: an address that is already an account, an invitation
 * still outstanding for it, an address that is not one. Duplicating any of those
 * checks here would give two entry points two different ideas about what may be
 * invited.
 */
final readonly class AdminInvitationController
{
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    private const FORBIDDEN_BODY = ['error' => 'Zarządzanie zaproszeniami wymaga uprawnień administratora.'];

    public function __construct(
        private Security $security,
        private InvitationRoster $roster,
        private IssueInvitation $issue,
        private RevokeInvitation $revoke,
    ) {
    }

    #[Route('/api/admin/invitations', name: 'api_admin_invitations', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if (!$this->currentUser()->isGlobalAdmin()) {
            return new JsonResponse(self::FORBIDDEN_BODY, Response::HTTP_FORBIDDEN);
        }

        $limit = max(1, min($request->query->getInt('limit', self::DEFAULT_LIMIT), self::MAX_LIMIT));
        $offset = max(0, $request->query->getInt('offset'));

        $invitations = $this->roster->page($limit, $offset);

        return new JsonResponse([
            'invitations' => array_map(self::present(...), $invitations),
            // The size of this page, like every other listing here.
            'count' => \count($invitations),
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => \count($invitations) === $limit,
        ]);
    }

    #[Route('/api/admin/invitations', name: 'api_admin_invitations_issue', methods: ['POST'])]
    public function issueInvitation(Request $request): JsonResponse
    {
        $actor = $this->currentUser();
        if (!$actor->isGlobalAdmin()) {
            return new JsonResponse(self::FORBIDDEN_BODY, Response::HTTP_FORBIDDEN);
        }

        $payload = $request->getPayload()->all();

        /** @var mixed $email */
        $email = $payload['email'] ?? null;
        if (!\is_string($email)) {
            return new JsonResponse(
                ['error' => 'Pole „email” jest wymagane i musi być tekstem.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        /** @var mixed $admin */
        $admin = $payload['admin'] ?? false;
        if (!\is_bool($admin)) {
            // Not coerced. `"admin": "false"` coerces to true in PHP, and the
            // mistake would hand out the role that hands out roles.
            return new JsonResponse(
                ['error' => 'Pole „admin” musi być wartością true albo false.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $issued = ($this->issue)($email, $admin, $actor);
        } catch (AdministrationRefused $refused) {
            return new JsonResponse(
                ['error' => $refused->getMessage()],
                AdministrationRefusalStatus::of($refused->reason),
            );
        }

        $summary = $this->roster->one($issued->invitationId);
        if (null === $summary) {
            throw new \LogicException('Wystawione zaproszenie zniknęło między zapisem a odczytem.');
        }

        return new JsonResponse([
            'invitation' => self::present($summary),
            // The plain token appears HERE AND NOWHERE ELSE. The database holds only
            // its sha256, exactly as with agent tokens, so there is no endpoint and
            // no query that can produce this link a second time.
            //
            // It must therefore never be logged, never be put into an audit entry,
            // and never be written into any other row — not "for convenience" when
            // somebody asks for a resend, because a token recoverable from our own
            // storage is a token a leaked backup hands over. A resend re-issues.
            'link' => $issued->acceptPath(),
        ], Response::HTTP_CREATED);
    }

    #[Route(
        '/api/admin/invitations/{id}',
        name: 'api_admin_invitations_revoke',
        methods: ['DELETE'],
    )]
    public function revokeInvitation(string $id): JsonResponse
    {
        $actor = $this->currentUser();
        if (!$actor->isGlobalAdmin()) {
            return new JsonResponse(self::FORBIDDEN_BODY, Response::HTTP_FORBIDDEN);
        }

        try {
            ($this->revoke)($actor, $id);
        } catch (AdministrationRefused $refused) {
            return new JsonResponse(
                ['error' => $refused->getMessage()],
                AdministrationRefusalStatus::of($refused->reason),
            );
        }

        // 204: the invitation is gone, and there is nothing left to describe.
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(InvitationSummary $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'invitedBy' => $invitation->invitedBy,
            'grantsGlobalAdmin' => $invitation->grantsGlobalAdmin,
            // Derived on every read from acceptedAt and expiresAt, never stored:
            // an invitation expires because time passed, with nobody around to run
            // an UPDATE (see InvitationStatus).
            'status' => $invitation->status->value,
            'createdAt' => $invitation->createdAt->format(\DATE_ATOM),
            'expiresAt' => $invitation->expiresAt->format(\DATE_ATOM),
            'acceptedAt' => $invitation->acceptedAt?->format(\DATE_ATOM),
        ];
    }

    private function currentUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Trasa poza firewallem — kontroler nie powinien tu trafić.');
        }

        return $user;
    }
}
