<?php

declare(strict_types=1);

namespace App\Presentation\Mcp\Tool;

use App\Domain\Identity\Actor;
use App\Domain\Memory\MemoryRegistry;
use App\Domain\Space\SpaceAccessResolver;
use App\Domain\Space\SpaceCatalog;
use App\Presentation\Mcp\McpTool;
use App\Presentation\Mcp\ToolArguments;

/**
 * ws_status — what this token is and what it can reach.
 *
 * The first call an agent should make, and the only one that answers "what am I"
 * rather than "what do you know". Without it an agent has to discover its
 * permissions by failing at things, and the guesses end up in the knowledge base.
 *
 * Entry counts come from our own registry, never from the palace: an agent
 * orienting itself must not cost a semantic query, and a space it may see but
 * has not written to yet has to appear with a zero rather than vanish.
 *
 * There is no label here, and no owner e-mail. A tool receives an Actor and
 * nothing else; who owns the token is a fact for the human
 * interface, not for the agent holding it.
 */
final readonly class StatusTool implements McpTool
{
    public function __construct(
        private SpaceAccessResolver $access,
        private SpaceCatalog $spaces,
        private MemoryRegistry $registry,
    ) {
    }

    public function name(): string
    {
        return 'ws_status';
    }

    public function description(): string
    {
        return 'Kim jest ten token i do jakich przestrzeni ma prawo, z liczbą wpisów w każdej. '
            . 'Wywołaj to najpierw — bez tego nie wiesz, gdzie możesz czytać i pisać.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public function call(Actor $actor, array $arguments): array
    {
        (new ToolArguments($arguments))->rejectUnknown([]);

        $spaces = $this->access->allowedSpaces($actor);
        $counts = $this->registry->countsFor($spaces);

        $described = [];
        foreach ($spaces as $space) {
            $described[] = [
                'slug' => $space->value,
                'role' => $this->access->roleIn($actor, $space)?->value,
                'private' => $space->isPrivate(),
                'entries' => $counts[$space->value] ?? 0,
            ];
        }

        return [
            'agent_token_id' => $actor->agentTokenId,
            // Says that the token is narrower than its owner, without naming
            // what was left out — that would describe spaces it may not see.
            'scoped' => null !== $actor->spaceScope,
            'spaces' => $described,
            'count' => \count($described),
            'default_write_space' => $this->defaultWriteSpace($actor),
        ];
    }

    /**
     * Where a write with no space named will land (inviolable rule 6).
     *
     * Worth answering explicitly: an agent that does not know this either asks
     * every time or guesses, and the guess is what fills a shared space with
     * somebody's working notes.
     *
     * Asked of the catalogue rather than matched by slug prefix, so that this
     * answer and the actual destination come from the same place. Null when the
     * token's scope excludes its owner's private space — a narrowed token can
     * legitimately have nowhere default to write.
     */
    private function defaultWriteSpace(Actor $actor): ?string
    {
        $private = $this->spaces->privateSpaceOf($actor->userId);

        return null !== $private && $this->access->canWrite($actor, $private)
            ? $private->value
            : null;
    }
}
