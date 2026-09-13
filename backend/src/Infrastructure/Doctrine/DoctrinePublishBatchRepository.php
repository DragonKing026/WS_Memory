<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Publishing\PublishBatch;
use App\Domain\Publishing\PublishBatchRepository;
use App\Domain\Publishing\PublishBatchStatus;
use App\Domain\Publishing\PublishMode;
use App\Domain\Publishing\SkipReason;
use App\Domain\Publishing\SkippedDrawer;
use App\Domain\Space\SpaceId;
use Doctrine\DBAL\Connection;

/**
 * Adapter: the publication journal on plain DBAL.
 *
 * The space is resolved by slug inside the INSERT rather than fetched first, the
 * same way DoctrineMemoryRegistry does it and for the same reason: a separate
 * SELECT opens a window in which the space disappears between the check and the
 * write, and the row would then name a space that is gone — the foreign key would
 * catch it, with an error nobody can read.
 *
 * markReverted() carries its own guard in the WHERE clause rather than reading the
 * row first. Undo is the one action here two people can plausibly trigger at once
 * (a screen and a retrying client), and a check-then-write would let both believe
 * they did it. Conditioning the UPDATE on the current status makes the database
 * pick the winner.
 */
final readonly class DoctrinePublishBatchRepository implements PublishBatchRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(PublishBatch $batch): void
    {
        $affected = $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO ws.publish_batches (
                    id, user_id, agent_token_id, mirror_id, space_id, source_replica,
                    mode, drawer_count, skipped_count, skipped_reasons, status,
                    created_at, reverted_at
                )
                SELECT
                    :id, :userId, :agentTokenId, :mirrorId, s.id, :replica,
                    :mode, :drawerCount, :skippedCount, CAST(:skipped AS JSONB), :status,
                    :createdAt, :revertedAt
                FROM ws.spaces s
                WHERE s.slug = :spaceSlug
                SQL,
            [
                'id' => $batch->id,
                'userId' => $batch->userId,
                'agentTokenId' => $batch->agentTokenId,
                'mirrorId' => $batch->mirrorId,
                'spaceSlug' => $batch->space->value,
                'replica' => $batch->sourceReplica,
                'mode' => $batch->mode->value,
                'drawerCount' => $batch->drawerCount,
                'skippedCount' => $batch->skippedCount(),
                'skipped' => json_encode(
                    array_map(static fn (SkippedDrawer $s): array => $s->toArray(), $batch->skipped),
                    \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
                ),
                'status' => $batch->status->value,
                'createdAt' => $batch->createdAt->format('Y-m-d H:i:s'),
                'revertedAt' => $batch->revertedAt?->format('Y-m-d H:i:s'),
            ],
        );

        if (0 === $affected) {
            throw new \DomainException(\sprintf(
                'Nie ma przestrzeni „%s" — partia publikacji nie została zapisana.',
                $batch->space->value,
            ));
        }
    }

    public function find(string $id): ?PublishBatch
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT b.id, b.user_id, b.agent_token_id, b.mirror_id, b.source_replica,
                       b.mode, b.drawer_count, b.skipped_reasons, b.status,
                       b.created_at, b.reverted_at, s.slug
                FROM ws.publish_batches b
                JOIN ws.spaces s ON s.id = b.space_id
                WHERE b.id = :id
                SQL,
            ['id' => $id],
        );

        if (false === $row) {
            return null;
        }

        $mode = PublishMode::tryFrom((string) $row['mode']);
        $status = PublishBatchStatus::tryFrom((string) $row['status']);

        if (null === $mode || null === $status) {
            // Written by a newer version of the application. Reported rather than
            // guessed: this row is what an undo acts on, and treating an unknown
            // status as `applied` would delete drawers on a hunch.
            throw new \DomainException(\sprintf(
                'Partia %s ma nieznany tryb albo stan — nie zgaduję, co z nią zrobić.',
                $id,
            ));
        }

        return new PublishBatch(
            id: (string) $row['id'],
            userId: (string) $row['user_id'],
            agentTokenId: \is_string($row['agent_token_id']) ? $row['agent_token_id'] : null,
            mirrorId: \is_string($row['mirror_id']) ? $row['mirror_id'] : null,
            space: new SpaceId((string) $row['slug']),
            sourceReplica: (string) $row['source_replica'],
            mode: $mode,
            status: $status,
            drawerCount: (int) $row['drawer_count'],
            skipped: $this->skippedFrom($row['skipped_reasons']),
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            revertedAt: \is_string($row['reverted_at']) && '' !== $row['reverted_at']
                ? new \DateTimeImmutable($row['reverted_at'])
                : null,
        );
    }

    public function markReverted(string $id, \DateTimeImmutable $at): void
    {
        $affected = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE ws.publish_batches
                SET status = 'reverted', reverted_at = :at
                WHERE id = :id AND status = 'applied'
                SQL,
            ['id' => $id, 'at' => $at->format('Y-m-d H:i:s')],
        );

        if (0 === $affected) {
            throw new \DomainException(\sprintf(
                'Partia %s nie jest w stanie, który da się wycofać.',
                $id,
            ));
        }
    }

    /**
     * @return list<SkippedDrawer>
     */
    private function skippedFrom(mixed $value): array
    {
        if (!\is_string($value) || '' === trim($value)) {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($value, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($decoded)) {
            return [];
        }

        $skipped = [];
        foreach ($decoded as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $drawer = $entry['drawer'] ?? null;
            $reason = SkipReason::tryFrom(\is_string($entry['reason'] ?? null) ? $entry['reason'] : '');

            if (!\is_string($drawer) || '' === $drawer || null === $reason) {
                continue;
            }

            $skipped[] = new SkippedDrawer(
                $drawer,
                $reason,
                \is_string($entry['detail'] ?? null) ? $entry['detail'] : '',
            );
        }

        return $skipped;
    }
}
