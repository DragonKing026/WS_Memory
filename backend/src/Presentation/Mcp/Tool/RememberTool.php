<?php

declare(strict_types=1);

namespace App\Presentation\Mcp\Tool;

use App\Application\Memory\MemoryService;
use App\Domain\Identity\Actor;
use App\Domain\Memory\MemoryKind;
use App\Domain\Space\SpaceId;
use App\Presentation\Mcp\McpTool;
use App\Presentation\Mcp\ToolArguments;

/**
 * ws_remember — file a finding into the shared base.
 *
 * Two absences in the schema are the point of it.
 *
 * There is no author parameter. Identity comes from the token and impersonation
 * is therefore inexpressible, not merely forbidden (inviolable rule 2).
 *
 * There is no `kind` either, and the tool always files a note. Letting an agent
 * choose `document` here would put a drawer in the palace's documentation room
 * with no row in `documents` — a wiki page the wiki does not know about, invisible
 * to every screen a human uses and impossible to revise. Documents arrive with
 * `ws_doc_write` in TODO-005, where a revision is created alongside.
 */
final readonly class RememberTool implements McpTool
{
    public function __construct(private MemoryService $memory)
    {
    }

    public function name(): string
    {
        return 'ws_remember';
    }

    public function description(): string
    {
        return 'Zapisz ustalenie do wspólnej bazy wiedzy. Bez wskazania przestrzeni trafia do '
            . 'Twojej prywatnej — sprawdź ws_status, żeby wiedzieć której. Pisz treść dosłownie, '
            . 'nie streszczaj: streszczenie traci to, po co wracamy do zapisu.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['text'],
            'properties' => [
                'text' => [
                    'type' => 'string',
                    'description' => 'Treść dosłowna. Pierwszy niepusty wiersz staje się tytułem na listach.',
                ],
                'space' => [
                    'type' => 'string',
                    'description' => 'Przestrzeń zespołowa. Pomiń, żeby zapisać prywatnie. '
                        . 'Wymaga roli piszącego — inaczej błąd, a nie ciche przekierowanie.',
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Znaczniki do przeglądania w aplikacji. Nie wpływają na wyszukiwanie znaczeniem.',
                ],
            ],
        ];
    }

    public function call(Actor $actor, array $arguments): array
    {
        $args = new ToolArguments($arguments);
        $args->rejectUnknown(['text', 'space', 'tags']);

        $space = $args->optionalString('space', 100);

        $stored = $this->memory->remember(
            actor: $actor,
            content: $args->requiredString('text'),
            space: null === $space ? null : new SpaceId($space),
            kind: MemoryKind::Note,
            tags: $args->optionalStringList('tags') ?? [],
        );

        // The space that was actually written to, not the one that was asked for.
        // With no space named the two differ, and an agent told `null` cannot say
        // where the content went — nor notice that it went somewhere unintended.
        return [
            'id' => $stored->drawer->value,
            'space' => $stored->space->value,
            'kind' => $stored->kind->value,
        ];
    }
}
