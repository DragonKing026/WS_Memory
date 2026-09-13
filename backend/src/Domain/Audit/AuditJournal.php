<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Port: reading the audit log. Only reading.
 *
 * There is no delete and no update here, and there never will be. An audit trail that
 * can be cleared from the panel is not an audit trail — it records what an administrator
 * did, and an administrator who can erase it has recorded nothing (D-016). The write side
 * is AuditTrail::record(), which appends and nothing else; retention is a matter for
 * `docs/05-deployment.md`, where old entries are aggregated into statistics by an
 * operator with database access, never by an HTTP request.
 *
 * Kept apart from AuditTrail rather than added to it, because the two have different
 * callers and different consequences. Every write path in the system depends on
 * AuditTrail; exactly one screen depends on this. Merged, a change to the reading half
 * would touch the interface that the whole application writes through.
 */
interface AuditJournal
{
    /**
     * The rows matching the filter, their total, and the actions occurring in the log.
     *
     * One call rather than three, so a screen cannot show a count belonging to a
     * different filter than the rows above it.
     */
    public function page(AuditFilter $filter): AuditPage;
}
