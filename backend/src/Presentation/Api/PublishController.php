<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Application\Publishing\PublicationRequest;
use App\Application\Publishing\PublishService;
use App\Domain\Identity\Actor;
use App\Domain\Identity\AgentIdentity;
use App\Domain\Memory\MemoryAccessDenied;
use App\Domain\Memory\MemoryUnavailable;
use App\Domain\Publishing\IncomingDrawer;
use App\Domain\Publishing\PublishRefusal;
use App\Domain\Publishing\PublishRefused;
use App\Domain\Space\SpaceId;
use App\Entity\User;
use App\Infrastructure\Security\AgentTokenAuthenticator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The door a local palace publishes through (D-010, rule 10).
 *
 * Always over the API, never straight into the database. That is not a preference
 * about layering: `MEMPALACE_PGVECTOR_DSN` handed to a laptop would be simpler and
 * would bypass the token, the roles and the audit trail in one step — the entire
 * reason this project exists on top of MemPalace rather than beside it.
 *
 * What the body may say and what it may not is the whole contract here. It names
 * the replica, the wing and the room content came FROM; it does not name an author
 * (inviolable rule 2 — identity comes from the credential, and impersonation has to
 * be inexpressible rather than merely refused) and it does not name a target space
 * except in manual mode, where naming one is checked like any other write.
 *
 * The status codes are part of the contract too, because the caller is an outbox
 * deciding whether to keep an item or drop it: 400 means the request is wrong and
 * resending it unchanged will fail again, 403 means the space is closed, 409 means
 * somebody already did this, 503 means come back later. A client that cannot tell
 * those apart either loses drawers or retries forever.
 */
