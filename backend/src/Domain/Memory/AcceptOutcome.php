<?php

declare(strict_types=1);

namespace App\Domain\Memory;

/**
 * What happened to one drawer arriving from a local palace.
 *
 * Three outcomes, and none of them is a failure — which is the whole point of
 * naming them. Under D-015 the sender is an unattended outbox that retries until
 * the server confirms, so "this was already here" is the expected answer to most
 * of what it sends, not an error to report. An implementation that raised on a
 * repeat would make a flaky network look like data corruption.
 */
enum AcceptOutcome: string
{
    /** New content, new drawer, new row. */
    case Filed = 'filed';

    /** The same local drawer arrived again; its row and content were refreshed. */
    case Updated = 'updated';

    /**
     * Different local drawer, content already in this space — dropped.
     *
     * Three people mining one repository send the same text three times (D-014).
     * The `(space_id, content_hash)` index is what finds it; the drop happens
     * here rather than in the table, because two people legitimately recording
     * the same sentence must not meet a failed write.
     */
    case Duplicate = 'duplicate';
}
