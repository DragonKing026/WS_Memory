<?php

declare(strict_types=1);

namespace App\Domain\Publishing;

/**
 * Where a batch is in its life.
 *
 * The values are exactly the ones `ws.publish_batches.status` accepts.
 *
 * `Preview` never reaches the table: a preview writes nothing at all, which is
 * the point of it. The case exists so a report can state what it is without a
 * second boolean beside the status — and so that if a preview is ever persisted,
 * it has a name already rather than one invented at the time.
 */
enum PublishBatchStatus: string
{
    /** A dry run: a report, no drawers, no rows. */
    case Preview = 'preview';

    /** The drawers are in the palace and booked in the registry. */
    case Applied = 'applied';

    /**
     * Undone: drawers deleted, registry rows gone, the batch itself kept.
     *
     * Kept on purpose — the publication history must not shrink when somebody
     * undoes a mistake, or "what left my machine last week" becomes
     * unanswerable (integrity rule 7 in docs/02-model-danych.md).
     */
    case Reverted = 'reverted';
}
