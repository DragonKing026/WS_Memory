<?php

declare(strict_types=1);

namespace App\Presentation\Mcp;

/**
 * Every tool the gateway serves, collected by container tag.
 *
 * Wrapping happens here, once, on the way in: each tool arrives bare and leaves
 * audited. That is the whole decorator chain in one visible place — a reader can
 * tell what surrounds a tool call without searching the container for decorators.
 *
 * The rate limit is deliberately NOT in this chain. It is per token and per
 * request, not per tool, so it belongs to the gateway that reads the token —
 * a decorator would have to be told the same thing seven times.
 */
final class McpToolRegistry
{
    /** @var array<string, McpTool>|null */
    private ?array $indexed = null;

    /**
     * @param iterable<McpTool> $registered
     */
    public function __construct(
        private readonly iterable $registered,
        private readonly AuditedToolFactory $audited,
    ) {
    }

    /** @return list<McpTool> */
    public function all(): array
    {
        return array_values($this->indexed());
    }

    public function find(string $name): ?McpTool
    {
        return $this->indexed()[$name] ?? null;
    }

    /** @return array<string, McpTool> */
    private function indexed(): array
    {
        if (null !== $this->indexed) {
            return $this->indexed;
        }

        $indexed = [];
        foreach ($this->registered as $tool) {
            $name = $tool->name();

            if (isset($indexed[$name])) {
                // Two tools under one name would make which one runs depend on
                // service ordering. Better a container that will not build.
                throw new \LogicException(\sprintf('Dwa narzędzia MCP o tej samej nazwie: %s.', $name));
            }

            if (!str_starts_with($name, 'ws_')) {
                // The prefix is how an agent tells our tools from the local
                // palace's, with both servers connected at once (docs/04).
                throw new \LogicException(\sprintf('Narzędzie MCP musi mieć przedrostek ws_: %s.', $name));
            }

            $indexed[$name] = $this->audited->wrap($tool);
        }

        ksort($indexed);

        return $this->indexed = $indexed;
    }
}
