<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Application\AgentToken\IssueAgentToken;
use App\Application\AgentToken\RevokeAgentToken;
use App\Entity\AgentToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Managing one's own agent tokens.
 *
 * Everything here is scoped to the caller, global administrators included. A token
 * is somebody's working credential, and an administrator who can list or retire
 * another person's agents can stop their work without a trace; the visible way to
 * do that is to deactivate the account, which is recorded (D-016).
 *
 * The plain token appears in exactly one response — the one that created it. The
 * listing shows what a token is and when it was last used, never what it is.
 */
final readonly class AgentTokenController
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private IssueAgentToken $issue,
        private RevokeAgentToken $revoke,
        private string $publicBaseUrl,
    ) {
    }

    #[Route('/api/agent-tokens', name: 'api_agent_tokens_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $owner = $this->owner();

        $tokens = $this->entityManager->getRepository(AgentToken::class)
            ->findBy(['owner' => $owner], ['createdAt' => 'DESC']);

        return new JsonResponse([
            'tokens' => array_map(static fn (AgentToken $token): array => [
                'id' => $token->getId()->toRfc4122(),
                'label' => $token->getLabel(),
                'spaceScope' => $token->getSpaceScope(),
                'expiresAt' => $token->getExpiresAt()?->format(\DATE_ATOM),
                'revokedAt' => $token->getRevokedAt()?->format(\DATE_ATOM),
                // The one field that makes a dead token identifiable. Without it
                // nobody dares retire anything and the list only ever grows.
                'lastUsedAt' => $token->getLastUsedAt()?->format(\DATE_ATOM),
                'lastUsedIp' => $token->getLastUsedIp(),
                'createdAt' => $token->getCreatedAt()->format(\DATE_ATOM),
                'usable' => $token->isUsable(),
            ], $tokens),
        ]);
    }

    #[Route('/api/agent-tokens', name: 'api_agent_tokens_issue', methods: ['POST'])]
    public function issue(Request $request): JsonResponse
    {
        $owner = $this->owner();

        /** @var mixed $payload */
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'Oczekiwano obiektu JSON.'], 400);
        }

        $label = $payload['label'] ?? null;
        if (!\is_string($label) || '' === trim($label)) {
            return new JsonResponse(['error' => 'Pole „label" jest wymagane.'], 400);
        }

        $scope = null;
        if (isset($payload['spaceScope'])) {
            if (!\is_array($payload['spaceScope']) || !array_is_list($payload['spaceScope'])) {
                return new JsonResponse(['error' => 'Pole „spaceScope" musi być listą identyfikatorów przestrzeni.'], 400);
            }

            $scope = [];
            foreach ($payload['spaceScope'] as $slug) {
                if (!\is_string($slug) || '' === trim($slug)) {
                    return new JsonResponse(['error' => 'Lista „spaceScope" zawiera element, który nie jest tekstem.'], 400);
                }

                $scope[] = trim($slug);
            }
        }

        $expiresAt = null;
        if (isset($payload['expiresAt'])) {
            if (!\is_string($payload['expiresAt'])) {
                return new JsonResponse(['error' => 'Pole „expiresAt" musi być datą w tekście.'], 400);
            }

            try {
                $expiresAt = new \DateTimeImmutable($payload['expiresAt']);
            } catch (\Exception) {
                return new JsonResponse(['error' => 'Pole „expiresAt" nie jest poprawną datą.'], 400);
            }
        }

        try {
            $issued = ($this->issue)($owner, $label, $scope, $expiresAt);
        } catch (\DomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse([
            'id' => $issued->tokenId,
            'label' => $issued->label,
            'spaceScope' => $issued->spaceScope,
            'expiresAt' => $issued->expiresAt?->format(\DATE_ATOM),
            // Shown once. Whoever receives this response is responsible for it;
            // there is no endpoint that will ever return it again.
            'token' => $issued->plainToken,
            'setupCommand' => $issued->claudeCodeCommand($this->publicBaseUrl),
        ], 201);
    }

    #[Route('/api/agent-tokens/{id}', name: 'api_agent_tokens_revoke', methods: ['DELETE'])]
    public function revokeToken(string $id): JsonResponse
    {
        $owner = $this->owner();

        // 404 for somebody else's token as well as for one that does not exist.
        // A different answer would let anyone enumerate other people's credentials.
        return ($this->revoke)($owner, $id)
            ? new JsonResponse(['revoked' => true])
            : new JsonResponse(['error' => 'Nie ma takiego tokena.'], 404);
    }

    private function owner(): User
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            // The firewall answers first in practice; this keeps the type honest.
            throw new \LogicException('Endpoint tokenów wymaga uwierzytelnienia.');
        }

        return $user;
    }
}
