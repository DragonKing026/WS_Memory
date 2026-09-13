<?php

declare(strict_types=1);

namespace App\Application\Publishing;

use App\Domain\Publishing\PublishBatch;

/**
 * The answer to one publication: what went where, and which batches to undo it with.
 *
 * Batches are plural because one request can produce several — one per target
 * space (D-036). A send that covered three wings, two of them mapped, yields three
 * batches, and that is the shape the sender needs: taking back what the team can
 * see must not also take back a week of private transcripts.
 */
final readonly class PublishReport
{
    /**
     * @param list<PublishBatch> $batches
     * @param list<DrawerReport> $drawers
     */
    public function __construct(
        public string $sourceReplica,
        public bool $preview,
        public array $batches,
        public array $drawers,
    ) {
    }

    public function writtenCount(): int
    {
        return \count(array_filter($this->drawers, static fn (DrawerReport $d): bool => $d->wasWritten()));
    }

    public function skippedCount(): int
    {
        return \count(array_filter($this->drawers, static fn (DrawerReport $d): bool => null !== $d->skipped));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'replica' => $this->sourceReplica,
            'preview' => $this->preview,
            'written' => $this->writtenCount(),
            'skipped' => $this->skippedCount(),
            'batches' => array_map(
                static fn (PublishBatch $batch): array => [
                    'id' => $batch->id,
                    'space' => $batch->space->value,
                    'mode' => $batch->mode->value,
                    'status' => $batch->status->value,
                    'drawers' => $batch->drawerCount,
                    'skipped' => $batch->skippedCount(),
                    'mirror' => $batch->mirrorId,
                ],
                $this->batches,
            ),
            'drawers' => array_map(static fn (DrawerReport $d): array => $d->toArray(), $this->drawers),
        ];
    }
}
