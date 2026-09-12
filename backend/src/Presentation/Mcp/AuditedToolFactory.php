<?php

declare(strict_types=1);

namespace App\Presentation\Mcp;

use App\Domain\Audit\AuditTrail;

/**
 * Builds the audited wrapper for a tool.
 *
 * A factory rather than `new AuditedTool(...)` inside the registry, so that the
 * registry needs no dependency of its own to hand on — and so a test can put a
 * different chain around the same tools without touching the registry.
 */
final readonly class AuditedToolFactory
{
    public function __construct(private AuditTrail $audit)
    {
    }

    public function wrap(McpTool $tool): McpTool
    {
        return new AuditedTool($tool, $this->audit);
    }
}
