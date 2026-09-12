<?php

declare(strict_types=1);

namespace App\Presentation\Mcp\Tool;

use App\Application\Memory\MemoryService;
use App\Domain\Identity\Actor;
use App\Domain\Memory\KnowledgeFact;
use App\Presentation\Mcp\McpTool;
use App\Presentation\Mcp\ToolArguments;

/**
 * ws_kg_query — typed facts about an entity, from spaces this token may read.
 *
 * Entity names are wing-qualified in storage and unqualified here (D-021), so an
 * agent asks about "WS_Memory" and never learns that the graph calls it something
 * longer. The consequence to know: the same name in two spaces is two entities,
 * and facts never cross between them.
 */
final readonly class KgQueryTool implements McpTool
{
    public function __construct(private MemoryService $memory)
    {
    }

    public function name(): string
    {
        return 'ws_kg_query';
    }

    public function description(): string
    {
        return 'Fakty o osobie, projekcie albo systemie z grafu wiedzy — z okresem ważności, '
            . 'więc widać też, co przestało być prawdą. Pytaj tu, zanim odpowiesz na pytanie '
            . 'o kogoś lub o coś: zgadywanie jest gorsze niż sprawdzenie.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['entity'],
            'properties' => [
                'entity' => [
                    'type' => 'string',
                    'description' => 'Nazwa encji, na przykład „WS_Memory" albo imię osoby.',
                ],
                'direction' => [
                    'type' => 'string',
                    'enum' => ['outgoing', 'incoming', 'both'],
                    'description' => 'outgoing: encja jest podmiotem; incoming: jest dopełnieniem. Domyślnie both.',
                ],
                'spaces' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Zawęź do wskazanych przestrzeni. Może tylko zawężać.',
                ],
            ],
        ];
    }

    public function call(Actor $actor, array $arguments): array
    {
        $args = new ToolArguments($arguments);
        $args->rejectUnknown(['entity', 'direction', 'spaces']);

        /** @var 'outgoing'|'incoming'|'both'|null $direction */
        $direction = $args->optionalEnum('direction', ['outgoing', 'incoming', 'both']);

        $facts = $this->memory->kgQuery(
            $actor,
            $args->requiredString('entity', 300),
            $direction,
            $args->optionalSpaces(),
        );

        return [
            'facts' => array_map(static fn (KnowledgeFact $f): array => [
                'subject' => $f->subject,
                'predicate' => $f->predicate,
                'object' => $f->object,
                'valid_from' => $f->validFrom?->format('Y-m-d'),
                'valid_to' => $f->validTo?->format('Y-m-d'),
            ], $facts),
            'count' => \count($facts),
        ];
    }
}
