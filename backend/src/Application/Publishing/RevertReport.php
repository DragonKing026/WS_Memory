<?php

declare(strict_types=1);

namespace App\Application\Publishing;

use App\Domain\Space\SpaceId;

/**
 * What an undo removed.
 *
 * Reports the count rather than only a success, because "reverted" and "reverted,
 * 0 drawers" are different facts and only the second tells somebody that a
 * previous attempt had already done the work.
 */
final readonly class RevertReport
{
    public function __construct(
        public string $batchId,
        public SpaceId $space,
        public int $removedDrawers,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch' => $this->batchId,
            'space' => $this->space->value,
            'status' => 'reverted',
            'removed' => $this->removedDrawers,
        ];
    }
}
