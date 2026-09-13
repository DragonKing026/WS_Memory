<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * The installation has no wording for a mail it is trying to send.
 *
 * Only reachable if the seeding migration did not run or a row was deleted by hand.
 * Loud rather than defaulting to something built into the code: a hidden fallback
 * would make "the panel shows no template but mails keep going out" a supportable
 * state, and it would be the wording nobody can edit.
 */
final class MissingMailTemplate extends \DomainException
{
    public static function forKey(MailTemplateKey $key): self
    {
        return new self(\sprintf(
            'Brak szablonu „%s” w bazie. Wykonaj migracje — szablony domyślne wgrywa migracja.',
            $key->value,
        ));
    }
}
