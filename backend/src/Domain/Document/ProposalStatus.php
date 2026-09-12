<?php

declare(strict_types=1);

namespace App\Domain\Document;

/**
 * The state of an entry in the review queue.
 *
 * The queue exists only where a space asks for it (`spaces.requires_proposal`).
 * Everywhere else an agent writes directly — versioning is the safety net, not a
 * queue for approvals (AGENTS.md, section 4).
 */
enum ProposalStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function isOpen(): bool
    {
        return self::Pending === $this;
    }
}
