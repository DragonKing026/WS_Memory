<?php

declare(strict_types=1);

namespace App\Application\Document;

/**
 * There is no such document — or it is not yours.
 *
 * One exception for both, deliberately. A caller who may not read a space must get
 * the same answer for a document that exists there as for one that does not;
 * otherwise the error itself confirms what the space contains (inviolable rule 7).
 */
final class DocumentNotFound extends \RuntimeException
{
    public static function create(): self
    {
        return new self('Nie ma takiego dokumentu.');
    }
}
