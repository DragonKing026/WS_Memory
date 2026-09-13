<?php

declare(strict_types=1);

namespace App\Domain\Dependency;

/**
 * How far an update order has got.
 *
 * Four states, and the first two are genuinely different — which is the reason this
 * is an enum and not a boolean. The backend only writes the order; a script on the
 * host picks it up (D-032). So `Pending` means "written, and nobody has taken it
 * yet", a state that can last a minute or forever, and `Running` means an agent
 * answered for it. Collapsing them would hide the one failure mode of the whole
 * design: an order nobody will ever claim because no agent is installed.
 *
 * The values are the strings in the database and on the wire; the frontend reads
 * them as an enum and the database constrains the column to exactly this set, so
 * renaming a case is a migration, not a rename.
 */
enum UpdateStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * Whether this order still occupies the dependency.
     *
     * The same predicate the partial unique index in the database expresses, and
     * the reason both exist: at most one order per dependency may be in flight, and
     * a second one has to be refused rather than queued behind a rebuild.
     */
    public function isInFlight(): bool
    {
        return self::Pending === $this || self::Running === $this;
    }
}
