<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * Where one mail got to.
 *
 * The values are English and match the `chk_mail_log_status` constraint in the
 * database, like every other status column in this schema. The Polish sentence the
 * panel shows comes from `label()` and travels beside the machine value, so the
 * frontend needs no dictionary of its own and the database needs no Polish.
 *
 * `Failed` after `Sent` is impossible, but `Sent` after `Failed` is not: a message
 * the transport refused twice and accepted on the third attempt ends up `sent` with
 * the reason of the last refusal still recorded next to it. That is the useful shape
 * — "went out eventually, and here is what was wrong" — and the reason a successful
 * send does not clear the failure it followed.
 */
enum MailStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'w kolejce',
            self::Sent => 'wysłany',
            self::Failed => 'nieudany',
        };
    }
}
