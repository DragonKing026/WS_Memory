<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Dependency\UpdaterHeartbeat;
use Doctrine\DBAL\Connection;

/**
 * Adapter: `ws.updater_heartbeat`, one row, overwritten.
 *
 * The upsert is on the primary key, which the schema pins to the single value 1. The
 * alternative — appending a row per pulse — would be a row a minute forever to answer
 * a question one timestamp answers, and would need its own pruning to stay that way.
 *
 * `ON CONFLICT` rather than update-then-insert-if-nothing-changed for the usual
 * reason: the first pulse after a deployment is the one that would race, and the
 * read-decide-write version loses it with a duplicate-key error on the very path that
 * is supposed to prove the agent works.
 */
final readonly class DoctrineUpdaterHeartbeat implements UpdaterHeartbeat
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(\DateTimeImmutable $seenAt): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO ws.updater_heartbeat (id, seen_at) VALUES (1, :seenAt)
                ON CONFLICT (id) DO UPDATE SET seen_at = EXCLUDED.seen_at
                SQL,
            ['seenAt' => $seenAt->format(\DateTimeInterface::ATOM)],
        );
    }

    public function lastSeen(): ?\DateTimeImmutable
    {
        $value = $this->connection->fetchOne('SELECT seen_at FROM ws.updater_heartbeat WHERE id = 1');

        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        // No row means no agent has ever reported, which is a different thing from a
        // late one and is reported as such (UpdaterState::installed()).
        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
