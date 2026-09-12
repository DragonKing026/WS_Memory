<?php

declare(strict_types=1);

namespace App\Application\Document;

/**
 * "Publish this document's revision N into the palace."
 *
 * Asynchronous because computing an embedding for a whole document takes seconds,
 * and a person pressing save should not wait for it.
 *
 * Carries the **revision number**, and that is what makes it safe to process out of
 * order: three quick saves put three of these in the queue, and a worker holding a
 * stale one can tell, because the document's current revision has moved past it. The
 * palace therefore ends up with the newest text regardless of the order the queue
 * happens to deliver in.
 */
final readonly class PublishDocument
{
    public function __construct(
        public string $documentId,
        public int $revisionNumber,
    ) {
    }
}
