<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Space\SpaceMembershipRepository;
use App\Domain\Space\SpaceRole;
use Doctrine\DBAL\Connection;

/**
 * Adapter: reads memberships straight through DBAL.
 *
 * Deliberately not through the ORM. This query runs on the permission path of
 * every single request, and hydrating entities to read two columns would put
 * the identity map between a revoked role and its effect — exactly the cache
 * the resolver promises not to have.
 */
final readonly class DoctrineSpaceMembershipRepository implements SpaceMembershipRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function rolesFor(string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT s.slug, m.role
                FROM ws.space_members m
                JOIN ws.spaces s ON s.id = m.space_id
                WHERE m.user_id = :userId
                SQL,
            ['userId' => $userId],
        );

        $roles = [];
        foreach ($rows as $row) {
            $roles[(string) $row['slug']] = SpaceRole::from((string) $row['role']);
        }

        return $roles;
    }
}
