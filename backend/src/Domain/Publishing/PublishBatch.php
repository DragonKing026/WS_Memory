<?php

declare(strict_types=1);

namespace App\Domain\Publishing;

use App\Domain\Space\SpaceId;

/**
 * Everything that went to one space in one publication — the unit of undoing.
 *
 * A batch belongs to exactly one space, which is what makes it undoable. One
 * request may carry drawers from several wings, and under the landing rule those
 * go to different places: some to a team space, the rest to the sender's private
 * one. Splitting per space (D-036) is what lets "take back what the team can see"
 * be one action that does not also erase a week of private transcripts.
 *
 * It also carries the skip report, because a batch that says "12 filed" and
 * nothing else leaves the sender guessing about the thirteenth.
 */
final readonly class PublishBatch
{
    /**
     * @param list<SkippedDrawer> $skipped
     */
    public function __construct(
        public string $id,
        public string $userId,
        public ?string $agentTokenId,
        public ?string $mirrorId,
        public SpaceId $space,
        public string $sourceReplica,
        public PublishMode $mode,
        public PublishBatchStatus $status,
        public int $drawerCount,
        public array $skipped,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $revertedAt = null,
    ) {
    }

    public function skippedCount(): int
    {
        return \count($this->skipped);
    }

    public function isRevertable(): bool
    {
        return PublishBatchStatus::Applied === $this->status;
    }
}
