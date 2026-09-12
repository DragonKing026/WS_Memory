<?php

declare(strict_types=1);

namespace App\Presentation\Mcp\Tool;

use App\Application\Document\DocumentService;
use App\Domain\Identity\Actor;
use App\Domain\Space\SpaceAccessResolver;
use App\Domain\Space\SpaceId;
use App\Entity\Document;
use App\Presentation\Mcp\McpTool;
use App\Presentation\Mcp\ToolArguments;

/**
 * ws_doc_list — what canonical documentation exists.
 *
 * Separate from ws_search because the questions differ: search answers "what do we
 * know about X", this answers "what documents are there". An agent asked to update
 * the deployment notes needs the second, and searching for them returns fragments of
 * whatever mentions deployment.
 *
 * Every listed document carries `verified` and `authored_by_ai`. That pair is the
 * whole trust model in two fields, and an agent that ignores it will cite an
 * unverified page written by another agent as though a person had checked it.
 */
final readonly class DocListTool implements McpTool
{
    public function __construct(
        private DocumentService $documents,
        private SpaceAccessResolver $access,
    ) {
    }

    public function name(): string
    {
        return 'ws_doc_list';
    }

    public function description(): string
    {
        return 'Wypisz dokumenty z wiki — tytuł, adres, numer rewizji i czy człowiek je potwierdził. '
            . 'Bez wskazania przestrzeni wypisuje wszystkie, do których ten token ma prawo. '
            . 'Zanim napiszesz nowy dokument, sprawdź tutaj, czy nie istnieje już podobny.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'space' => ['type' => 'string', 'description' => 'Jedna przestrzeń. Pomiń, żeby wypisać ze wszystkich swoich.'],
                'query' => ['type' => 'string', 'description' => 'Odsiej po fragmencie tytułu albo adresu.'],
                'include_archived' => ['type' => 'boolean', 'description' => 'Czy pokazać zarchiwizowane. Domyślnie nie.'],
            ],
        ];
    }

    public function call(Actor $actor, array $arguments): array
    {
        $args = new ToolArguments($arguments);
        $args->rejectUnknown(['space', 'query', 'include_archived']);

        $space = $args->optionalString('space', 100);
        $needle = $args->optionalString('query', 200);
        $archived = $args->optionalBool('include_archived') ?? false;

        // One space if named, otherwise every space the token may read. The
        // intersection with permissions happens in DocumentService either way, so a
        // space outside them contributes an empty list rather than an error.
        $spaces = null === $space
            ? $this->access->allowedSpaces($actor)
            : [new SpaceId($space)];

        $documents = [];
        foreach ($spaces as $spaceId) {
            foreach ($this->documents->list($actor, $spaceId, $archived) as $document) {
                if (null !== $needle && !$this->matches($document, $needle)) {
                    continue;
                }

                $documents[] = [
                    'space' => $document->getSpace()->getSlug(),
                    'slug' => $document->getSlug(),
                    'title' => $document->getTitle(),
                    'revision' => $document->getCurrentRevisionNumber(),
                    'authored_by_ai' => $document->isAuthoredByAi(),
                    'verified' => $document->isVerified(),
                    'archived' => $document->isArchived(),
                    'updated_at' => $document->getUpdatedAt()->format(\DATE_ATOM),
                ];
            }
        }

        return ['documents' => $documents, 'count' => \count($documents)];
    }

    private function matches(Document $document, string $needle): bool
    {
        $needle = mb_strtolower($needle);

        return str_contains(mb_strtolower($document->getTitle()), $needle)
            || str_contains(mb_strtolower($document->getSlug()), $needle);
    }
}
