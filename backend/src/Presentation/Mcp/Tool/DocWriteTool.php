<?php

declare(strict_types=1);

namespace App\Presentation\Mcp\Tool;

use App\Application\Document\DocumentService;
use App\Application\Document\ProposalRequired;
use App\Domain\Document\DocumentSlug;
use App\Domain\Identity\Actor;
use App\Domain\Space\SpaceId;
use App\Presentation\Mcp\McpError;
use App\Presentation\Mcp\McpTool;
use App\Presentation\Mcp\ToolArguments;

/**
 * ws_doc_write — an agent writing canonical documentation, directly.
 *
 * Directly, and that is a decision (D-005): the document is readable and searchable
 * the moment it is written, marked "author: AI" and unverified. Versioning is the
 * safety net — a person can correct it or roll it back — rather than a queue somebody
 * has to empty before anybody benefits.
 *
 * The exception is a space with `requires_proposal`, where this answers `-32004` and
 * names `ws_propose`. An error that only says "denied" would have the agent retrying
 * the same call; naming the way through is the difference between a refusal and an
 * instruction.
 *
 * There is deliberately no way to verify from here, and no way to delete.
 */
final readonly class DocWriteTool implements McpTool
{
    public function __construct(private DocumentService $documents)
    {
    }

    public function name(): string
    {
        return 'ws_doc_write';
    }

    public function description(): string
    {
        return 'Zapisz dokument w wiki — nowy albo jako nową rewizję istniejącego. '
            . 'Wymaga roli piszącego w przestrzeni. Dokument jest od razu widoczny i wyszukiwalny, '
            . 'oznaczony jako napisany przez AI i niepotwierdzony; potwierdzić może tylko człowiek. '
            . 'Najpierw przeczytaj aktualną wersję przez ws_doc_read — zapis zastępuje całą treść, '
            . 'nie dopisuje do niej.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['space', 'slug', 'title', 'content'],
            'properties' => [
                'space' => ['type' => 'string', 'description' => 'Przestrzeń, w której ma powstać dokument.'],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Adres: małe litery bez ogonków, cyfry, łączniki, ukośnik jako folder '
                        . '— na przykład „wdrozenia/backup-bazy".',
                ],
                'title' => ['type' => 'string', 'description' => 'Tytuł widoczny na listach.'],
                'content' => [
                    'type' => 'string',
                    'description' => 'Pełna treść w Markdownie. Zastępuje poprzednią w całości.',
                ],
                'change_note' => [
                    'type' => 'string',
                    'description' => 'Po co ta zmiana. Krótko, ale konkretnie — to jedyny opis, jaki zobaczy '
                        . 'człowiek przeglądający historię.',
                ],
            ],
        ];
    }

    public function call(Actor $actor, array $arguments): array
    {
        $args = new ToolArguments($arguments);
        $args->rejectUnknown(['space', 'slug', 'title', 'content', 'change_note']);

        try {
            $revision = $this->documents->write(
                actor: $actor,
                space: new SpaceId($args->requiredString('space', 100)),
                slug: new DocumentSlug($args->requiredString('slug', DocumentSlug::MAX_LENGTH)),
                title: $args->requiredString('title', 300),
                content: $args->requiredString('content'),
                changeNote: $args->optionalString('change_note', 500),
            );
        } catch (ProposalRequired $e) {
            throw McpError::of(McpError::PROPOSAL_REQUIRED, $e->getMessage());
        }

        $document = $revision->getDocument();

        return [
            'space' => $document->getSpace()->getSlug(),
            'slug' => $document->getSlug(),
            'revision' => $revision->getNumber(),
            'created' => 1 === $revision->getNumber(),
            // Said out loud so the agent can tell the person it is working with, and
            // so it does not treat its own writing as settled.
            'verified' => false,
            'authored_by_ai' => true,
        ];
    }
}
