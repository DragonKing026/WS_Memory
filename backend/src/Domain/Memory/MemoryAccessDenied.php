<?php

declare(strict_types=1);

namespace App\Domain\Memory;

use App\Domain\Space\SpaceId;

/**
 * The actor may not write here.
 *
 * Raised for writes only, never for reads. A read outside one's permissions
 * answers with an empty result, because the message "you have no access to the
 * space HR" is itself a disclosure — it confirms the space exists (docs/03).
 * A write is different: the agent named the space explicitly, so it already
 * knows; refusing silently would only make it try again forever.
 */
final class MemoryAccessDenied extends \RuntimeException
{
    public static function write(SpaceId $space): self
    {
        return new self(\sprintf('Brak uprawnienia do zapisu w przestrzeni „%s".', $space->value));
    }
}
