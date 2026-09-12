<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Who a presented agent token turns out to be.
 *
 * More than an Actor, because the two answers an MCP request needs are
 * different questions: "what may this do" (the actor) and "what is this"
 * (the label, for ws_status and for the audit trail). Fetching them together
 * costs one query instead of two on a path every single tool call walks.
 */
final readonly class AgentIdentity
{
    public function __construct(
        public string $tokenId,
        public string $label,
        public Actor $actor,
    ) {
    }
}
