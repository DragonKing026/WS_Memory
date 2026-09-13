<?php

declare(strict_types=1);

namespace App\Application\Publishing;

use App\Domain\Publishing\IncomingDrawer;
use App\Domain\Space\SpaceId;

/**
 * One batch as a local palace offered it.
 *
 * A parameter object because the shape matters more than the arity: the replica
 * applies to every drawer in the request (it names the machine, not the content),
 * while the wing and room are per drawer — a single send covers whatever the
 * miner touched, across wings.
 *
 * `space` is the manual-mode escape hatch behind `/ws-publish`: name a space and
 * the mapping is bypassed. Bypassed, not overruled — naming a space you cannot
 * write to is refused rather than redirected, because an agent silently told "fine"
 * while its content went elsewhere has no way to notice (inviolable rule 6 gives
 * that guarantee only to callers who named nothing).
 *
 * There is no author field, and there is no room for one. Authorship comes from
 * the token (inviolable rule 2), and this is the endpoint where that rule earns
 * its keep: the caller is a machine describing content it did not write.
 */
final readonly class PublicationRequest
{
    /**
     * @param list<IncomingDrawer> $drawers
     */
    public function __construct(
        public string $sourceReplica,
        public array $drawers,
        public ?SpaceId $space = null,
        public bool $preview = false,
    ) {
    }
}
