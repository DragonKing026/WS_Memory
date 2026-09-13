<?php

declare(strict_types=1);

namespace App\Domain\Publishing;

/**
 * Port: which wings of which replica are mapped onto which spaces.
 *
 * Keyed by the owner as well as the replica and the wing, and that is not
 * over-specification. A mapping is one person's statement about one of their
 * machines; two people may well mine the same repository into a wing of the same
 * name, and one of them agreeing to share it says nothing about the other
 * (D-014). A lookup by wing alone would make the first confirmation apply to
 * everybody — which is the leak this whole rule exists to prevent.
 */
interface MirrorDirectory
{
    /**
     * The mapping for this wing, confirmed or not.
     *
     * Returns unconfirmed and paused mappings too, on purpose: the landing rule
     * has to tell "no mapping" from "a mapping nobody agreed to" in order to say
     * so in the report. Deciding is Mirror::routes()'s job, not this port's.
     */
    public function mirrorFor(string $userId, string $sourceReplica, string $sourceWing): ?Mirror;
}
