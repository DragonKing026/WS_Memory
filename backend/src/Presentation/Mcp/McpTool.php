<?php

declare(strict_types=1);

namespace App\Presentation\Mcp;

use App\Domain\Identity\Actor;

/**
 * One tool the gateway offers to agents.
 *
 * The set of tools **is** the permission boundary (D-007). MemPalace exposes 44
 * tools that accept any wing; passing them through would mean no permissions at
 * all, so agents get a curated set instead, and every one of them takes its
 * identity from the Actor argument rather than from anything it was sent.
 *
 * Adding a tool is adding a class: the tag in services.yaml collects it into the
 * registry, so nothing else has to be edited — which matters because the
 * alternative is a list somebody eventually forgets to extend.
 *
 * **No tool may declare a parameter naming its author.** Impersonation has to be
 * inexpressible in the API rather than merely forbidden (inviolable rule 2), and
 * a test walks every schema to keep it that way.
 */
interface McpTool
{
    /** Wire name, always prefixed `ws_`. */
    public function name(): string;

    /**
     * What the tool does, as the agent will read it.
     *
     * This text is the whole documentation an agent gets. It is worth writing
     * carefully: a tool described vaguely is a tool used wrongly, and the cost
     * lands in the knowledge base rather than in an error message.
     */
    public function description(): string;

    /**
     * JSON Schema of the arguments, as MCP's `inputSchema`.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * Runs the tool for this actor.
     *
     * A payload containing a collection must also carry a `count`, so that the
     * audit trail can record how much was returned without guessing at the shape
     * (see AuditedTool).
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function call(Actor $actor, array $arguments): array;
}
