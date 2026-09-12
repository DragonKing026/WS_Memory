<?php

declare(strict_types=1);

namespace App\Application\Document;

use App\Domain\Space\SpaceId;

/**
 * This space queues agent writes instead of accepting them directly.
 *
 * A distinct exception rather than a refusal, because the answer is actionable: the
 * caller should use the proposal queue. Told only "denied", an agent would retry the
 * same call — the message has to name the way through.
 */
final class ProposalRequired extends \RuntimeException
{
    public static function in(SpaceId $space): self
    {
        return new self(\sprintf(
            'Przestrzeń „%s" przyjmuje zapisy agentów przez kolejkę propozycji. Użyj ws_propose.',
            $space->value,
        ));
    }
}
