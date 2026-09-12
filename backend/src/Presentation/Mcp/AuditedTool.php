<?php

declare(strict_types=1);

namespace App\Presentation\Mcp;

use App\Domain\Audit\AuditTrail;
use App\Domain\Identity\Actor;

/**
 * Decorator: every tool call leaves a trace, successful or not.
 *
 * Wrapped around each tool by the registry rather than written into them, because
 * auditing applies to all of them — and a rule repeated in seven classes is a
 * rule that will be missing from the eighth.
 *
 * This entry deliberately overlaps with the one MemoryService records. They
 * answer different questions: this one says which **tool** a given **token**
 * invoked and whether it failed, and it exists even for calls that never reach
 * memory (`ws_status`) or that fail before it (bad arguments, no permission).
 * Without it, precisely the calls worth investigating would be the invisible ones.
 */
final readonly class AuditedTool implements McpTool
{
    public function __construct(
        private McpTool $inner,
        private AuditTrail $audit,
    ) {
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function description(): string
    {
        return $this->inner->description();
    }

    public function inputSchema(): array
    {
        return $this->inner->inputSchema();
    }

    public function call(Actor $actor, array $arguments): array
    {
        $space = \is_string($arguments['space'] ?? null) ? $arguments['space'] : null;

        try {
            $payload = $this->inner->call($actor, $arguments);
        } catch (\Throwable $e) {
            $this->audit->record(
                action: 'mcp.' . $this->name(),
                actor: $actor,
                spaceSlug: $space,
                target: ['error' => $e::class, 'message' => $e->getMessage()],
            );

            throw $e;
        }

        $this->audit->record(
            action: 'mcp.' . $this->name(),
            actor: $actor,
            spaceSlug: $space,
            // `count` rather than a guess at the payload shape: a tool returning
            // a collection is required to declare it (see McpTool).
            target: array_filter(
                ['count' => $payload['count'] ?? null],
                static fn (mixed $v): bool => null !== $v,
            ),
        );

        return $payload;
    }
}
