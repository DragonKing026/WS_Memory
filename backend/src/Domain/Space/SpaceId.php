<?php

declare(strict_types=1);

namespace App\Domain\Space;

/**
 * A space identifier.
 *
 * A dedicated type rather than a bare string, because space identifiers travel
 * through every permission check in the system. A string can be confused with a
 * user id, a slug or a wing name; this type cannot. Given that a mistake here
 * means content reaching the wrong person, the cost of the wrapper is trivial.
 */
final readonly class SpaceId implements \Stringable
{
    public function __construct(public string $value)
    {
        if ('' === trim($value)) {
            throw new \InvalidArgumentException('A space identifier cannot be empty.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
