<?php

declare(strict_types=1);

namespace App\Presentation\Mcp;

use App\Domain\Identity\AgentIdentity;
use App\Domain\Identity\AgentTokenDirectory;
use App\Infrastructure\Security\AgentTokenAuthenticator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /mcp — the single door for AI agents.
 *
 * Everything protocol-shaped lives in McpServer; this class deals only with HTTP:
 * the body, the status code, the token's usage record and the rate limit.
 *
 * The rate limit sits here rather than around each tool, because it is per token
 * and per request — a decorator would have to be told the same thing once per
 * tool. It counts every method, `tools/list` included: an agent looping over the
 * catalogue hurts the palace just as much as one looping over searches.
 */
final readonly class McpController
{
    public function __construct(
        private McpServer $server,
        private AgentTokenDirectory $directory,
        private int $callsPerMinute,
    ) {
    }

    #[Route('/mcp', name: 'mcp_endpoint', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $identity = $request->attributes->get(AgentTokenAuthenticator::IDENTITY_ATTRIBUTE);
        if (!$identity instanceof AgentIdentity) {
            // The firewall should have answered already. Reaching this means the
            // endpoint was left unsecured by a configuration change, and failing
            // closed is the only safe reaction.
            return new JsonResponse(['error' => 'Nieuwierzytelniony.'], Response::HTTP_UNAUTHORIZED);
        }

        $calls = $this->directory->noteUsage($identity->tokenId, $request->getClientIp());

        if ($calls > $this->callsPerMinute) {
            return $this->rpcError(
                McpError::RATE_LIMITED,
                \sprintf(
                    'Przekroczony limit %d wywołań na minutę dla tego tokena. Zwolnij i spróbuj ponownie.',
                    $this->callsPerMinute,
                ),
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent(), true);

        if (!\is_array($decoded)) {
            return $this->rpcError(McpError::PARSE_ERROR, 'Ciało żądania nie jest poprawnym JSON-em.');
        }

        if (array_is_list($decoded)) {
            // Batches are legal JSON-RPC and we do not accept them. One request
            // per call keeps the rate limit and the audit trail honest — a batch
            // would count as one call while doing twenty.
            return $this->rpcError(
                McpError::INVALID_REQUEST,
                'Wsadowe żądania nie są obsługiwane. Wyślij jedno wywołanie na żądanie.',
            );
        }

        /** @var array<string, mixed> $decoded */
        $answer = $this->server->handle($decoded, $identity->actor);

        // A notification has no answer. Returning an empty body with 202 is what
        // tells the client it was accepted; a JSON body here makes clients that
        // are waiting for nothing hang.
        if (null === $answer) {
            return new JsonResponse(null, Response::HTTP_ACCEPTED, [], true);
        }

        return new JsonResponse($answer);
    }

    private function rpcError(int $code, string $message, int $status = Response::HTTP_OK): JsonResponse
    {
        // HTTP 200 with a JSON-RPC error is the protocol's own convention for
        // errors that are about the call rather than about the transport; the rate
        // limit is the exception, because 429 is what a client already knows how
        // to back off from.
        return new JsonResponse([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }
}
