<?php

declare(strict_types=1);

namespace App\Domain\Memory;

/**
 * The name of a wing in the palace — the only axis memory can be filtered on.
 *
 * A dedicated type, and not the plain string the palace protocol actually
 * carries, for one reason: inviolable rule 3 says no query reaches the palace
 * without a space filter. Expressed as `?string $wing = null` that rule lives
 * in review comments and test names. Expressed as a non-nullable type that
 * refuses to be empty, it lives in the signature — a call without a wing does
 * not compile, and an empty wing cannot be constructed.
 *
 * Deliberately distinct from SpaceId. A wing is the palace's word and a space
 * is ours; they usually coincide but `spaces.palace_wing` is free to differ,
 * and a system that confuses the two cannot ever rename either.
 */
final readonly class PalaceWing implements \Stringable
{
    public function __construct(public string $value)
    {
        if ('' === trim($value)) {
            throw new \InvalidArgumentException('A wing name cannot be empty — an unfiltered palace query is never allowed.');
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
