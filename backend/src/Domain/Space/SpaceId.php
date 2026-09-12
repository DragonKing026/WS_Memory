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

    /** Prefix of the slug of a user private space. See self::privateFor(). */
    public const PRIVATE_PREFIX = 'priv_';

    /**
     * The identifier of a given user private space.
     *
     * The convention — slug `priv_<user id>` — lives here and is used by
     * everything that needs it: the entity that creates the space, and the
     * catalogue that looks it up when a write names no space (inviolable rule 6).
     * Spelled out in two places it would eventually differ in two places, and
     * the failure mode is content filed where nobody looks for it.
     */
    public static function privateFor(string $userId): self
    {
        return new self(self::PRIVATE_PREFIX . $userId);
    }

    public function isPrivate(): bool
    {
        return str_starts_with($this->value, self::PRIVATE_PREFIX);
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