final readonly class PublishController
{
    public function __construct(
        private Security $security,
        private PublishService $publishing,
    ) {
    }

    #[Route('/api/publish', name: 'api_publish', methods: ['POST'])]
    public function publish(Request $request): JsonResponse
    {
        try {
            $payload = $this->payloadOf($request);
            $publication = new PublicationRequest(
                sourceReplica: $this->stringOf($payload, 'replica') ?? '',
                drawers: $this->drawersOf($payload),
                space: null !== ($slug = $this->stringOf($payload, 'space')) ? new SpaceId($slug) : null,
                preview: true === ($payload['preview'] ?? false),
            );

            $report = $this->publishing->publish($this->actor($request), $publication);
        } catch (PublishRefused $refused) {
            return $this->refuse($refused);
        } catch (\InvalidArgumentException $malformed) {
            // Thrown by the value objects themselves — an empty wing, a blank
            // drawer identifier. The caller's request is wrong, not its permissions.
            return new JsonResponse(['error' => $malformed->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (MemoryAccessDenied $denied) {
            // Said out loud rather than redirected. Silence here would leave an
            // outbox resending the same batch for ever, and the sender named the
            // space (or mapped the wing) so nothing is disclosed by saying so.
            return new JsonResponse(['error' => $denied->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (MemoryUnavailable $unavailable) {
            // The one answer that means "keep the batch and try later" (D-015).
            return new JsonResponse(
                ['error' => $unavailable->getMessage()],
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Retry-After' => '60'],
            );
        }

        return new JsonResponse($report->toArray(), Response::HTTP_OK);
    }

    #[Route('/api/publish/{batch}/revert', name: 'api_publish_revert', methods: ['POST'])]
    public function revert(string $batch, Request $request): JsonResponse
    {
        try {
            $report = $this->publishing->revert($this->actor($request), $batch);
        } catch (PublishRefused $refused) {
            return $this->refuse($refused);
        } catch (MemoryUnavailable $unavailable) {
            // Nothing was half-undone: the drawers are removed from the palace
            // before any row is touched, so a retry finishes what this one started.
            return new JsonResponse(
                ['error' => $unavailable->getMessage()],
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Retry-After' => '60'],
            );
        }

        return new JsonResponse($report->toArray(), Response::HTTP_OK);
    }

    private function refuse(PublishRefused $refused): JsonResponse
    {
        return new JsonResponse(
            ['error' => $refused->getMessage()],
            match ($refused->refusal) {
                PublishRefusal::Malformed => Response::HTTP_BAD_REQUEST,
                // "Not yours" answers 404 like "does not exist", because telling a
                // caller that a batch exists but belongs to somebody else is itself
                // a disclosure — the same rule the document routes follow.
                PublishRefusal::UnknownBatch => Response::HTTP_NOT_FOUND,
                PublishRefusal::AlreadyReverted => Response::HTTP_CONFLICT,
            },
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PublishRefused
     */
    private function payloadOf(Request $request): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent(), true);

        if (!\is_array($decoded)) {
            throw PublishRefused::malformed('Ciało żądania musi być obiektem JSON.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<IncomingDrawer>
     *
     * @throws PublishRefused
     */
    private function drawersOf(array $payload): array
    {
        /** @var mixed $raw */
        $raw = $payload['drawers'] ?? null;
        if (!\is_array($raw)) {
            throw PublishRefused::malformed('Brakuje listy „drawers".');
        }

        $drawers = [];
        foreach ($raw as $index => $entry) {
            if (!\is_array($entry)) {
                throw PublishRefused::malformed(\sprintf('Szuflada nr %s nie jest obiektem.', (string) $index));
            }

            /** @var array<string, mixed> $entry */
            $drawers[] = new IncomingDrawer(
                sourceDrawerId: $this->stringOf($entry, 'id') ?? '',
                sourceWing: $this->stringOf($entry, 'wing') ?? '',
                sourceRoom: $this->stringOf($entry, 'room'),
                content: \is_string($entry['content'] ?? null) ? $entry['content'] : '',
                filedAt: $this->dateOf($entry, 'filedAt'),
                title: $this->stringOf($entry, 'title'),
                tags: $this->tagsOf($entry),
                sourcePath: $this->stringOf($entry, 'sourcePath'),
            );
        }

        return $drawers;
    }

    /**
     * @param array<string, mixed> $from
     */
    private function stringOf(array $from, string $key): ?string
    {
        /** @var mixed $value */
        $value = $from[$key] ?? null;

        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    /**
     * @param array<string, mixed> $from
     *
     * @return list<string>
     */
    private function tagsOf(array $from): array
    {
        /** @var mixed $raw */
        $raw = $from['tags'] ?? null;
        if (!\is_array($raw)) {
            return [];
        }

        $tags = [];
        foreach ($raw as $tag) {
            if (\is_string($tag) && '' !== trim($tag)) {
                $tags[] = trim($tag);
            }
        }

        return $tags;
    }

    /**
     * @param array<string, mixed> $from
     *
     * @throws PublishRefused
     */
    private function dateOf(array $from, string $key): ?\DateTimeImmutable
    {
        $raw = $this->stringOf($from, $key);
        if (null === $raw) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            // Refused rather than silently replaced with now(). This timestamp is
            // what orders the browse screen and what a watermark is compared
            // against; a guessed one would put a week-old transcript at the top of
            // today's list and nobody would ever know why.
            throw PublishRefused::malformed(\sprintf('Nie umiem odczytać znacznika czasu „%s".', $raw));
        }
    }

    /**
     * Who is publishing — from the credential, never from the body.
     *
     * Both credentials are accepted here and the difference is recorded rather
     * than flattened: an agent token narrows its owner's permissions and its
     * writes are marked as written by AI, so treating one as its owner would
     * both widen it and mislabel everything it sends.
     */
    private function actor(Request $request): Actor
    {
        /** @var mixed $identity */
        $identity = $request->attributes->get(AgentTokenAuthenticator::IDENTITY_ATTRIBUTE);
        if ($identity instanceof AgentIdentity) {
            return $identity->actor;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Trasa poza firewallem — kontroler nie powinien tu trafić.');
        }

        return Actor::human($user->getId()->toRfc4122(), $user->isGlobalAdmin());
    }
}
