<?php

declare(strict_types=1);

namespace App\Domain\Publishing;

/**
 * Why one drawer of a batch was not published.
 *
 * Two reasons, opposite in kind. A secret is a refusal — something is wrong and
 * the sender should look at it. A duplicate is a success — the content is already
 * in that space, which is exactly what the sender wanted (D-014). Reporting both
 * as "pominięte" without saying which would make the ordinary case look like a
 * problem, and the problem look ordinary.
 */
enum SkipReason: string
{
    case Secret = 'secret';
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return match ($this) {
            self::Secret => 'filtr sekretów odrzucił treść',
            self::Duplicate => 'ta sama treść jest już w tej przestrzeni',
        };
    }
}
