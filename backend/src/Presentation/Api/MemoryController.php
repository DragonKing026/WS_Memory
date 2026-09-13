<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Application\Memory\MemoryService;
use App\Domain\Identity\Actor;
use App\Domain\Memory\MemoryEntryView;
use App\Domain\Memory\MemoryKind;
use App\Domain\Space\SpaceId;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Raw memory, browsable.
 *
 * A separate screen from the wiki because it answers a different question. The wiki is
 * what the company has decided to write down; this is everything that has been filed —
 * notes an agent thought worth keeping, diary entries, transcripts. Mixed into one
 * list, the second would bury the first, and a reader would stop being able to tell
 * "we decided this" from "an agent noticed this".
 *
 * Paged from the start. The registry is the table that grows fastest in the whole
 * system, and an unpaged listing over it is an endpoint with an expiry date — the
 * document listing proved that at ten thousand rows.
 */
final readonly class MemoryController
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    public function __construct(
        private Security $security,
        private MemoryService $memory,
    ) {
    }

    #[Route('/api/memory', name: 'api_memory_browse', methods: ['GET'])]
    public function browse(Request $request): JsonResponse
    {
        $kindParam = trim((string) $request->query->get('kind', ''));
        $kind = '' === $kindParam ? null : MemoryKind::tryFrom($kindParam);
        if ('' !== $kindParam && null === $kind) {
            return new JsonResponse(['error' => 'Nieznana klasa wiedzy.'], Response::HTTP_BAD_REQUEST);
        }

        $limit = max(1, min($request->query->getInt('limit', self::DEFAULT_LIMIT), self::MAX_LIMIT));
        $offset = max(0, $request->query->getInt('offset'));

        $entries = $this->memory->browse(
            $this->actor(),
            $this->spacesFrom($request),
            $kind,
            $this->dateFrom($request, 'since'),
            $this->dateFrom($request, 'before'),
            $limit,
            $offset,
        );

        return new JsonResponse([
            'entries' => array_map(self::present(...), $entries),
            'count' => \count($entries),
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => \count($entries) === $limit,
        ]);
    }

    /** @return array<string, mixed> */
    private static function present(MemoryEntryView $entry): array
    {
        return [
            'drawer' => $entry->drawer->value,
            'space' => $entry->space->value,
            'kind' => $entry->kind->value,
            'title' => $entry->title,
            'tags' => $entry->tags,
            'byAi' => $entry->byAi,
            'verified' => $entry->verified,
            'documentSlug' => $entry->documentSlug,
            'filedAt' => $entry->filedAt->format(\DATE_ATOM),
        ];
    }

    /** @return list<SpaceId>|null */
    private function spacesFrom(Request $request): ?array
    {
        $slugs = [];
        foreach ($request->query->all('spaces') as $value) {
            if (\is_string($value) && '' !== trim($value)) {
                $slugs[] = new SpaceId(trim($value));
            }
        }

        // null and [] differ: null is "wherever I may look", an empty list would be
        // "nowhere". A client sending only blanks meant the former.
        return [] === $slugs ? null : $slugs;
    }

    private function dateFrom(Request $request, string $name): ?\DateTimeImmutable
    {
        $raw = trim((string) $request->query->get($name, ''));
        if ('' === $raw) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            // Dropped rather than fatal: a half-typed date in a filter the user is
            // still filling in must not break the screen.
            return null;
        }
    }

    private function actor(): Actor
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Trasa poza firewallem — kontroler nie powinien tu trafić.');
        }

        return Actor::human($user->getId()->toRfc4122(), $user->isGlobalAdmin());
    }
}
