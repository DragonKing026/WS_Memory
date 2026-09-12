<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Health\DependencyStatus;
use App\Domain\Health\HealthProbe;
use Doctrine\DBAL\Connection;

/**
 * Adapter: checks whether the database responds.
 */
final readonly class DatabaseHealthProbe implements HealthProbe
{
    public function __construct(private Connection $connection)
    {
    }

    public function name(): string
    {
        return 'database';
    }

    public function check(): DependencyStatus
    {
        try {
            $this->connection->executeQuery('SELECT 1');

            return DependencyStatus::available();
        } catch (\Throwable $e) {
            return DependencyStatus::unavailable($e->getMessage());
        }
    }
}
