<?php

declare(strict_types=1);

namespace App\Application\Audit;

use App\Domain\Audit\AuditFilter;
use App\Domain\Audit\AuditJournal;
use App\Domain\Audit\AuditPageView;
use App\Domain\Identity\AuthorDirectory;

/**
 * The audit screen's one question: this slice of the log, with its actors named.
 *
 * Thin by design — it holds no rule of its own — but it is the place where the two halves
 * meet, and that meeting is worth a name. The journal knows rows and raw identifiers; the
 * directory knows names; joining them is neither one's job, and doing it in the controller
 * would mean the next caller (a report, a console command) either repeats the pairing or
 * ships a screen full of UUIDs.
 *
 * Reading only. There is no companion class for deleting or editing entries, because an
 * audit log that can be cleared from the panel is not an audit log (D-016).
 */
final readonly class AuditLogQuery
{
    public function __construct(
        private AuditJournal $journal,
        private AuthorDirectory $authors,
    ) {
    }

    public function page(AuditFilter $filter): AuditPageView
    {
        return AuditPageView::resolve($this->journal->page($filter), $filter, $this->authors);
    }
}
