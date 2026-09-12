<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Identity\Actor;

/**
 * Port: the record of who did what.
 *
 * Declared in the domain because auditing is a rule of this system, not an
 * infrastructure convenience: a knowledge base where an administrator can read
 * private content without a trace is a different product (D-016).
 */
interface AuditTrail
{
    /**
     * @param array<string, mixed> $target what exactly was acted upon
     */
    public function record(
        string $action,
        ?Actor $actor = null,
        ?string $spaceSlug = null,
        array $target = [],
    ): void;
}
