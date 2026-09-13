<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Space\SpaceDirectory;
use App\Domain\Space\SpaceId;
use App\Domain\Space\SpaceMemberView;
use App\Domain\Space\SpaceOverview;
use App\Domain\Space\SpaceRole;
use Doctrine\DBAL\Connection;

/**
 * Adapter: the space list with its counters, in one statement.
 *
 * Plain DBAL like the rest of this namespace. Hydrating Space entities to count their
 * members would mean loading every membership and every document of every space into
 * the identity map to print two numbers.
 *
 * The shape of the listing query is the point of this class, so it is worth reading
 * before changing it. The page of spaces is cut FIRST, in a CTE, and the counters are
 * correlated subqueries over that page. Two other shapes suggest themselves and both
 * are worse:
 *
 *   - a query per space to count its members — the N+1 that stays invisible until an
 *     installation has three hundred spaces and the panel takes seconds to open;
 *   - `LEFT JOIN (SELECT space_id, count(*) ... GROUP BY space_id)` — one statement,
 *     but it aggregates the whole of `space_members` and the whole of `documents` to
 *     answer for the fifty rows on screen.
 *
 * Both counters ride on an index that already exists: `space_members` is keyed on
 * `(space_id, user_id)` and `documents` carries `uniq_documents_space_slug` on
 * `(space_id, slug)`, so each subquery is an index scan over one space's rows.
 */
final readonly class DoctrineSpaceDirectory implements SpaceDirectory
{
    public function __construct(private Connection $connection)
    {
    }

    public function overview(int $limit = SpaceDirectory::PAGE_SIZE, int $offset = 0): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                WITH page AS (
                    SELECT id, slug, name, description, is_private, requires_proposal, palace_wing, created_at
                    FROM ws.spaces
                    ORDER BY is_private, name
                    LIMIT :limit OFFSET :offset
                )
                SELECT
                    p.slug,
                    p.name,
                    p.description,
                    p.is_private,
                    p.requires_proposal,
                    p.palace_wing,
                    p.created_at,
                    (SELECT count(*) FROM ws.space_members m WHERE m.space_id = p.id)  AS member_count,
                    (
                        SELECT count(*) FROM ws.documents d
                        WHERE d.space_id = p.id AND d.archived_at IS NULL
                    ) AS document_count
                FROM page p
                ORDER BY p.is_private, p.name
                SQL,
            ['limit' => $limit, 'offset' => $offset],
        );

        return array_map(self::overviewFrom(...), $rows);
    }

    public function total(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM ws.spaces');
    }

    public function membersOf(SpaceId $space): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    u.id,
                    u.display_name,
                    u.email,
                    m.role,
                    m.added_at,
                    granter.display_name AS added_by
                FROM ws.space_members m
                JOIN ws.spaces s       ON s.id = m.space_id
                JOIN ws.users u        ON u.id = m.user_id
                LEFT JOIN ws.users granter ON granter.id = m.added_by
                WHERE s.slug = :slug
                ORDER BY CASE m.role WHEN 'admin' THEN 0 WHEN 'writer' THEN 1 ELSE 2 END, u.display_name
                SQL,
            ['slug' => $space->value],
        );

        return array_map(self::memberFrom(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function overviewFrom(array $row): SpaceOverview
    {
        return new SpaceOverview(
            slug: (string) $row['slug'],
            name: (string) $row['name'],
            description: null === $row['description'] ? null : (string) $row['description'],
            isPrivate: (bool) $row['is_private'],
            requiresProposal: (bool) $row['requires_proposal'],
            palaceWing: (string) $row['palace_wing'],
            memberCount: (int) $row['member_count'],
            // Archived documents are left out: the number answers "how much live content
            // is in here", and an archived document is content somebody has explicitly
            // retired. It is still in the table — nothing is deleted — and still visible
            // through the wiki's own listing with `archived=1`.
            documentCount: (int) $row['document_count'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function memberFrom(array $row): SpaceMemberView
    {
        $role = SpaceRole::tryFrom((string) $row['role']);

        if (null === $role) {
            // Unreachable through the application — the column is written from the enum —
            // so this can only be a hand-edited row. It fails loudly rather than being
            // skipped or downgraded to the weakest role, because this list is the answer
            // to "who has access here": hiding the row would understate somebody's reach
            // and guessing the role would misreport it, and both are worse on this screen
            // than an error saying the data is wrong.
            throw new \UnexpectedValueException(\sprintf(
                'Nieznana rola „%s” w ws.space_members — popraw wiersz w bazie.',
                (string) $row['role'],
            ));
        }

        return new SpaceMemberView(
            userId: (string) $row['id'],
            displayName: (string) $row['display_name'],
            email: (string) $row['email'],
            role: $role,
            addedAt: new \DateTimeImmutable((string) $row['added_at']),
            // Resolved by a join rather than through AuthorDirectory: the row it names is
            // in `ws.users`, which this query already reads for the member themselves, so
            // a second lookup would buy nothing. The audit screen is the opposite case —
            // its actors are raw ids with no join to make.
            addedBy: null === $row['added_by'] ? null : (string) $row['added_by'],
        );
    }
}
