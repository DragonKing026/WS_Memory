<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Who an audit entry was written by: a person, an agent, or the system itself.
 *
 * The distinction is not decoration. "Artur Ograbek" and "agent CI (Artur Ograbek)"
 * both name a person, and only one of them was sitting at a keyboard — which is the
 * first thing a reader wants to know when an entry looks wrong. An entry with neither
 * identifier was written by the application on nobody's behalf: a scheduled check, a
 * console command, a migration. Calling that "unknown" would suggest something was
 * lost; it was not.
 *
 * The values are Polish because they are printed in the panel next to the actor's
 * name and the frontend has no dictionary of its own for them. The case names stay
 * English like the rest of the code.
 */
enum AuditActorKind: string
{
    case Human = 'czlowiek';
    case Agent = 'agent';
    case System = 'system';
}
