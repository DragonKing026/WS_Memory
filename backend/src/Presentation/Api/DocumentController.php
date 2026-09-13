<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Application\Document\DocumentNotFound;
use App\Application\Document\DocumentService;
use App\Domain\Identity\AuthorDirectory;
use App\Application\Document\ProposalRequired;
use App\Domain\Document\DocumentSlug;
use App\Domain\Identity\Actor;
use App\Domain\Memory\MemoryAccessDenied;
use App\Domain\Space\SpaceId;
use App\Entity\Document;
use App\Entity\DocumentRevision;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The wiki over HTTP, for the frontend.
 *
 * Every route answers `404` for a document in a space the caller cannot read — the
 * same answer as for one that does not exist. That is the same rule the space
 * endpoints follow, and for the same reason: "403 on /api/spaces/hr/documents/pensje"
 * tells the reader that a document about salaries exists (inviolable rule 7).
 *
 * Slugs may contain a slash, so the routes use `{slug<.+>}`: without it Symfony would
 * stop at the first segment and "umowy/najem" would be unreachable.
 */
final readonly class DocumentController
{
    public function __construct(
        private Security $security,
        private DocumentService $documents,
        private AuthorDirectory $authors,
    ) {
    }

    #[Route('/api/spaces/{space}/documents', name: 'api_documents_list', methods: ['GET'])]
    public function list(string $space, Request $request): JsonResponse
    {
        $limit = $request->query->getInt('limit', DocumentService::PAGE_SIZE);
        $limit = max(1, min($limit, DocumentService::MAX_PAGE_SIZE));
        $offset = max(0, $request->query->getInt('offset'));

        $documents = $this->documents->list(
            $this->actor(),
            new SpaceId($space),
            $request->query->getBoolean('archived'),
            $limit,
            $offset,
        );

        return new JsonResponse([
            'documents' => array_map($this->summarise(...), $documents),
            // The size of THIS page, not of the space. Named `count` for the callers
            // that already read it; `hasMore` is what tells a client to ask again,
            // and it costs no extra query.
            'count' => \count($documents),
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => \count($documents) === $limit,
        ]);
    }

    #[Route('/api/spaces/{space}/documents/{slug<.+>}/history', name: 'api_documents_history', methods: ['GET'])]
    public function history(string $space, string $slug): JsonResponse
    {
        return $this->guard(function () use ($space, $slug): JsonResponse {
            $document = $this->documents->require($this->actor(), new SpaceId($space), new DocumentSlug($slug));

            // Names resolved in bulk, once for the whole list: a revision stores raw
            // identifiers (deliberately — a copied name goes stale), and a column of
            // UUIDs answers "who wrote this" with "no idea".
            $userIds = [];
            $tokenIds = [];
            foreach ($document->getRevisions() as $revision) {
                if (null !== $id = $revision->getAuthorUserId()?->toRfc4122()) {
                    $userIds[] = $id;
                }
                if (null !== $id = $revision->getAuthorAgentTokenId()?->toRfc4122()) {
                    $tokenIds[] = $id;
                }
            }

            $names = $this->authors->namesOf($userIds);
            $labels = $this->authors->tokenLabelsOf($tokenIds);

            $revisions = [];
            foreach ($document->getRevisions() as $revision) {
                $userId = $revision->getAuthorUserId()?->toRfc4122();
                $tokenId = $revision->getAuthorAgentTokenId()?->toRfc4122();

                $revisions[] = $this->describeRevision($revision) + [
                    // An account that no longer exists leaves the revision in place
                    // with nobody named — history that loses entries when somebody
                    // leaves is worse than history naming nobody.
                    'authorName' => match (true) {
                        null !== $tokenId => $labels[$tokenId] ?? 'agent (token usunięty)',
                        null !== $userId => $names[$userId] ?? 'konto usunięte',
                        default => 'nieznany',
                    },
                ];
            }

            return new JsonResponse([
                'slug' => $document->getSlug(),
                'currentRevision' => $document->getCurrentRevisionNumber(),
                'revisions' => $revisions,
            ]);
        });
    }

    #[Route('/api/spaces/{space}/documents/{slug<.+>}/diff', name: 'api_documents_diff', methods: ['GET'])]
    public function diff(string $space, string $slug, Request $request): JsonResponse
    {
        return $this->guard(function () use ($space, $slug, $request): JsonResponse {
            $from = $request->query->getInt('from');
            $to = $request->query->getInt('to');

            if ($from < 1 || $to < 1) {
                return new JsonResponse(['error' => 'Podaj numery rewizji: ?from=1&to=3.'], 400);
            }

            $diff = $this->documents->diff(
                $this->actor(),
                new SpaceId($space),
                new DocumentSlug($slug),
                $from,
                $to,
            );

            return new JsonResponse([
                'from' => $from,
                'to' => $to,
                'identical' => $diff->isIdentical(),
                'added' => $diff->added,
                'removed' => $diff->removed,
                'lines' => $diff->lines,
            ]);
        });
    }

    #[Route('/api/spaces/{space}/documents/{slug<.+>}', name: 'api_documents_read', methods: ['GET'])]
    public function read(string $space, string $slug, Request $request): JsonResponse
    {
        return $this->guard(function () use ($space, $slug, $request): JsonResponse {
            $document = $this->documents->require($this->actor(), new SpaceId($space), new DocumentSlug($slug));

            $number = $request->query->getInt('revision');
            $revision = 0 === $number ? $document->getCurrentRevision() : $document->revision($number);

            if (null === $revision) {
                throw DocumentNotFound::create();
            }

            return new JsonResponse($this->summarise($document) + [
                'revision' => $this->describeRevision($revision),
                'content' => $revision->getContent(),
            ]);
        });
    }

    #[Route('/api/spaces/{space}/documents/{slug<.+>}', name: 'api_documents_write', methods: ['PUT'])]
    public function write(string $space, string $slug, Request $request): JsonResponse
    {
        return $this->guard(function () use ($space, $slug, $request): JsonResponse {
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

            $changeNote = $payload['changeNote'] ?? null;

            $revision = $this->documents->write(
                actor: $this->actor(),
                space: new SpaceId($space),
                slug: new DocumentSlug($slug),
                title: $title,
                content: $content,
                changeNote: \is_string($changeNote) ? $changeNote : null,
            );

            return new JsonResponse(
                $this->summarise($revision->getDocument()) + ['revision' => $this->describeRevision($revision)],
                1 === $revision->getNumber() ? 201 : 200,
            );
        });
    }

    #[Route('/api/spaces/{space}/documents/{slug<.+>}/rollback', name: 'api_documents_rollback', methods: ['POST'])]
    public function rollback(string $space, string $slug, Request $request): JsonResponse
    {
        return $this->guard(function () use ($space, $slug, $request): JsonResponse {
            /** @var mixed $payload */
            $payload = json_decode($request->getContent(), true);
            $to = \is_array($payload) ? ($payload['toRevision'] ?? null) : null;

            if (!\is_int($to) || $to < 1) {
                return new JsonResponse(['error' => 'Pole „toRevision" musi być numerem rewizji.'], 400);
            }

            $revision = $this->documents->rollback(
                $this->actor(),
                new SpaceId($space),
                new DocumentSlug($slug),
                $to,
            );

            // The new revision, not the restored one: a rollback moves history
            // forward, and the response has to say so or the caller will think it
            // went back.
            return new JsonResponse(
                $this->summarise($revision->getDocument()) + ['revision' => $this->describeRevision($revision)],
            );
        });
    }

    #[Route('/api/spaces/{space}/documents/{slug<.+>}/verify', name: 'api_documents_verify', methods: ['POST'])]
    public function verify(string $space, string $slug): JsonResponse
    {
        return $this->guard(function () use ($space, $slug): JsonResponse {
            $document = $this->documents->verify($this->user(), new SpaceId($space), new DocumentSlug($slug));

            return new JsonResponse($this->summarise($document));
        });
    }

    #[Route('/api/spaces/{space}/documents/{slug<.+>}/archive', name: 'api_documents_archive', methods: ['POST'])]
    public function archive(string $space, string $slug): JsonResponse
    {
        return $this->guard(function () use ($space, $slug): JsonResponse {
            $document = $this->documents->archive($this->actor(), new SpaceId($space), new DocumentSlug($slug));

            return new JsonResponse($this->summarise($document));
        });
    }

    /**
     * Turns the domain's refusals into HTTP, in one place.
     *
     * Written once rather than per route, because the mapping is where a mistake
     * becomes a disclosure: a "not found" answered as 403 anywhere would undo the
     * rule the whole file follows.
     *
     * @param callable(): JsonResponse $action
     */
    private function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (DocumentNotFound $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        } catch (ProposalRequired $e) {
            // 409: the request is valid and the route exists, but this space wants it
            // to go through the queue.
            return new JsonResponse(['error' => $e->getMessage()], 409);
        } catch (MemoryAccessDenied $e) {
            return new JsonResponse(['error' => $e->getMessage()], 403);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Document $document): array
    {
        return [
            'slug' => $document->getSlug(),
            'title' => $document->getTitle(),
            'space' => $document->getSpace()->getSlug(),
            'status' => $document->getStatus()->value,
            'currentRevision' => $document->getCurrentRevisionNumber(),
            // Both flags, because they answer different questions: who wrote it, and
            // whether anybody has vouched for it.
            'authoredByAi' => $document->isAuthoredByAi(),
            'verified' => $document->isVerified(),
            'verifiedBy' => $document->getVerifiedBy()?->getDisplayName(),
            'verifiedAt' => $document->getVerifiedAt()?->format(\DATE_ATOM),
            'archived' => $document->isArchived(),
            'updatedAt' => $document->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeRevision(DocumentRevision $revision): array
    {
        return [
            'number' => $revision->getNumber(),
            'title' => $revision->getTitleAtRevision(),
            'byAi' => $revision->isByAgent(),
            'authorUserId' => $revision->getAuthorUserId()?->toRfc4122(),
            'authorAgentTokenId' => $revision->getAuthorAgentTokenId()?->toRfc4122(),
            'changeNote' => $revision->getChangeNote(),
            'createdAt' => $revision->getCreatedAt()->format(\DATE_ATOM),
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
            throw new \LogicException('Endpointy wiki wymagają uwierzytelnienia.');
        }

        return $user;
    }
}
