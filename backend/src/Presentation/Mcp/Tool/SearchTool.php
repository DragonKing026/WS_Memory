<?php

declare(strict_types=1);

namespace App\Presentation\Mcp\Tool;

use App\Application\Memory\MemoryService;
use App\Domain\Identity\Actor;
use App\Domain\Memory\MemoryFragment;
use App\Domain\Memory\MemoryKind;
use App\Domain\Memory\MemoryQuery;
use App\Presentation\Mcp\McpTool;
use App\Presentation\Mcp\ToolArguments;

/**
 * ws_search — semantic search across every space this token may read.
 *
 * Note what the schema does NOT contain: any way to say which wing of the palace
 * to look in. The set of spaces is computed from the token's permissions, and the
 * optional `spaces` argument can only narrow that set (docs/03). An agent that
 * sends `wing`, having learned the name from the local MemPalace server, is told
 * off rather than quietly ignored — see ToolArguments::rejectUnknown().
 */
final readonly class SearchTool implements McpTool
{
    public function __construct(private MemoryService $memory)
    {
    }

    public function name(): string
    {
        return 'ws_search';
    }

    public function description(): string
    {
        return 'Szukaj w firmowej bazie wiedzy znaczeniem, nie słowami — zapytanie po polsku '
            . 'znajduje treść opisaną innymi słowami. Szuka tylko tam, gdzie ten token ma prawo. '
            . 'Zanim coś zapiszesz, sprawdź tutaj, czy już tego nie ma.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['query'],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'maxLength' => MemoryQuery::MAX_QUERY_LENGTH,
                    'description' => 'Same słowa kluczowe albo pytanie, maksymalnie '
                        . MemoryQuery::MAX_QUERY_LENGTH . ' znaków. Tło opisu nie wpisuj — psuje trafność.',
                ],
                'spaces' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Zawęź do wskazanych przestrzeni. Może tylko zawężać: '
                        . 'przestrzeń, do której token nie ma prawa, nie doda się przez ten parametr. '
                        . 'Pomiń, żeby szukać we wszystkich swoich.',
                ],
                'kind' => [
                    'type' => 'string',
                    'enum' => ['note', 'document', 'diary', 'transcript'],
                    'description' => 'Ogranicz do jednej klasy wiedzy.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => MemoryQuery::MAX_LIMIT,
                    'description' => 'Ile trafień. Domyślnie ' . MemoryQuery::DEFAULT_LIMIT . '.',
                ],
                'since' => ['type' => 'string', 'description' => 'Tylko wpisy od tej daty (RRRR-MM-DD).'],
                'before' => ['type' => 'string', 'description' => 'Tylko wpisy przed tą datą (RRRR-MM-DD).'],
            ],
        ];
    }

    public function call(Actor $actor, array $arguments): array
    {
        $args = new ToolArguments($arguments);
        $args->rejectUnknown(['query', 'spaces', 'kind', 'limit', 'since', 'before']);

        $kind = $args->optionalEnum('kind', ['note', 'document', 'diary', 'transcript']);

        $query = new MemoryQuery(
            text: $args->requiredString('query', MemoryQuery::MAX_QUERY_LENGTH),
            limit: $args->optionalInt('limit', 1, MemoryQuery::MAX_LIMIT) ?? MemoryQuery::DEFAULT_LIMIT,
            kind: null === $kind ? null : MemoryKind::from($kind),
            since: $args->optionalDate('since'),
            before: $args->optionalDate('before'),
        );

        $fragments = $this->memory->search($actor, $query, $args->optionalSpaces());

        return [
            'results' => array_map(static fn (MemoryFragment $f): array => [
                'id' => $f->id->value,
                'space' => $f->space?->value,
                'kind_room' => $f->room,
                'title' => $f->title(),
                'content' => $f->content,
                'similarity' => $f->similarity,
                'filed_at' => $f->filedAt?->format(\DATE_ATOM),
            ], $fragments),
            'count' => \count($fragments),
        ];
    }
}
