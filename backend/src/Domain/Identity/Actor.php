<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Space\SpaceId;

/**
 * Whoever is performing an operation: a person or an agent token.
 *
 * One type for both, because the REST surface and the MCP gateway call the same
 * domain services (D-008). Were identity modelled twice, the two surfaces would
 * eventually disagree about permissions — and the disagreement would surface as
 * a leak rather than an error.
 *
 * An agent carries the id of the person who owns its token. Its optional scope
 * can only ever narrow that person's permissions; the intersection is computed
 * in SpaceAccessResolver, never here, so there is a single place to audit.
 */
final readonly class Actor
{
    /** @param list<SpaceId>|null $spaceScope null = the owner's full set */
    private function __construct(
        public string $userId,
        public ?string $agentTokenId,
        public ?array $spaceScope,
        public bool $isGlobalAdmin,
    ) {
    }

    public static function human(string $userId, bool $isGlobalAdmin = false): self
    {
        return new self($userId, null, null, $isGlobalAdmin);
    }

    /**
     * @param list<SpaceId>|null $spaceScope
     */
    public static function agent(
        string $ownerUserId,
        string $agentTokenId,
        ?array $spaceScope = null,
    ): self {
        // An agent is never a global administrator, whoever owns it.
        // Administration — creating spaces, granting roles, issuing tokens —
        // is a human act in the interface (D-007).
        return new self($ownerUserId, $agentTokenId, $spaceScope, false);
    }

    public function isAgent(): bool
    {
        return null !== $this->agentTokenId;
    }
}
