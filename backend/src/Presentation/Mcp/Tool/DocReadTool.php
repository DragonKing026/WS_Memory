<?php

declare(strict_types=1);

namespace App\Presentation\Mcp\Tool;

use App\Application\Document\DocumentService;
use App\Domain\Document\DocumentSlug;
use App\Domain\Identity\Actor;
use App\Domain\Space\SpaceId;
use App\Presentation\Mcp\McpTool;
use App\Presentation\Mcp\ToolArguments;

/**
 * ws_doc_read — the full text of a document, at any revision.
 *
 * Answers `found: false` for a document in a space the token cannot read, exactly as
 * for one that does not exist (inviolable rule 7).
 *
 * The response carries `verified` and `authored_by_ai` alongside the content, because
 * an agent about to act on documentation needs to know whether anybody stands behind
 * it. Content without that pair invites treating another agent's draft as settled.
 */
final readonly class DocReadTool implements McpTool
{
    public function __construct(private DocumentService $documents)
    {
    }

    public function name(): string
    {
        return 'ws_doc_read';
    }

    public function description(): string
    {
        return 'Przeczytaj dokument z wiki w całości. Bez numeru rewizji dostaniesz aktualną. '
            . 'Sprawdź pole verified: dokument niepotwierdzony przez człowieka może być '
            . 'wcześniejszą wersją prawdy, nie ustaleniem.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['space', 'slug'],
            'properties' => [
                'space' => ['type' => 'string', 'description' => 'Przestrzeń dokumentu.'],
                'slug' => ['type' => 'string', 'description' => 'Adres dokumentu, na przykład „umowy/najem-lokalu".'],
                'revision' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Numer rewizji. Pomiń, żeby przeczytać aktualną.',
                ],
            ],
        ];
    }

    public function call(Actor $actor, array $arguments): array
    {
        $args = new ToolArguments($arguments);
        $args->rejectUnknown(['space', 'slug', 'revision']);

        $document = $this->documents->read(
            $actor,
            new SpaceId($args->requiredString('space', 100)),
            new DocumentSlug($args->requiredString('slug', DocumentSlug::MAX_LENGTH)),
        );

        if (null === $document) {
            return ['found' => false];
        }

        $number = $args->optionalInt('revision', 1, 1_000_000);
        $revision = null === $number ? $document->getCurrentRevision() : $document->revision($number);

        if (null === $revision) {
            return ['found' => false];
        }

        return [
            'found' => true,
            'space' => $document->getSpace()->getSlug(),
            'slug' => $document->getSlug(),
            'title' => $revision->getTitleAtRevision(),
            'revision' => $revision->getNumber(),
            'current_revision' => $document->getCurrentRevisionNumber(),
            'authored_by_ai' => $document->isAuthoredByAi(),
            'verified' => $document->isVerified(),
            'archived' => $document->isArchived(),
            'change_note' => $revision->getChangeNote(),
            'content' => $revision->getContent(),
        ];
    }
}
