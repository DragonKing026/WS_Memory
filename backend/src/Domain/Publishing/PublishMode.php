<?php

declare(strict_types=1);

namespace App\Domain\Publishing;

/**
 * How a batch came to exist.
 *
 * The values are exactly the ones `ws.publish_batches.mode` accepts, so a mode
 * the database would refuse cannot be constructed.
 *
 * The distinction is not bookkeeping: a `mirror` batch went where a confirmed
 * mapping said it should and is therefore visible to a team, while a `selective`
 * one went where the sender asked or — far more often — into the sender's own
 * private space (D-014). Somebody reading the publication journal to answer "did
 * this leave my machine for other people to read" needs those two apart.
 */
enum PublishMode: string
{
    /** The sender named a space, or there was no mapping and it went private. */
    case Selective = 'selective';

    /** A confirmed mapping routed it to a team space. */
    case Mirror = 'mirror';
}
