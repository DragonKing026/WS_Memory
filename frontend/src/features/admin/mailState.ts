import type { MailLogEntry, MailStatus, MailTemplate } from './mailSchemas'
import type { Badge } from './userState'

/**
 * How the journal shows where a mail got to.
 *
 * A table rather than conditions in a template, for the reason the audit screen gives:
 * three appearances, each with its own colour and icon, and the compiler refusing to let
 * a fourth state be forgotten. The words themselves come from the backend
 * (`statusLabel`), so there is no Polish dictionary here to drift out of step.
 */
const appearances: Record<MailStatus, Omit<Badge, 'label'>> = {
  queued: { color: 'neutral', icon: 'i-lucide-clock' },
  sent: { color: 'success', icon: 'i-lucide-mail-check' },
  failed: { color: 'error', icon: 'i-lucide-mail-x' },
}

export function statusAppearance(entry: MailLogEntry): Badge {
  return { ...appearances[entry.status], label: entry.statusLabel }
}

/**
 * "trzy próby, ostatnia 14 września 09:12" — or nothing at all.
 *
 * Returns null while there is exactly one attempt and it succeeded, which is the normal
 * case: a column reading "1 próba" on every row is noise that hides the row where the
 * number is three.
 */
export function describeAttempts(entry: MailLogEntry): string | null {
  if (entry.attempts <= 1 && entry.status !== 'failed') {
    return null
  }

  return `${entry.attempts} ${attemptWord(entry.attempts)}`
}

function attemptWord(count: number): string {
  const last = count % 10
  const lastTwo = count % 100

  if (count === 1) {
    return 'próba'
  }

  if (last >= 2 && last <= 4 && (lastTwo < 12 || lastTwo > 14)) {
    return 'próby'
  }

  return 'prób'
}

/**
 * Whether the wording in the editor differs from what is saved.
 *
 * Drives both the save button and the warning about leaving. Compared as text rather
 * than tracked with a flag, so that typing something and undoing it counts as no change
 * — which is what a person means by "I didn't change anything".
 */
export function isEdited(template: MailTemplate, subject: string, body: string): boolean {
  return template.subject !== subject || template.body !== body
}

/**
 * Places a person may type, each with the sample value a preview will show.
 *
 * Built for the screen rather than read from two lists in the template, because the
 * useful thing to show next to `{{ wygasa }}` is what it turns into.
 */
export interface PlaceHint {
  name: string
  token: string
  sample: string
  /** Allowed in the body only — the subject is stored in the mail journal. */
  bodyOnly: boolean
}

export function placeHints(template: MailTemplate): PlaceHint[] {
  return template.placeholders.map((name) => ({
    name,
    token: `{{ ${name} }}`,
    sample: template.sampleValues[name] ?? '',
    bodyOnly: template.sensitive.includes(name),
  }))
}
