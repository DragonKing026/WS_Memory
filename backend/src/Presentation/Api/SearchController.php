<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Application\Search\SearchService;
use App\Domain\Identity\Actor;
use App\Domain\Memory\MemoryKind;
use App\Domain\Memory\MemoryQuery;
use App\Domain\Memory\SearchMode;
use App\Domain\Search\SearchHit;
use App\Domain\Search\SnippetPart;
use App\Domain\Space\SpaceId;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Search, for people.
 *
 * The agents' door is `/mcp` and it answers from the same service (D-008), so
 * neither surface can see anything the other cannot. What differs is what a
 * person needs on top of the content: who wrote it, whether anybody verified it,
 * and how good the match actually is.
 *
 * Two things this endpoint does that a plain result list would not:
 *
 *   - **weak matches travel separately.** Semantic search always answers, only
 *     progressively worse, so a flat list makes the twentieth result look like an
 *     answer. Splitting them here rather than in the browser means every client
 *     gets the distinction, including the next one;
 *   - **it states its own coverage.** Lexical mode cannot see the body of
 *     anything but documents (D-029). A search screen that hides that is worse
 *     than one that never offered the mode, so the limitation ships with the
 *     results.
 */
final readonly class SearchController
{
    public function __construct(
        private Security $security,
        private SearchService $search,
    ) {
    }

    #[Route('/api/search', name: 'api_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $mode = SearchMode::tryFrom((string) $request->query->get('mode', SearchMode::Semantic->value));
        if (null === $mode) {
            return $this->badRequest('Nieznany tryb wyszukiwania. Dozwolone: „semantic" albo „lexical".');
        }

        $kindParam = trim((string) $request->query->get('kind', ''));
        $kind = '' === $kindParam ? null : MemoryKind::tryFrom($kindParam);
        if ('' !== $kindParam && null === $kind) {
            return $this->badRequest('Nieznana klasa wiedzy.');
        }

        try {
            $query = new MemoryQuery(
                text: (string) $request->query->get('q', ''),
                limit: $this->limitFrom($request),
                kind: $kind,
                since: $this->dateFrom($request, 'since'),
                before: $this->dateFrom($request, 'before'),
            );
        } catch (\InvalidArgumentException $problem) {
            // MemoryQuery's messages are written to be read by a person — an
            // empty query and a 300-character one need different answers, and
            // "nieprawidłowe zapytanie" would be neither.
            return $this->badRequest($problem->getMessage());
        }

        $hits = $this->search->search($this->actor(), $query, $mode, $this->spacesFrom($request));

        $strong = [];
        $weak = [];
        foreach ($hits as $hit) {
            if ($hit->weak) {
                $weak[] = self::present($hit);
            } else {
                $strong[] = self::present($hit);
            }
        }

        return new JsonResponse([
            // Echoed back so a client can label an empty result with what was
            // actually searched for, rather than with whatever is in its input
            // box by the time the answer arrives.
            'query' => $query->text,
            'mode' => $mode->value,
            'results' => $strong,
            'weakResults' => $weak,
            'count' => \count($strong) + \count($weak),
            'coverage' => self::coverageOf($mode),
        ]);
    }

    /**
     * What this mode could and could not look inside.
     *
     * Sent with every answer rather than written into the frontend, because the
     * limitation belongs to the backend's data and would otherwise go stale in a
     * translation file the day the palace grows a lexical mode.
     *
     * @return array<string, mixed>
     */
    private static function coverageOf(SearchMode $mode): array
    {
        return match ($mode) {
            SearchMode::Semantic => [
                'fullText' => true,
                'note' => 'Szuka znaczeniem w całej treści — znajduje też to, co opisano innymi słowami.',
            ],
            SearchMode::Lexical => [
                'fullText' => false,
                'note' => 'Szuka dokładnych słów. Pełną treść przeszukuje tylko w dokumentach; '
                    . 'notatki, dziennik i transkrypty dopasowuje po tytule i tagach.',
            ],
        };
    }

    /** @return array<string, mixed> */
    private static function present(SearchHit $hit): array
    {
        return [
            'space' => $hit->space->value,
            'kind' => $hit->kind->value,
            'title' => $hit->title,
            // Structure, not markup: the snippet comes from content people and
            // agents wrote, and a string of HTML from the database is how a wiki
            // ends up executing somebody's script tag.
            'snippet' => array_map(
                static fn (SnippetPart $part): array => ['text' => $part->text, 'match' => $part->match],
                $hit->snippet->parts,
            ),
            'score' => $hit->score,
            'weak' => $hit->weak,
            'byAi' => $hit->byAi,
            'verified' => $hit->verified,
            'drawer' => $hit->drawer?->value,
            'documentSlug' => $hit->documentSlug,
            'at' => $hit->at?->format(\DATE_ATOM),
        ];
    }

    /** @return list<SpaceId>|null */
    private function spacesFrom(Request $request): ?array
    {
        $raw = $request->query->all('spaces');

        $slugs = [];
        foreach ($raw as $value) {
            if (\is_string($value) && '' !== trim($value)) {
                $slugs[] = new SpaceId(trim($value));
            }
        }

        // null and [] mean different things: null is "wherever I may look", an
        // empty list would be "nowhere". A client sending only blanks meant the
        // former.
        return [] === $slugs ? null : $slugs;
    }

    private function limitFrom(Request $request): int
    {
        $raw = $request->query->get('limit');

        if (null === $raw || '' === $raw) {
            return self::DEFAULT_LIMIT;
        }

        // Left to MemoryQuery to reject: it owns the bounds, and repeating them
        // here is how the API and the domain end up disagreeing about the maximum.
        return (int) $raw;
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
            // An unparseable date is dropped rather than fatal: the alternative
            // is a search screen that refuses to work because of a half-typed
            // date in a filter the user is still filling in.
            return null;
        }
    }

    /** A page of results, not a wall of them; the client asks for more if it wants more. */
    private const DEFAULT_LIMIT = 20;

    private function badRequest(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_BAD_REQUEST);
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
