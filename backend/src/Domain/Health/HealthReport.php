<?php

declare(strict_types=1);

namespace App\Domain\Health;

/**
 * Aggregate system status.
 *
 * Reports dependencies separately instead of a single ok/not-ok, because from
 * the outside a dead database and an unreachable memory service look
 * identical — the API answers, nothing works — yet they call for entirely
 * different operator action.
 */
final readonly class HealthReport
{
    /** @param array<string, DependencyStatus> $dependencies */
    public function __construct(private array $dependencies)
    {
    }

    public function isHealthy(): bool
    {
        foreach ($this->dependencies as $status) {
            if (!$status->available) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = ['status' => $this->isHealthy() ? 'healthy' : 'unhealthy'];

        foreach ($this->dependencies as $name => $status) {
            $result[$name] = $status->toArray();
        }

        return $result;
    }
}
