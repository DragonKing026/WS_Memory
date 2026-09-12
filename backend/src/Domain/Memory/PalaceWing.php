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
    /**
     * Separator between a wing and an entity name in the knowledge graph.
     *
     * Two colons rather than one character, because entity names come from
     * prose and a single colon appears in them ("Uwaga: termin"). A collision
     * would not be a crash — it would quietly file a fact into the wrong space.
     */
    public const SEPARATOR = '::';

    public function __construct(public string $value)
    {
        if ('' === trim($value)) {
            throw new \InvalidArgumentException('A wing name cannot be empty — an unfiltered palace query is never allowed.');
        }
    }

    /**
     * The entity name as it is stored in the graph for this wing.
     *
     * The palace's knowledge graph has no wing axis at all: mempalace_kg_query
     * takes an entity and nothing else. Filtering facts after fetching them
     * would be precisely what inviolable rule 3 forbids, so we put the scope
     * into the key instead — every fact is written and queried under a
     * wing-qualified name (D-021). A query for another space's facts then does
     * not return them to be filtered; it does not match them.
     */
    public function qualify(string $entity): string
    {
        return $this->value . self::SEPARATOR . $entity;
    }

    /**
     * The bare entity name, or null if this name belongs to another wing.
     */
    public function unqualify(string $entity): ?string
    {
        $prefix = $this->value . self::SEPARATOR;

        return str_starts_with($entity, $prefix)
            ? substr($entity, \strlen($prefix))
            : null;
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
