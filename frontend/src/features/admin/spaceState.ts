import { ApiError } from '@/api/errors'

import { pluralPl } from './format'
import type { AdminSpace, SpaceMemberRole } from './spaceSchemas'
import type { Badge } from './userState'

/**
 * What the spaces list shows, and where it stops short of offering anything.
 *
 * The one decision that matters: **no member actions on a private space.** The backend
 * refuses them with 422, and a screen that offered them anyway would be teaching its
 * users that its buttons are a lottery — the most expensive thing an administration
 * panel can teach. The reason behind the backend's refusal is worth repeating on screen,
 * because it is not arbitrary: a private space holds that person's own notes and, by
 * default, the transcripts of their conversations with agents (D-014), and an
 * administrator quietly joining it would empty the promise that those are private
 * (D-016).
 */

export const memberRoleLabels: Record<SpaceMemberRole, string> = {
  admin: 'administrator przestrzeni',
  writer: 'może pisać',
  reader: 'może czytać',
}

/**
 * For the role picker: label and value, in ascending order of reach.
 *
 * Deliberately not `as const`: the select component takes a mutable array of items, and a
 * readonly tuple does not satisfy it.
 */
export const memberRoleOptions: { label: string; value: SpaceMemberRole }[] = [
  { label: memberRoleLabels.reader, value: 'reader' },
  { label: memberRoleLabels.writer, value: 'writer' },
  { label: memberRoleLabels.admin, value: 'admin' },
]

/** Narrows whatever a select hands back. The component's payload is loosely typed, and a
 *  cast would put an unchecked value straight into a request body. */
export function isSpaceMemberRole(value: unknown): value is SpaceMemberRole {
  return value === 'admin' || value === 'writer' || value === 'reader'
}

/**
 * Whether this screen may touch the membership of this space.
 *
 * The single source of that answer: the template asks this, and so does the guard inside
 * every handler, so there is no path where the check is drawn on screen but not applied.
 */
export function memberActionsAvailable(space: AdminSpace): boolean {
  return !space.isPrivate
}

/**
 * Whether the grant form may be used at all, and whether it has anything to send.
 *
 * The privacy rule comes from `memberActionsAvailable`, the same function that decides
 * whether the role picker and the removal button are drawn — granting access is a member
 * action like the others, and a second opinion about privacy is how the two would one day
 * disagree. The address is checked here too, because an empty one would travel to the
 * backend only to come back as a refusal about nothing.
 */
export function canGrantAccess(space: AdminSpace, email: string): boolean {
  return memberActionsAvailable(space) && email.trim() !== ''
}

/** A confirmation that says what is about to be handed out, or null when none is due. */
export interface GrantConfirmation {
  title: string
  confirmLabel: string
}

/**
 * Asks before the one role that widens somebody's reach beyond reading and writing.
 *
 * A space administrator can hand the space to anyone else, including themselves again
 * after being removed, so granting that role is the point of no easy return — and the
 * confirmation names what it gives rather than asking whether the reader is sure.
 * `reader` and `writer` get none: they change what one person sees in one space, which is
 * the everyday reason this screen is open, and a question in front of every grant is a
 * question nobody reads by the third time.
 */
export function grantConfirmationFor(
  space: AdminSpace,
  email: string,
  role: SpaceMemberRole,
): GrantConfirmation | null {
  if (role !== 'admin') {
    return null
  }

  return {
    title: `Nadać ${email.trim()} rolę administratora przestrzeni „${space.name}”?`,
    confirmLabel: 'Tak, nadaj rolę administratora',
  }
}

/**
 * Whether a refused grant should point at the invitations screen.
 *
 * The backend refuses this route with 422 for two reasons — no account with that address,
 * and a role it does not know — and only the first is reachable from here, because the
 * picker cannot produce a fourth role. So a 422 means the address has nobody behind it,
 * and the next step is issuing an invitation rather than retyping the address.
 *
 * Decided on the status, not on the sentence. The sentence is the backend's and is quoted
 * as it came (see `refusals.ts`); matching on its wording would make a copy of it here
 * that nothing would keep in step.
 */
export function grantRefusalNeedsInvitation(cause: unknown): boolean {
  return cause instanceof ApiError && cause.status === 422
}

/** Said on the row itself, where the missing buttons are, rather than in a footnote. */
export function privacyExplanation(space: AdminSpace): string | null {
  if (memberActionsAvailable(space)) {
    return null
  }

  return 'Przestrzeń prywatna należy do jednej osoby — trafiają do niej jej notatki i domyślnie transkrypty rozmów z agentami. Składu nie zmienia się tutaj: backend takiej zmiany nie wykona (422), więc nie ma tu przycisku, który by o nią prosił.'
}

export function spaceBadges(space: AdminSpace): Badge[] {
  const badges: Badge[] = []

  if (space.isPrivate) {
    badges.push({ label: 'prywatna', color: 'warning', icon: 'i-lucide-lock' })
  }

  if (space.requiresProposal) {
    badges.push({ label: 'wymaga propozycji', color: 'info', icon: 'i-lucide-git-pull-request' })
  }

  return badges
}

/** Members and documents as one line — the two numbers that say whether a space is used. */
export function spaceCounts(space: AdminSpace): string {
  const members = `${space.memberCount} ${pluralPl(space.memberCount, ['członek', 'członkowie', 'członków'])}`
  const documents = `${space.documentCount} ${pluralPl(space.documentCount, ['dokument', 'dokumenty', 'dokumentów'])}`

  return `${members} · ${documents}`
}
