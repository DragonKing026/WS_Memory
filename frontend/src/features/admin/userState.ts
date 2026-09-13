import { pluralPl } from './format'
import type { AdminUser } from './userSchemas'

/**
 * What one row of the account list says, and what a click on it asks for.
 *
 * Pure functions rather than template expressions, for one reason above the others: the
 * payload. Both actions send the **new** value (`{admin: true}`, `{active: false}`), and
 * sending the current one instead is the kind of mistake that looks like the button
 * doing nothing — a bug nobody reports as a bug, they just click harder. Here it is one
 * assertion per direction.
 *
 * Notably absent: any rule about what may be refused. The backend refuses taking the
 * role off yourself, off the last administrator, and deactivating your own account, and
 * it says why in `error` (see `refusals.ts`). Re-deciding that here would give this
 * installation two copies of one rule.
 */

export type BadgeColor = 'primary' | 'success' | 'warning' | 'error' | 'neutral' | 'info'

export interface Badge {
  label: string
  color: BadgeColor
  icon: string
}

export function roleBadge(user: AdminUser): Badge {
  return user.isGlobalAdmin
    ? { label: 'administrator globalny', color: 'primary', icon: 'i-lucide-shield' }
    : { label: 'użytkownik', color: 'neutral', icon: 'i-lucide-user' }
}

export function activityBadge(user: AdminUser): Badge {
  return user.isActive
    ? { label: 'aktywne', color: 'success', icon: 'i-lucide-check' }
    : { label: 'wyłączone', color: 'error', icon: 'i-lucide-ban' }
}

/** One requested change: the value that goes on the wire, and the words that ask for it. */
export interface AccountChange {
  /** The state being asked for — the opposite of the one on screen. */
  value: boolean
  buttonLabel: string
  confirmTitle: string
  confirmLabel: string
}

export function roleChangeFor(user: AdminUser): AccountChange {
  if (user.isGlobalAdmin) {
    return {
      value: false,
      buttonLabel: 'Odbierz rolę administratora',
      confirmTitle: `Odebrać ${user.displayName} rolę administratora globalnego?`,
      confirmLabel: 'Tak, odbierz rolę',
    }
  }

  return {
    value: true,
    buttonLabel: 'Nadaj rolę administratora',
    confirmTitle: `Nadać ${user.displayName} rolę administratora globalnego?`,
    confirmLabel: 'Tak, nadaj rolę',
  }
}

export function activityChangeFor(user: AdminUser): AccountChange {
  if (user.isActive) {
    return {
      value: false,
      buttonLabel: 'Wyłącz konto',
      confirmTitle: `Wyłączyć konto ${user.displayName}?`,
      confirmLabel: 'Tak, wyłącz konto',
    }
  }

  return {
    value: true,
    buttonLabel: 'Włącz konto',
    confirmTitle: `Włączyć konto ${user.displayName} ponownie?`,
    confirmLabel: 'Tak, włącz konto',
  }
}

/**
 * What switching the account off takes down with it.
 *
 * Said in the confirmation, not afterwards. An agent token belongs to a person and
 * carries that person's access, so a deactivated account is a set of silently dead
 * agents — and the owner of those agents will read it as the gateway breaking, not as a
 * decision somebody made here.
 */
export function deactivationNotice(user: AdminUser): string {
  if (user.tokenCount === 0) {
    return 'Ta osoba nie ma teraz żadnego tokena agenta, więc nie ma czego odcinać. Utraci dostęp do interfejsu i do API.'
  }

  const tokens = `${user.tokenCount} ${pluralPl(user.tokenCount, ['token', 'tokeny', 'tokenów'])}`

  return `Wyłączenie konta unieważnia wszystkie tokeny agentów tej osoby (${tokens}) — przestaną działać przy następnym żądaniu, bez ostrzeżenia po swojej stronie.`
}

/** Which row belongs to the person reading the screen. Both backend refusals are about
 *  acting on yourself, so saying which row that is turns a 409 into something expected. */
export function isViewer(user: AdminUser, viewerId: string | null): boolean {
  return viewerId !== null && user.id === viewerId
}

/**
 * Why an action on this row is unavailable, or null when it is available.
 *
 * Only the cases this screen can know **without restating a backend rule**. Acting on
 * yourself is exactly that: the browser knows which row is yours, and it needs no copy
 * of the reasoning to know the answer will be no.
 *
 * "The last administrator" deliberately stays out of here. It depends on the whole
 * table, not on one row, and a second implementation of it would drift from the first
 * — so that one is left to the backend and quoted from its refusal.
 */
export function actionUnavailableBecause(user: AdminUser, viewerId: string | null): string | null {
  return isViewer(user, viewerId)
    ? 'Na własnym koncie tego nie zrobisz — administrator, który odbierze rolę sobie, zamyka sobie drzwi i nie ma jak ich otworzyć.'
    : null
}

/** Memberships and tokens as one line, with the counters in the right plural form. */
export function resourceSummary(user: AdminUser): string {
  const spaces = `${user.spaceCount} ${pluralPl(user.spaceCount, ['przestrzeń', 'przestrzenie', 'przestrzeni'])}`
  const tokens = `${user.tokenCount} ${pluralPl(user.tokenCount, ['token agenta', 'tokeny agentów', 'tokenów agentów'])}`

  return `${spaces} · ${tokens}`
}
