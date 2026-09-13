<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Dependency\UpdateRefused;
use App\Domain\Dependency\UpdateRequest;
use App\Domain\Dependency\UpdateRequestRepository;
use App\Domain\Dependency\UpdateStatus;
use App\Domain\Dependency\Version;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Adapter: `ws.dependency_updates` on plain DBAL.
 *
 * No entity, like the rest of this namespace. Two of the operations below are single
 * statements that must be atomic — the claim and the abandonment sweep — and an ORM
 * would turn either into read, decide, write, which is exactly the shape that loses a
 * race. A `SELECT ... FOR UPDATE SKIP LOCKED` inside an `UPDATE ... RETURNING` has no
 * natural spelling through an entity manager, and writing it as one statement here is
 * clearer than writing it as three with a lock hint.
 *
 * Times are supplied by the caller rather than taken from `now()` in SQL. The
 * abandonment cutoff is computed in PHP, so if the claim's `started_at` came from the
 * database clock the comparison would straddle two clocks — and the two live in
 * different containers. One clock, passed in, and the comparison means what it says.
 */
final readonly class DoctrineUpdateRequestRepository implements UpdateRequestRepository
{
    /** Every column, in one place: four methods hydrate the same row shape. */
    private const COLUMNS = 'id, name, from_version, to_version, status, requested_by, '
        . 'requested_at, started_at, finished_at, log';

    public function __construct(private Connection $connection)
    {
    }

    public function add(UpdateRequest $request): void
    {
        try {
            $this->connection->insert('ws.dependency_updates', [
                'id' => $request->id,
                'name' => $request->name,
                'from_version' => null === $request->fromVersion ? null : (string) $request->fromVersion,
                'to_version' => (string) $request->toVersion,
                'status' => $request->status->value,
                'requested_by' => $request->requestedBy,
                'requested_at' => $request->requestedAt->format(\DateTimeInterface::ATOM),
                'started_at' => $request->startedAt?->format(\DateTimeInterface::ATOM),
                'finished_at' => $request->finishedAt?->format(\DateTimeInterface::ATOM),
                'log' => $request->log,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // The partial unique index — `WHERE status IN ('pending','running')` — is
            // the only uniqueness this table has besides the primary key, and the
            // primary key is a UUID we just generated. So this is the two-clicks race,
            // translated into the same refusal the service raises when it can see the
            // conflict itself. The caller must not have to tell the two apart: one is
            // the check working, the other is the check being too late.
            throw UpdateRefused::alreadyInFlight($request->name, $e);
        }
    }

    public function latestFor(string $name): ?UpdateRequest
    {
        $row = $this->connection->fetchAssociative(
            \sprintf(
                'SELECT %s FROM ws.dependency_updates WHERE name = :name ORDER BY requested_at DESC, id DESC LIMIT 1',
                self::COLUMNS,
            ),
            ['name' => $name],
        );

        return false === $row ? null : self::hydrate($row);
    }

    public function recentFor(string $name, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT %s FROM ws.dependency_updates WHERE name = :name ORDER BY requested_at DESC, id DESC LIMIT :limit',
                self::COLUMNS,
            ),
            ['name' => $name, 'limit' => max(1, $limit)],
        );

        // `id DESC` after `requested_at DESC` is not decoration: the timestamp column
        // has second precision, so two orders placed in the same second would
        // otherwise come back in an order the database is free to change between
        // queries — and the first row of this list is the one the panel headlines.
        // UUIDv7 sorts by creation time, so the tie-break is chronological too.
        return array_map(self::hydrate(...), $rows);
    }

    public function claimNext(\DateTimeImmutable $startedAt): ?UpdateRequest
    {
        // One statement, and the sub-select is where the atomicity lives. `FOR UPDATE
        // SKIP LOCKED` makes a second agent run pass over the row this one is taking
        // instead of blocking on it and then claiming it a moment later; `RETURNING`
        // hands back the row this call actually moved, so the caller cannot act on an
        // order somebody else got.
        $row = $this->connection->fetchAssociative(
            \sprintf(
                <<<'SQL'
                    UPDATE ws.dependency_updates SET status = 'running', started_at = :startedAt
                    WHERE id = (
                        SELECT id FROM ws.dependency_updates
                        WHERE status = 'pending'
                        ORDER BY requested_at, id
                        LIMIT 1
                        FOR UPDATE SKIP LOCKED
                    )
                    RETURNING %s
                    SQL,
                self::COLUMNS,
            ),
            ['startedAt' => $startedAt->format(\DateTimeInterface::ATOM)],
        );

        return false === $row ? null : self::hydrate($row);
    }

    public function finish(string $id, UpdateStatus $outcome, string $log, \DateTimeImmutable $finishedAt): bool
    {
        $affected = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE ws.dependency_updates
                SET status = :status,
                    started_at = COALESCE(started_at, :finishedAt),
                    finished_at = :finishedAt,
                    log = :log
                WHERE id = :id AND status IN ('pending', 'running')
                SQL,
            [
                'status' => $outcome->value,
                'finishedAt' => $finishedAt->format(\DateTimeInterface::ATOM),
                'log' => $log,
                'id' => $id,
            ],
        );

        // `status IN ('pending','running')` rather than `= 'running'`: an agent that
        // failed between claiming and the claim's answer reaching it still has to be
        // able to close the order, and an order closed twice must not have the first
        // result overwritten by a later, staler one.
        return $affected > 0;
    }

    public function failAbandoned(\DateTimeImmutable $startedBefore, string $log, \DateTimeImmutable $now): int
    {
        return (int) $this->connection->executeStatement(
            <<<'SQL'
                UPDATE ws.dependency_updates
                SET status = 'failed',
                    finished_at = :now,
                    log = CASE WHEN log IS NULL OR log = '' THEN :log ELSE log || E'\n' || :log END
                WHERE status = 'running' AND started_at < :startedBefore
                SQL,
            [
                'now' => $now->format(\DateTimeInterface::ATOM),
                'log' => $log,
                'startedBefore' => $startedBefore->format(\DateTimeInterface::ATOM),
            ],
        );

        // Only `running` is swept. A `pending` order has not been touched by anybody,
        // so its age says nothing about whether an agent is coming — and the refusal
        // in UpdateRequestService is what keeps one from being written when none is.
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): UpdateRequest
    {
        $to = self::version($row['to_version'] ?? null);

        if (null === $to) {
            // The column is NOT NULL and every value in it passed the service's
            // pattern on the way in, so this is unreachable short of a hand-edited
            // row. It is checked rather than coerced because the alternative is a
            // typed property fed a null and a fatal error one frame later, in a
            // place that says nothing about where the bad row came from.
            throw new \RuntimeException(\sprintf(
                'Zlecenie %s ma nieczytelną wersję docelową „%s”.',
                (string) ($row['id'] ?? '?'),
                (string) ($row['to_version'] ?? ''),
            ));
        }

        return new UpdateRequest(
            (string) ($row['id'] ?? ''),
            (string) ($row['name'] ?? ''),
            self::version($row['from_version'] ?? null),
            $to,
            UpdateStatus::from((string) ($row['status'] ?? '')),
            (string) ($row['requested_by'] ?? ''),
            self::moment($row['requested_at'] ?? null) ?? new \DateTimeImmutable(),
            self::moment($row['started_at'] ?? null),
            self::moment($row['finished_at'] ?? null),
            \is_string($row['log'] ?? null) ? $row['log'] : null,
        );
    }

    private static function version(mixed $value): ?Version
    {
        return \is_string($value) ? Version::tryParse($value) : null;
    }

    private static function moment(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

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
