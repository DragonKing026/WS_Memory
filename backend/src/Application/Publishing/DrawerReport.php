<?php

declare(strict_types=1);

namespace App\Application\Publishing;

use App\Domain\Memory\AcceptedMemory;
use App\Domain\Memory\AcceptOutcome;
use App\Domain\Memory\DrawerId;
use App\Domain\Publishing\Landing;
use App\Domain\Publishing\LandingReason;
use App\Domain\Publishing\SkippedDrawer;
use App\Domain\Space\SpaceId;

/**
 * What became of one drawer, reported per drawer rather than per batch.
 *
 * Counts alone would not do. The sender is an outbox that has to know, drawer by
 * drawer, which items it may tick off its queue and which to bring back (D-015) —
 * and "9 of 10 filed" identifies nothing. The landing reason rides along because
 * publication happens unwatched, so this report is the only place the routing
 * decision is ever visible to a person.
 */
final readonly class DrawerReport
{
    private function __construct(
        public string $sourceDrawerId,
        public SpaceId $space,
        public LandingReason $landing,
        /** Null exactly when the drawer was skipped. */
        public ?AcceptOutcome $outcome,
        /** Null exactly when it was not. */
        public ?SkippedDrawer $skipped,
        public ?DrawerId $drawer,
        /** The batch it belongs to; null in a preview, which books none. */
        public ?string $batchId,
    ) {
    }

    public static function accepted(
        string $sourceDrawerId,
        Landing $landing,
        AcceptedMemory $accepted,
        ?string $batchId,
    ): self {
        return new self(
            $sourceDrawerId,
            $accepted->space,
            $landing->reason,
            $accepted->outcome,
            AcceptOutcome::Duplicate === $accepted->outcome
                // A duplicate is an outcome AND a skip: the content is in the space,
                // which is what the sender wanted, and nothing was written, which
                // the batch has to account for.
                ? SkippedDrawer::duplicate($sourceDrawerId)
                : null,
            $accepted->drawer,
            $batchId,
        );
    }

    public static function refused(
        string $sourceDrawerId,
        Landing $landing,
        SkippedDrawer $skipped,
        ?string $batchId,
    ): self {
        return new self($sourceDrawerId, $landing->space, $landing->reason, null, $skipped, null, $batchId);
    }

    public function wasWritten(): bool
    {
        return AcceptOutcome::Filed === $this->outcome || AcceptOutcome::Updated === $this->outcome;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sourceDrawerId' => $this->sourceDrawerId,
            'space' => $this->space->value,
            'landing' => $this->landing->value,
            'landingNote' => $this->landing->label(),
            'outcome' => null === $this->outcome ? 'skipped' : $this->outcome->value,
            'drawer' => $this->drawer?->value,
            'batch' => $this->batchId,
            'skipped' => $this->skipped?->toArray(),
        ];
    }
}
