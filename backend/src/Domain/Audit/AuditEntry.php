<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * One audit entry as it was stored: raw identifiers, nothing resolved.
 *
 * The identifiers stay raw here on purpose, mirroring the table. `ws.audit_log` records
 * an actor by plain id and holds no relation to the accounts table, so that deactivating
 * an account never cascades into the history of what it did — and so that a display name
 * copied at write time cannot go stale. Turning those ids into names is a separate step,
 * done in bulk for a whole page (see AuditPageView).
 *
 * `target` is whatever the writing code put there, shaped differently per action. It is
 * carried through to the panel as-is rather than being flattened into columns: the set of
 * actions grows with every feature, and a schema for their payloads would have to grow
 * with it or start lying.
 */
final readonly class AuditEntry
{
    /** @param array<string, mixed> $target */
    public function __construct(
        public string $id,
        public string $action,
        public ?string $actorUserId,
        public ?string $actorAgentTokenId,
        public ?string $spaceSlug,
        public array $target,
        public ?string $ip,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * An agent is recognised by its token, not by the absence of a user id.
     *
     * Both columns are filled for an agent's entry — the token AND the id of the person
     * who owns it — because an agent always acts under somebody's authority (inviolable
     * rule 4). So the token has to be checked first; checking the user id first would
     * report every agent action as a human one.
     */
    public function actorKind(): AuditActorKind
    {
        return match (true) {
            null !== $this->actorAgentTokenId => AuditActorKind::Agent,
            null !== $this->actorUserId => AuditActorKind::Human,
            default => AuditActorKind::System,
        };
    }
}
