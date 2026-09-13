<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Identity\AuthorDirectory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Adapter: user ids and token ids turned into names, in one query each.
 *
 * Plain DBAL, like the rest of this namespace: hydrating User entities to read one
 * column each would put the identity map between a renamed account and its effect,
 * and pull a password hash into memory to render a history list.
 */
final readonly class DoctrineAuthorDirectory implements AuthorDirectory
{
    public function __construct(private Connection $connection)
    {
    }

    public function namesOf(array $userIds): array
    {
        $unique = self::unique($userIds);
        if ([] === $unique) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, display_name FROM ws.users WHERE id IN (:ids)',
            ['ids' => $unique],
            ['ids' => ArrayParameterType::STRING],
        );

        $names = [];
        foreach ($rows as $row) {
            $names[(string) $row['id']] = (string) $row['display_name'];
        }

        return $names;
    }

    public function tokenLabelsOf(array $tokenIds): array
    {
        $unique = self::unique($tokenIds);
        if ([] === $unique) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT t.id, t.label, u.display_name AS owner
                FROM ws.agent_tokens t
                JOIN ws.users u ON u.id = t.user_id
                WHERE t.id IN (:ids)
                SQL,
            ['ids' => $unique],
            ['ids' => ArrayParameterType::STRING],
        );

        $labels = [];
        foreach ($rows as $row) {
            // The owner travels with the label because an agent acts for somebody, and
            // "agent CI" alone does not say whose authority it wrote under.
            $labels[(string) $row['id']] = \sprintf('%s (%s)', $row['label'], $row['owner']);
        }

        return $labels;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private static function unique(array $ids): array
    {
        $seen = [];
        foreach ($ids as $id) {
            if ('' !== $id) {
                $seen[$id] = $id;
            }
        }

        return array_values($seen);
    }
}
