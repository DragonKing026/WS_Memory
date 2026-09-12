<?php

declare(strict_types=1);

namespace App\Domain\Memory;

use App\Domain\Space\SpaceId;

/**
 * The identifier of one piece of content in the palace.
 *
 * For a drawer this is the palace's own id (`drawer_<wing>_<room>_<hash>`).
 *
 * A knowledge-graph fact is the exception worth naming: the palace stores facts
 * but hands back no identifier for them, and our registry needs one to record
 * which space the fact belongs to. So we derive a stable identifier from the
 * fact itself — see self::forFact(). The derivation lives here and nowhere else,
 * because the write side and the read side must agree on it exactly; were it
 * computed in two places, facts would be written under one identifier and
 * looked up under another, and every fact would silently become invisible.
 */
final readonly class DrawerId implements \Stringable
{
    public function __construct(public string $value)
    {
        if ('' === trim($value)) {
            throw new \InvalidArgumentException('A drawer identifier cannot be empty.');
        }
    }

    /**
     * The identifier under which a fact is registered for a given space.
     *
     * The space is part of the fingerprint on purpose. The same triple may be
     * recorded in two spaces, and each occurrence needs its own row — a single
     * identifier would force the registry to answer "which space?" with a list,
     * and the permission check would have to decide what a list means.
     */
    public static function forFact(KnowledgeFact $fact, SpaceId $space): self
    {
        return new self('fact_' . hash('sha256', $space->value . "\0" . $fact->fingerprint()));
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
