<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Identity\UserRoster;
use App\Domain\Identity\UserSummary;
use App\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * Adapter: accounts read as a listing.
 *
 * Plain DBAL rather than the ORM, like the rest of this namespace, and here for a
 * second reason as well. Hydrating `User` entities to show a list would still leave
 * the two counts to fetch, and the obvious way to fetch them — ask each row — is a
 * query per account. The document listing already showed what that costs at ten
 * thousand rows; it took the backend down on memory before it took it down on time.
 *
 * So both counts are correlated subqueries in the SELECT list of the one statement.
 * Postgres evaluates the target list only for the rows the LIMIT lets through, so a
 * page of fifty costs fifty index lookups on `idx_members_user` and
 * `idx_agent_tokens_user` — not a scan of either table. A GROUP BY over the whole
 * of `ws.space_members` joined onto the page would have been "one query" too, and
 * would have read every membership in the installation to print fifty numbers.
 */
final readonly class DoctrineUserRoster implements UserRoster
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<UserSummary> */
    public function page(?string $query, int $limit, int $offset): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::SELECT . <<<'SQL'
                WHERE :pattern::text IS NULL
                   OR u.email ILIKE :pattern ESCAPE '\'
                   OR u.display_name ILIKE :pattern ESCAPE '\'
                ORDER BY u.created_at DESC, u.id DESC
                LIMIT :limit OFFSET :offset
                SQL,
            [
                'pattern' => self::patternFor($query),
                'limit' => $limit,
                'offset' => $offset,
            ],
        );

        return array_map(self::summaryFrom(...), $rows);
    }

    public function one(string $userId): ?UserSummary
    {
        $row = $this->connection->fetchAssociative(
            self::SELECT . 'WHERE u.id = :id',
            ['id' => $userId],
        );

        return false === $row ? null : self::summaryFrom($row);
    }

    public function countActiveGlobalAdmins(): int
    {
        // The role marker is a JSON array element, so containment rather than
        // equality: `roles` also holds whatever else was granted, and an `=` here
        // would silently stop counting an administrator the day a second role
        // appears in that column.
        return (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT count(*) FROM ws.users
                WHERE is_active = true AND roles @> :role::jsonb
                SQL,
            ['role' => json_encode([User::GLOBAL_ADMIN_ROLE], \JSON_THROW_ON_ERROR)],
        );
    }

    /**
     * The one projection, shared by the page and the single row.
     *
     * Written as a constant rather than a query builder because what matters about
     * it is that both callers get literally the same columns: the screen draws the
     * answer to a change with the same code that draws the list.
     */
    private const SELECT = <<<'SQL'
        SELECT
            u.id,
            u.email,
            u.display_name,
            u.roles,
            u.is_active,
            u.created_at,
            u.last_login_at,
            (SELECT count(*) FROM ws.space_members m WHERE m.user_id = u.id) AS space_count,
            (
                SELECT count(*) FROM ws.agent_tokens t
                WHERE t.user_id = u.id AND t.revoked_at IS NULL
            ) AS token_count
        FROM ws.users u
        SQL . "\n";

    /**
     * Turns a search box into a LIKE pattern, wildcards and all escaped.
     *
     * A `%` typed by a user is a percent sign they want to find, not "match
     * everything" — and `_` is worse, because unescaped it silently matches one
     * character and the result looks almost right.
     */
    private static function patternFor(?string $query): ?string
    {
        $trimmed = trim($query ?? '');

        return '' === $trimmed ? null : '%' . addcslashes($trimmed, '%_\\') . '%';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function summaryFrom(array $row): UserSummary
    {
        return new UserSummary(
            id: (string) $row['id'],
            email: (string) $row['email'],
            displayName: (string) $row['display_name'],
            isGlobalAdmin: self::holdsGlobalAdmin($row['roles'] ?? null),
            isActive: (bool) $row['is_active'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            lastLoginAt: null === $row['last_login_at']
                ? null
                : new \DateTimeImmutable((string) $row['last_login_at']),
            spaceCount: (int) $row['space_count'],
            tokenCount: (int) $row['token_count'],
        );
    }

    /**
     * The same question `User::isGlobalAdmin()` answers, asked of a raw jsonb value.
     *
     * Both read the one constant, so there is no second spelling of the role to
     * keep in step — a listing that disagreed with the permission check about who
     * is an administrator would be read as the truth by whoever is looking at it.
     */
    private static function holdsGlobalAdmin(mixed $roles): bool
    {
        if (!\is_string($roles)) {
            return false;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($roles, true);

        return \is_array($decoded) && \in_array(User::GLOBAL_ADMIN_ROLE, $decoded, true);
    }
}
