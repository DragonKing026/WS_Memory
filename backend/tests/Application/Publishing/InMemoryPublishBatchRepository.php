<?php

declare(strict_types=1);

namespace App\Tests\Application\Publishing;

use App\Domain\Publishing\PublishBatch;
use App\Domain\Publishing\PublishBatchRepository;
use App\Domain\Publishing\PublishBatchStatus;

/**
 * The publication journal without a database.
 *
 * `failOnRecord` is the lever that matters. It makes the batch row fail AFTER the
 * drawers of that batch have already gone into the palace and the registry — the
 * one ordering in which a publication can end up half-done — so the rule that a
 * batch is all or nothing is asserted by running it rather than by reading the code.
 *
 * Deliberately does NOT roll itself back inside transactional(): the registry double
 * owns that, and a second thing quietly undoing its own writes would hide a missing
 * transaction rather than reveal one.
 */
final class InMemoryPublishBatchRepository implements PublishBatchRepository
{
    /** @var array<string, PublishBatch> */
    public array $batches = [];

    public bool $failOnRecord = false;

    public function record(PublishBatch $batch): void
    {
        if ($this->failOnRecord) {
            throw new \RuntimeException('batch row failed (test)');
        }

        $this->batches[$batch->id] = $batch;
    }

    public function find(string $id): ?PublishBatch
    {
        return $this->batches[$id] ?? null;
    }

    public function markReverted(string $id, \DateTimeImmutable $at): void
    {
        $batch = $this->batches[$id] ?? null;

        if (null === $batch || PublishBatchStatus::Applied !== $batch->status) {
            throw new \DomainException(\sprintf('Partia %s nie jest w stanie, który da się wycofać.', $id));
        }

        $this->batches[$id] = new PublishBatch(
            id: $batch->id,
            userId: $batch->userId,
            agentTokenId: $batch->agentTokenId,
            mirrorId: $batch->mirrorId,
            space: $batch->space,
            sourceReplica: $batch->sourceReplica,
            mode: $batch->mode,
            status: PublishBatchStatus::Reverted,
            drawerCount: $batch->drawerCount,
            skipped: $batch->skipped,
            createdAt: $batch->createdAt,
            revertedAt: $at,
        );
    }
}
