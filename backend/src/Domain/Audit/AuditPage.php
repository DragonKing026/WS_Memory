<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * One page of the audit log, the total it was taken from, and the actions that occur in it.
 *
 * The three arrive together because the screen cannot be drawn from any two of them. The
 * rows are the content, the total is what the paging controls need, and the action list
 * is what the filter is built from — and that last one has to be the values actually
 * present in the table rather than a constant compiled into the frontend. A hardcoded
 * list goes stale the first time somebody adds an action, and it goes stale silently:
 * the new action's entries are in the log, visible in the list, and unreachable by the
 * filter.
 *
 * The action list is deliberately NOT filtered by the rest of the query. It describes
 * the log, not the page: a filter whose options disappeared as soon as you used one
 * would leave a reader unable to get back to where they were.
 */
final readonly class AuditPage
{
    /**
     * @param list<AuditEntry> $entries newest first
     * @param int              $total   entries matching the filter, not entries on this page
     * @param list<string>     $actions every action value present in the log, sorted
     */
    public function __construct(
        public array $entries,
        public int $total,
        public array $actions,
    ) {
    }
}
