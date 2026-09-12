<?php

declare(strict_types=1);

namespace App\Domain\Memory;

/**
 * Memory did not answer.
 *
 * Exists so that "I found nothing" and "I could not look" stay different
 * answers. They are easy to collapse into one — an empty array satisfies both
 * signatures — and the collapse is expensive: an agent told "nothing found"
 * concludes the knowledge does not exist and writes it again, in its own words,
 * next to the copy it could not see. A failure has to be loud, or the base fills
 * up with duplicates nobody ordered.
 */
class MemoryUnavailable extends \RuntimeException
{
}
