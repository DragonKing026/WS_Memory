<?php

declare(strict_types=1);

namespace App\Domain\Health;

/**
 * Queries every registered probe and assembles a report.
 *
 * Knows no concrete dependency — it receives them as a list of ports.
 */
final readonly class HealthChecker
{
    /** @param iterable<HealthProbe> $probes */
    public function __construct(private iterable $probes)
    {
    }

    public function check(): HealthReport
    {
        $statuses = [];

        foreach ($this->probes as $probe) {
            $statuses[$probe->name()] = $probe->check();
        }

        return new HealthReport($statuses);
    }
}
