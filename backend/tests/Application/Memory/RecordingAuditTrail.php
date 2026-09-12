<?php

declare(strict_types=1);

namespace App\Tests\Application\Memory;

use App\Domain\Audit\AuditTrail;
use App\Domain\Identity\Actor;

/**
 * The audit trail as a list, so a test can assert that a read left a trace.
 */
final class RecordingAuditTrail implements AuditTrail
{
    /** @var list<array{action: string, space: ?string, target: array<string, mixed>}> */
    public array $entries = [];

    public function record(
        string $action,
        ?Actor $actor = null,
        ?string $spaceSlug = null,
        array $target = [],
    ): void {
        $this->entries[] = ['action' => $action, 'space' => $spaceSlug, 'target' => $target];
    }

    /** @return list<string> */
    public function actions(): array
    {
        return array_map(static fn (array $e): string => $e['action'], $this->entries);
    }
}
