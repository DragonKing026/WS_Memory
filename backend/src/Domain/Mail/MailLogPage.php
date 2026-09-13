<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * One page of the mail journal and the total it was taken from.
 *
 * The total is what the paging controls need, and it counts rows matching the
 * filter rather than rows on the page — a count of the page is a number the reader
 * can already see.
 */
final readonly class MailLogPage
{
    /**
     * @param list<MailLogEntry> $entries newest first
     * @param int                $total   rows matching the filter
     */
    public function __construct(
        public array $entries,
        public int $total,
    ) {
    }
}
