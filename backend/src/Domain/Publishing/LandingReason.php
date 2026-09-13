<?php

declare(strict_types=1);

namespace App\Domain\Publishing;

/**
 * Why a drawer landed where it did (D-014).
 *
 * Four of these six reasons end in the sender's private space, and that is
 * precisely why they are told apart. "It went to your private space" is the same
 * outcome whether there is no mapping, whether somebody proposed one and never
 * confirmed it, whether it is paused, or whether the room is excluded — but the
 * four call for completely different next actions, and a sender who cannot tell
 * them apart concludes the mapping is broken.
 */
enum LandingReason: string
{
    /** A confirmed, active mapping sent it to a team space. */
    case Mapped = 'mapped';

    /** No mapping for this wing at all — the common case, and the default (D-014). */
    case Unmapped = 'unmapped';

    /** A mapping exists but no human has confirmed it, so it routes nothing. */
    case Unconfirmed = 'unconfirmed';

    /** The mapping is switched off or paused. */
    case Paused = 'paused';

    /** The wing is mapped, this room is not. */
    case ExcludedRoom = 'excluded_room';

    /** The sender named the space outright — manual mode. */
    case Requested = 'requested';

    public function label(): string
    {
        return match ($this) {
            self::Mapped => 'skrzydło zmapowane na przestrzeń zespołową',
            self::Unmapped => 'skrzydło bez mapowania — prywatna przestrzeń właściciela',
            self::Unconfirmed => 'mapowanie niepotwierdzone przez człowieka — prywatna przestrzeń właściciela',
            self::Paused => 'mapowanie wstrzymane — prywatna przestrzeń właściciela',
            self::ExcludedRoom => 'pokój wykluczony z mapowania — prywatna przestrzeń właściciela',
            self::Requested => 'przestrzeń wskazana w żądaniu',
        };
    }

    public function mode(): PublishMode
    {
        return self::Mapped === $this ? PublishMode::Mirror : PublishMode::Selective;
    }
}
