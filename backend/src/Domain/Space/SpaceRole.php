<?php

declare(strict_types=1);

namespace App\Domain\Space;

/**
 * A role within a single space.
 *
 * Deliberately ordered by strength, so that "at least a writer" is a
 * comparison rather than a list of cases that someone forgets to extend when a
 * fourth role appears.
 */
enum SpaceRole: string
{
    case Reader = 'reader';
    case Writer = 'writer';
    case Admin = 'admin';

    public function rank(): int
    {
        return match ($this) {
            self::Reader => 1,
            self::Writer => 2,
            self::Admin => 3,
        };
    }

    public function isAtLeast(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }
}
