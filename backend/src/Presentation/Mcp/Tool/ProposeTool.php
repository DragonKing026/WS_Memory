<?php

declare(strict_types=1);

namespace App\Presentation\Mcp\Tool;

use App\Application\Document\ProposalService;
use App\Domain\Document\DocumentSlug;
use App\Domain\Identity\Actor;
use App\Domain\Space\SpaceId;
use App\Presentation\Mcp\McpTool;
use App\Presentation\Mcp\ToolArguments;

/**
 * ws_propose — offering content where writing directly is not allowed.
 *
 * Needs only the reader role, because that is what the queue is for: somewhere an
 * agent may read but not publish, it can still contribute. Requiring the writer role
 * would leave the queue reachable only by those who do not need it.
 *
 * There is no tool to accept or reject. Reviewing is a human act in the interface
 * (D-005), and an agent approving its own proposal would make the queue decorative.
 */
final readonly class ProposeTool implements McpTool
{
    public function __construct(private ProposalService $proposals)
    {
    }

    public function name(): string
    {
        return 'ws_propose';
    }

    public function description(): string
    {
        return 'Zaproponuj dokument do kolejki, gdy przestrzeń wymaga przeglądu przed publikacją '
            . '(ws_doc_write odpowiada wtedy błędem i odsyła tutaj). Treść czeka na człowieka; '
            . 'po przyjęciu autorem rewizji jest recenzent, a informacja, że tekst napisało AI, zostaje.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['space', 'title', 'content'],
            'properties' => [
                'space' => ['type' => 'string', 'description' => 'Przestrzeń, której dotyczy propozycja.'],
                'title' => ['type' => 'string', 'description' => 'Tytuł proponowanego dokumentu.'],
                'content' => ['type' => 'string', 'description' => 'Pełna treść w Markdownie.'],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Proponowany adres. Pomiń, żeby recenzent wybrał — albo żeby powstał z tytułu.',
                ],
            ],
        ];
    }

    public function call(Actor $actor, array $arguments): array
    {
        $args = new ToolArguments($arguments);
        $args->rejectUnknown(['space', 'title', 'content', 'slug']);

        $slug = $args->optionalString('slug', DocumentSlug::MAX_LENGTH);

        $proposal = $this->proposals->propose(
            actor: $actor,
            space: new SpaceId($args->requiredString('space', 100)),
            title: $args->requiredString('title', 300),
            content: $args->requiredString('content'),
            slug: null === $slug ? null : new DocumentSlug($slug),
        );

        return [
            'id' => $proposal->getId()->toRfc4122(),
            'space' => $proposal->getSpace()->getSlug(),
            'status' => $proposal->getStatus()->value,
            // Named plainly: the content is not in the wiki and searching will not
            // find it. An agent that assumes otherwise will report it as published.
            'in_wiki' => false,
            'awaiting' => 'przegląd przez człowieka',
        ];
    }
}
