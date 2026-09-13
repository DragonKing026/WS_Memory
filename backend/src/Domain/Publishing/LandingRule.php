<?php

declare(strict_types=1);

namespace App\Domain\Publishing;

use App\Domain\Identity\Actor;
use App\Domain\Space\SpaceCatalog;
use App\Domain\Space\SpaceId;

/**
 * The rule that decides where content from a local palace lands (D-014).
 *
 * | wing of the local palace | lands in |
 * |---|---|
 * | mapped onto a team space, mapping confirmed | that space — the team can read it |
 * | anything else | the sender's private space |
 *
 * The second row is the whole design, and it is what makes "send everything by
 * default" safe rather than reckless. Everything is on the server always — a
 * backup, searchable, reachable from a second machine — and nothing becomes
 * visible to the team without one human confirmation. So the confirmation moved
 * off publication (which now happens unwatched, thousands of times) and onto
 * mapping (which happens once and decides visibility).
 *
 * "Anything else" deliberately includes every way a mapping can fail to apply:
 * absent, unconfirmed, deactivated, paused, or excluding this room. Each is
 * somebody having said no, and the safe direction to fail in is private. Falling
 * back to the team space on a technicality — a paused mirror, say — would publish
 * to a team while the person who paused it believes they stopped exactly that.
 *
 * A class rather than a method on a service, because the rule list will grow
 * (AGENTS.md names it as the strategy it is) and because a rule this consequential
 * has to be testable without a palace, a database or an HTTP request.
 */
final readonly class LandingRule
{
    public function __construct(
        private MirrorDirectory $mirrors,
        private SpaceCatalog $spaces,
    ) {
    }

    /**
     * Where a drawer from this wing goes.
     *
     * @param SpaceId|null $requested the space the sender named, in manual mode
     *
     * @throws \DomainException when the account has no private space to fall back on
     */
    public function decide(
        Actor $actor,
        string $sourceWing,
        ?string $sourceRoom,
        string $sourceReplica,
        ?SpaceId $requested = null,
    ): Landing {
        // A named space short-circuits the mapping, and that is not a hole in the
        // rule: naming it does not grant it. Whether the actor may write there is
        // checked where every other write is checked, and a sender without the
        // role is refused rather than quietly redirected — see MemoryService.
        if (null !== $requested) {
            return new Landing($requested, LandingReason::Requested);
        }

        $mirror = $this->mirrors->mirrorFor($actor->userId, $sourceReplica, $sourceWing);

        if (null !== $mirror && $mirror->routes($sourceRoom)) {
            return new Landing($mirror->space, LandingReason::Mapped, $mirror->id);
        }

        return new Landing(
            $this->privateSpaceOf($actor),
            $mirror?->refusalReason($sourceRoom) ?? LandingReason::Unmapped,
            // The mapping is named even when it refused. A report saying "paused"
            // without saying which mapping is paused is a report you cannot act on.
            $mirror?->id,
        );
    }

    private function privateSpaceOf(Actor $actor): SpaceId
    {
        return $this->spaces->privateSpaceOf($actor->userId)
            // Every account gets its private space when the invitation is accepted,
            // so this is a broken account rather than a missing argument. Saying so
            // beats the alternative, which is filing somebody's transcripts wherever
            // happens to be reachable.
            ?? throw new \DomainException(
                'Konto nie ma prywatnej przestrzeni — publikacja z lokalnego pałaca nie ma gdzie wylądować.',
            );
    }
}
