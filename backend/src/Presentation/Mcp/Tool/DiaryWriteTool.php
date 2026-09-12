<?php

declare(strict_types=1);

namespace App\Presentation\Mcp\Tool;

use App\Application\Memory\MemoryService;
use App\Domain\Identity\Actor;
use App\Domain\Space\SpaceId;
use App\Presentation\Mcp\McpTool;
use App\Presentation\Mcp\ToolArguments;

/**
 * ws_diary_write — an agent's own record of a session.
 *
 * Raw material rather than documentation (AGENTS.md, section 4): nobody reviews
 * it and nothing is versioned. It defaults to the owner's private space for a
 * reason beyond tidiness — session notes are the content people most expect to be
 * theirs, and a default that put them in a shared space would teach everyone to
 * turn the feature off (D-016).
 */
final readonly class DiaryWriteTool implements McpTool
{
    public function __construct(private MemoryService $memory)
    {
    }

    public function name(): string
    {
        return 'ws_diary_write';
    }

    public function description(): string
    {
        return 'Zapisz w dzienniku, co się w tej sesji stało, czego się dowiedziałeś i co jest ważne. '
            . 'Domyślnie prywatnie. To surowiec, nie dokumentacja — nikt tego nie recenzuje.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['text'],
            'properties' => [
                'text' => ['type' => 'string', 'description' => 'Wpis. Zwięźle, ale dosłownie.'],
                'space' => [
                    'type' => 'string',
                    'description' => 'Przestrzeń zespołowa, jeśli wpis dotyczy wspólnej pracy. Pomiń, żeby zapisać prywatnie.',
                ],
                'topic' => ['type' => 'string', 'description' => 'Temat wpisu, na przykład nazwa zadania.'],
            ],
        ];
    }

    public function call(Actor $actor, array $arguments): array
    {
        $args = new ToolArguments($arguments);
        $args->rejectUnknown(['text', 'space', 'topic']);

        $space = $args->optionalString('space', 100);

        $stored = $this->memory->diaryWrite(
            actor: $actor,
            entry: $args->requiredString('text'),
            space: null === $space ? null : new SpaceId($space),
            topic: $args->optionalString('topic', 120),
        );

        return ['id' => $stored->drawer->value, 'space' => $stored->space->value];
    }
}
