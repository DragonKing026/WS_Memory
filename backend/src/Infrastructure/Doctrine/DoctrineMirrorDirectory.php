<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Publishing\Mirror;
use App\Domain\Publishing\MirrorDirectory;
use App\Domain\Space\SpaceId;
use Doctrine\DBAL\Connection;

/**
 * Adapter: the mapping table on plain DBAL.
 *
 * Not through the ORM, for the same reason as DoctrineMemoryRegistry: this query
 * runs once per drawer on the publication path, which under D-014 is the busiest
 * write path in the system, and hydrating an entity to read six columns would put
 * the identity map between a paused mapping and its effect.
 *
 * The space arrives as its slug rather than its id, because the landing rule and
 * everything downstream of it speak SpaceId. Resolving it in the join means a
 * mapping whose space was deleted simply does not come back — the foreign key
 * makes that impossible today, and a row that cannot be honoured is better absent
 * than half-built.
 */
final readonly class DoctrineMirrorDirectory implements MirrorDirectory
{
    public function __construct(private Connection $connection)
    {
    }

    public function mirrorFor(string $userId, string $sourceReplica, string $sourceWing): ?Mirror
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT m.id, m.excluded_rooms, m.is_active, m.is_confirmed, m.paused_at, s.slug
                FROM ws.mirrors m
                JOIN ws.spaces s ON s.id = m.space_id
                WHERE m.user_id = :userId
                  AND m.source_replica = :replica
                  AND m.source_wing = :wing
                SQL,
            ['userId' => $userId, 'replica' => $sourceReplica, 'wing' => $sourceWing],
        );

        if (false === $row) {
            return null;
        }

        return new Mirror(
            id: (string) $row['id'],
            userId: $userId,
            sourceReplica: $sourceReplica,
            sourceWing: $sourceWing,
            space: new SpaceId((string) $row['slug']),
            excludedRooms: $this->roomsFrom($row['excluded_rooms']),
            isActive: (bool) $row['is_active'],
            isConfirmed: (bool) $row['is_confirmed'],
            pausedAt: $this->dateOrNull($row['paused_at']),
        );
    }

    /**
     * @return list<string>
     */
    private function roomsFrom(mixed $value): array
    {
        if (!\is_string($value) || '' === trim($value)) {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($value, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Unreadable exclusions are treated as none, and that is the safe
            // direction here even though it publishes more: the alternative — an
            // exception — would stop the whole batch, and D-015 promises that a
            // server-side problem never blocks somebody's work. The room that
            // should have been excluded still lands privately unless the mapping
            // is confirmed, which is the guarantee that actually matters.
            return [];
        }

        if (!\is_array($decoded)) {
            return [];
        }

        $rooms = [];
        foreach ($decoded as $room) {
            if (\is_string($room) && '' !== trim($room)) {
                $rooms[] = $room;
            }
        }

        return $rooms;
    }

    private function dateOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            // A pause we cannot read the date of is still a pause. Answering "now"
            // keeps the mapping switched off, which is what somebody who paused it
            // asked for; answering null would silently resume publishing to a team.
            return new \DateTimeImmutable();
        }
    }
}
