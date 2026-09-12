<?php

declare(strict_types=1);

namespace App\Domain\Document;

/**
 * Whether a document is meant to be read yet.
 *
 * Note what this is NOT: a review gate. An agent's document is published and
 * searchable the moment it is written (D-005); the draft state is for a human who
 * has started writing and is not finished. Verification is a separate flag, because
 * "somebody stands behind this" and "this is finished" are different claims.
 */
enum DocumentStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
