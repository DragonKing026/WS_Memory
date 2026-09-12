<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Application\Document\DocumentNotFound;
use App\Application\Document\ProposalService;
use App\Domain\Document\DocumentSlug;
use App\Domain\Identity\Actor;
use App\Domain\Memory\MemoryAccessDenied;
use App\Domain\Space\SpaceId;
use App\Entity\Proposal;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The review queue over HTTP.
 *
 * Reviewing is a human act, so there is no MCP counterpart to accept or reject —
 * an agent can submit and nothing more (D-005). That asymmetry is the point of the
 * queue: a space that wants a person to look first gets a person.
 */
final readonly class ProposalController
{
    public function __construct(
        private Security $security,
        private ProposalService $proposals,
    ) {
    }

    #[Route('/api/spaces/{space}/proposals', name: 'api_proposals_list', methods: ['GET'])]
    public function pending(string $space): JsonResponse
    {
        $proposals = $this->proposals->pending($this->actor(), new SpaceId($space));

        return new JsonResponse([
            'proposals' => array_map($this->describe(...), $proposals),
            'count' => \count($proposals),
        ]);
    }

    #[Route('/api/spaces/{space}/proposals', name: 'api_proposals_submit', methods: ['POST'])]
    public function submit(string $space, Request $request): JsonResponse
    {
        return $this->guard(function () use ($space, $request): JsonResponse {
            /** @var mixed $payload */
            $payload = json_decode($request->getContent(), true);
            if (!\is_array($payload)) {
                return new JsonResponse(['error' => 'Oczekiwano obiektu JSON.'], 400);
            }

            $title = $payload['title'] ?? null;
            $content = $payload['content'] ?? null;
            if (!\is_string($title) || !\is_string($content)) {
                return new JsonResponse(['error' => 'Pola „title" i „content" są wymagane.'], 400);
            }

            $slug = $payload['slug'] ?? null;

            $proposal = $this->proposals->propose(
                actor: $this->actor(),
                space: new SpaceId($space),
                title: $title,
                content: $content,
                slug: \is_string($slug) && '' !== trim($slug) ? new DocumentSlug($slug) : null,
            );

            return new JsonResponse($this->describe($proposal), 201);
        });
    }

    #[Route('/api/proposals/{id}/accept', name: 'api_proposals_accept', methods: ['POST'])]
    public function accept(string $id, Request $request): JsonResponse
    {
        return $this->guard(function () use ($id, $request): JsonResponse {
            /** @var mixed $payload */
            $payload = json_decode($request->getContent(), true);
            $slug = \is_array($payload) ? ($payload['slug'] ?? null) : null;
            $note = \is_array($payload) ? ($payload['note'] ?? null) : null;

            $document = $this->proposals->accept(
                reviewer: $this->user(),
                proposalId: $id,
                slug: \is_string($slug) && '' !== trim($slug) ? new DocumentSlug($slug) : null,
                note: \is_string($note) ? $note : null,
            );

            return new JsonResponse([
                'accepted' => true,
                'document' => [
                    'space' => $document->getSpace()->getSlug(),
                    'slug' => $document->getSlug(),
                    'currentRevision' => $document->getCurrentRevisionNumber(),
                    // The reviewer authored the revision, and the document still
                    // records that the text came from an agent.
                    'authoredByAi' => $document->isAuthoredByAi(),
                ],
            ]);
        });
    }

    #[Route('/api/proposals/{id}/reject', name: 'api_proposals_reject', methods: ['POST'])]
    public function reject(string $id, Request $request): JsonResponse
    {
        return $this->guard(function () use ($id, $request): JsonResponse {
            /** @var mixed $payload */
            $payload = json_decode($request->getContent(), true);
            $note = \is_array($payload) ? ($payload['note'] ?? null) : null;

            $proposal = $this->proposals->reject(
                $this->user(),
                $id,
                \is_string($note) ? $note : null,
            );

            return new JsonResponse($this->describe($proposal));
        });
    }

    /**
     * @param callable(): JsonResponse $action
     */
    private function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (DocumentNotFound $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        } catch (MemoryAccessDenied $e) {
            return new JsonResponse(['error' => $e->getMessage()], 403);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Proposal $proposal): array
    {
        return [
            'id' => $proposal->getId()->toRfc4122(),
            'space' => $proposal->getSpace()->getSlug(),
            'slug' => $proposal->getSlug(),
            'title' => $proposal->getTitle(),
            'content' => $proposal->getContent(),
            'status' => $proposal->getStatus()->value,
            'byAi' => $proposal->isByAgent(),
            'reviewedBy' => $proposal->getReviewedBy()?->getDisplayName(),
            'reviewedAt' => $proposal->getReviewedAt()?->format(\DATE_ATOM),
            'reviewNote' => $proposal->getReviewNote(),
            'resultingDocument' => $proposal->getResultingDocument()?->getSlug(),
            'createdAt' => $proposal->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    private function actor(): Actor
    {
        $user = $this->user();

        return Actor::human($user->getId()->toRfc4122(), $user->isGlobalAdmin());
    }

    private function user(): User
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new \LogicException('Endpointy kolejki wymagają uwierzytelnienia.');
        }

        return $user;
    }
}
