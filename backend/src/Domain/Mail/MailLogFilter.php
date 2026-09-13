<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * What slice of the mail journal a screen is asking for.
 *
 * Normalised here rather than in the controller, for the reason AuditFilter gives:
 * the same slice is described twice per request — once for the rows, once for the
 * total they are a slice of — and a `WHERE recipient = ''` matching nothing reads
 * exactly like an empty journal.
 *
 * The recipient is matched as a fragment, not an equality. An administrator looking
 * for a failure types what they remember of an address, and an exact-match filter
 * that answers "nothing" to a typo is a filter people stop using.
 */
final readonly class MailLogFilter
{
    public const PAGE_SIZE = 50;

    public const MAX_PAGE_SIZE = 200;

    public ?string $recipient;

    public int $limit;

    public int $offset;

    public function __construct(
        public ?MailStatus $status = null,
        ?string $recipient = null,
        int $limit = self::PAGE_SIZE,
        int $offset = 0,
    ) {
        $trimmed = trim((string) $recipient);
        $this->recipient = '' === $trimmed ? null : mb_strtolower($trimmed);
        $this->limit = max(1, min($limit, self::MAX_PAGE_SIZE));
        $this->offset = max(0, $offset);
    }
}
