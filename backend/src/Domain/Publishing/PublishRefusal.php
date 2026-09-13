<?php

declare(strict_types=1);

namespace App\Domain\Publishing;

/**
 * Which kind of refusal a PublishRefused is.
 *
 * An enum carried on the exception rather than a subclass per case, so the
 * mapping onto HTTP lives in one `match` in the controller instead of a chain of
 * catch blocks that someone forgets to extend.
 *
 * The distinction that matters most is retryable versus not. The client is an
 * unattended outbox (D-015): it must keep a batch it may resend and drop one it
 * never can, and it decides that from the status code.
 */
enum PublishRefusal: string
{
    /** The request itself is wrong — resending it unchanged will fail again. */
    case Malformed = 'malformed';

    /** No such batch, or not this caller's. */
    case UnknownBatch = 'unknown_batch';

    /** Undo has already happened, or there was nothing to undo. */
    case AlreadyReverted = 'already_reverted';
}
