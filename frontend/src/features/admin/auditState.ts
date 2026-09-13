import type { AuditActorKind, AuditEntry } from './auditSchemas'
import { pluralPl } from './format'
import type { Badge } from './userState'

/**
 * How the audit log distinguishes who did something.
 *
 * The distinction is the point of the screen, so it is a table rather than a chain of
 * conditions in a template: three appearances, each with its own word, colour and icon,
 * and the compiler refusing to let a fourth kind be forgotten.
 *
 * `agent` is the one that needs to be unmistakable. An agent's row carries the display
 * name of the person whose token it used, so on name alone it is indistinguishable from
 * that person working — and "did Artur write this, or did his agent" is the question the
 * log exists to answer.
 */
const appearances: Record<AuditActorKind, Badge> = {
  czlowiek: { label: 'człowiek', color: 'neutral', icon: 'i-lucide-user' },
  agent: { label: 'agent AI', color: 'info', icon: 'i-lucide-bot' },
  system: { label: 'system', color: 'warning', icon: 'i-lucide-cog' },
}

export function actorAppearance(kind: AuditActorKind): Badge {
  return appearances[kind]
}

/** Whether there is anything behind the expander. An empty object and a null both mean
 *  "this action had no object", and an expander that opens onto `{}` is a broken promise. */
export function hasTarget(entry: AuditEntry): boolean {
  return entry.target !== null && Object.keys(entry.target).length > 0
}

/** The object of the action, as JSON. Indented, because it is read by a person trying to
 *  reconstruct what happened, not grepped. */
export function formatTarget(entry: AuditEntry): string {
  return entry.target === null ? '{}' : JSON.stringify(entry.target, null, 2)
}

/**
 * Who is behind the rows currently loaded.
 *
 * A one-line answer to the question people actually arrive with — "how much of this is
 * the agents?" — before they start reading rows. Counted over what is on screen, not over
 * `count`, and the sentence says so: the backend sends totals for the query, not a
 * breakdown by actor, and inventing one from a page would be a statistic about the first
 * twenty-five rows dressed up as a statistic about everything.
 */
export function describeActors(entries: AuditEntry[]): string | null {
  if (entries.length === 0) {
    return null
  }

  const counted = new Map<AuditActorKind, number>()

  for (const entry of entries) {
    counted.set(entry.actorKind, (counted.get(entry.actorKind) ?? 0) + 1)
  }

  const order: AuditActorKind[] = ['czlowiek', 'agent', 'system']
  const parts: string[] = []

  for (const kind of order) {
    const count = counted.get(kind) ?? 0

    if (count > 0) {
      parts.push(`${count} ${pluralPl(count, labelsFor(kind))}`)
    }
  }

  return parts.join(' · ')
}

function labelsFor(kind: AuditActorKind): readonly [string, string, string] {
  switch (kind) {
    case 'czlowiek':
      return ['od człowieka', 'od ludzi', 'od ludzi']
    case 'agent':
      return ['od agenta', 'od agentów', 'od agentów']
    case 'system':
      return ['od systemu', 'od systemu', 'od systemu']
  }
}
