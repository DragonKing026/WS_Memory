<?php

declare(strict_types=1);

namespace App\Presentation\Mcp\Tool;

use App\Application\Memory\MemoryService;
use App\Domain\Identity\Actor;
use App\Domain\Memory\KnowledgeFact;
use App\Domain\Space\SpaceId;
use App\Presentation\Mcp\McpTool;
use App\Presentation\Mcp\ToolArguments;

/**
 * ws_kg_add — record a typed fact.
 *
 * Facts are scoped to one space by qualifying the entity names (D-021), which has
 * a consequence worth stating in the description rather than hiding: a fact
 * written in one space is invisible from another, even under the same entity name.
 * An agent that expects otherwise will write the same fact twice and wonder why
 * neither place has both.
 */
final readonly class KgAddTool implements McpTool
{
    public function __construct(private MemoryService $memory)
    {
    }

    public function name(): string
    {
        return 'ws_kg_add';
    }

    public function description(): string
    {
        return 'Dodaj fakt do grafu wiedzy: podmiot → orzeczenie → dopełnienie, opcjonalnie z datą, '
            . 'od której obowiązuje. Fakt należy do jednej przestrzeni i nie jest widoczny z innych. '
            . 'Gdy coś przestało być prawdą, dodaj nowy fakt z valid_from, nie nadpisuj starego.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['subject', 'predicate', 'object'],
            'properties' => [
                'subject' => ['type' => 'string', 'description' => 'Encja, o której mówimy.'],
                'predicate' => [
                    'type' => 'string',
                    'description' => 'Typ relacji, na przykład „uzywa", „nalezy_do", „odpowiada_za".',
                ],
                'object' => ['type' => 'string', 'description' => 'Encja, z którą jest połączona.'],
                'space' => [
                    'type' => 'string',
                    'description' => 'Przestrzeń faktu. Pomiń, żeby zapisać w prywatnej.',
                ],
                'valid_from' => ['type' => 'string', 'description' => 'Od kiedy to prawda (RRRR-MM-DD).'],
                'valid_to' => ['type' => 'string', 'description' => 'Do kiedy było prawdą (RRRR-MM-DD).'],
            ],
        ];
    }

    public function call(Actor $actor, array $arguments): array
    {
        $args = new ToolArguments($arguments);
        $args->rejectUnknown(['subject', 'predicate', 'object', 'space', 'valid_from', 'valid_to']);

        $fact = new KnowledgeFact(
            subject: $args->requiredString('subject', 500),
            predicate: $args->requiredString('predicate', 200),
            object: $args->requiredString('object', 500),
            validFrom: $args->optionalDate('valid_from'),
            validTo: $args->optionalDate('valid_to'),
        );

        $space = $args->optionalString('space', 100);
        $this->memory->kgAdd($actor, $fact, null === $space ? null : new SpaceId($space));

        return ['added' => true, 'fact' => $fact->describe(), 'space' => $space];
    }
}
