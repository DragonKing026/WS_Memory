<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Identity\InvitationRoster;
use App\Domain\Identity\InvitationStatus;
use App\Domain\Identity\InvitationSummary;
use Doctrine\DBAL\Connection;

/**
 * Adapter: invitations read as a listing.
 *
 * The inviter's name arrives through a LEFT JOIN, in the same statement — the
 * alternative being a lookup per row, which is the pattern the account listing
 * exists to avoid. LEFT, not INNER: `invited_by` is null for every invitation
 * issued from the console before any account existed, and an INNER JOIN would
 * hide the very first invitation of every installation.
 *
 * `token_hash` is not selected. Not because selecting it would be slow, but
 * because a column that never leaves the database cannot be serialised into a
 * response by somebody adding a field to the presentation layer.
 */
final readonly class DoctrineInvitationRoster implements InvitationRoster
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<InvitationSummary> */
    public function page(int $limit, int $offset): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::SELECT . <<<'SQL'
                ORDER BY i.created_at DESC, i.id DESC
                LIMIT :limit OFFSET :offset
                SQL,
            ['limit' => $limit, 'offset' => $offset],
        );

        return array_map(self::summaryFrom(...), $rows);
    }

    public function one(string $invitationId): ?InvitationSummary
    {
        $row = $this->connection->fetchAssociative(
            self::SELECT . 'WHERE i.id = :id',
            ['id' => $invitationId],
        );

        return false === $row ? null : self::summaryFrom($row);
    }

    private const SELECT = <<<'SQL'
        SELECT
            i.id,
            i.email,
            i.grants_global_admin,
            i.created_at,
            i.expires_at,
            i.accepted_at,
            inviter.display_name AS invited_by
        FROM ws.invitations i
        LEFT JOIN ws.users inviter ON inviter.id = i.invited_by
        SQL . "\n";

    /**
     * @param array<string, mixed> $row
     */
    private static function summaryFrom(array $row): InvitationSummary
    {
        $expiresAt = new \DateTimeImmutable((string) $row['expires_at']);
        $acceptedAt = null === $row['accepted_at']
            ? null
            : new \DateTimeImmutable((string) $row['accepted_at']);

        return new InvitationSummary(
            id: (string) $row['id'],
            email: (string) $row['email'],
            invitedBy: null === $row['invited_by'] ? null : (string) $row['invited_by'],
            grantsGlobalAdmin: (bool) $row['grants_global_admin'],
            // Computed here, from the two timestamps that are the truth. There is no
            // status column to read and there must not be one: expiry happens because
            // time passed, with nobody around to run an UPDATE.
            status: InvitationStatus::of($acceptedAt, $expiresAt),
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            expiresAt: $expiresAt,
            acceptedAt: $acceptedAt,
        );
    }
}
