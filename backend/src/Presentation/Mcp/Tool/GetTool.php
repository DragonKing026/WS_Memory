<?php

declare(strict_types=1);

namespace App\Presentation\Mcp\Tool;

use App\Application\Memory\MemoryService;
use App\Domain\Identity\Actor;
use App\Domain\Memory\DrawerId;
use App\Presentation\Mcp\McpTool;
use App\Presentation\Mcp\ToolArguments;

/**
 * ws_get — the full content behind an identifier from a search result.
 *
 * The answer for content this token may not have is `found: false` — exactly the
 * answer for content that does not exist. Not an error, and not a different
 * error: "you have no access to that drawer" would confirm the drawer exists,
 * and an identifier is cheap enough to guess at (inviolable rule 7).
 */
final readonly class GetTool implements McpTool
{
    public function __construct(private MemoryService $memory)
    {
    }

    public function name(): string
    {
        return 'ws_get';
    }

    public function description(): string
    {
        return 'Pobierz pełną treść po identyfikatorze z wyniku ws_search. '
            . 'Wyszukiwanie zwraca fragmenty; tutaj dostaniesz całość.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['id'],
            'properties' => [
                'id' => [
                    'type' => 'string',
                    'description' => 'Identyfikator z pola „id" wyniku ws_search.',
                ],
            ],
        ];
    }

    public function call(Actor $actor, array $arguments): array
    {
        $args = new ToolArguments($arguments);
        $args->rejectUnknown(['id']);

        $fragment = $this->memory->get($actor, new DrawerId($args->requiredString('id', 300)));

        if (null === $fragment) {
            return ['found' => false];
        }

        return [
            'found' => true,
            'id' => $fragment->id->value,
            'space' => $fragment->space?->value,
            'kind_room' => $fragment->room,
            'title' => $fragment->title(),
            'content' => $fragment->content,
            'source_file' => $fragment->sourceFile,
            'filed_at' => $fragment->filedAt?->format(\DATE_ATOM),
            'added_by' => $fragment->addedBy,
        ];
    }
}
