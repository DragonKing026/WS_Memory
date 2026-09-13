<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Dependency\DependencyRecord;
use App\Domain\Dependency\DependencyStateStore;
use App\Domain\Dependency\Version;
use Doctrine\DBAL\Connection;

/**
 * Adapter: `ws.dependency_state` on plain DBAL.
 *
 * No entity, for the same reason as DoctrineAuditTrail: the writer is a scheduled
 * check with no other reason to hold an entity manager, and an ORM flush somebody
 * forgets to call would leave the panel reporting a check that never happened.
 *
 * The write is an upsert on the primary key, so the table holds one row per
 * dependency whether or not a row was there. `ON CONFLICT` rather than
 * select-then-insert-or-update, because two checks can overlap — a scheduled one
 * and an administrator pressing the button — and the read-decide-write version
 * loses that race with a duplicate-key error.
 *
 * Versions are stored as the text they were parsed from. Storing components in
 * separate integer columns would let the database sort them correctly, and nothing
 * here sorts them: there is one row per dependency and the comparison happens in
 * Version, where the rest of the system can see it.
 */
final readonly class DoctrineDependencyStateStore implements DependencyStateStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(string $name): ?DependencyRecord
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT installed_version, pinned_version, latest_version, checked_at, check_problem
                FROM ws.dependency_state
                WHERE name = :name
                SQL,
            ['name' => $name],
        );

        if (false === $row) {
            return null;
        }

        return new DependencyRecord(
            $name,
            self::version($row['installed_version'] ?? null),
            self::version($row['pinned_version'] ?? null),
            self::version($row['latest_version'] ?? null),
            self::moment($row['checked_at'] ?? null),
            \is_string($row['check_problem'] ?? null) ? $row['check_problem'] : null,
        );
    }

    public function save(DependencyRecord $record): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO ws.dependency_state (
                    name, installed_version, pinned_version, latest_version, checked_at, check_problem
                ) VALUES (
                    :name, :installed, :pinned, :latest, :checkedAt, :problem
                )
                ON CONFLICT (name) DO UPDATE SET
                    installed_version = EXCLUDED.installed_version,
                    pinned_version    = EXCLUDED.pinned_version,
                    latest_version    = EXCLUDED.latest_version,
                    checked_at        = EXCLUDED.checked_at,
                    check_problem     = EXCLUDED.check_problem
                SQL,
            [
                'name' => $record->name,
                'installed' => self::text($record->installed),
                'pinned' => self::text($record->pinned),
                'latest' => self::text($record->latest),
                'checkedAt' => $record->checkedAt?->format(\DateTimeInterface::ATOM),
                'problem' => $record->checkProblem,
            ],
        );
    }

    /**
     * A stored number we can no longer parse is dropped rather than fatal.
     *
     * The parser gets stricter over time — pre-releases are refused today and were
     * not always — and a row written by an older build must not make the panel
     * unopenable. Losing one field is recoverable by the next check; an exception
     * out of a read is not.
     */
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

    private static function text(?Version $version): ?string
    {
        return null === $version ? null : (string) $version;
    }
}
