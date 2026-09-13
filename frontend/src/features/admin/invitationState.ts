import type { AdminInvitation, InvitationStatus } from './invitationSchemas'
import type { Badge } from './userState'

/**
 * Reading one invitation: what it is now, and whether there is anything to do about it.
 *
 * Two decisions live here, and both are the kind that go wrong in a template.
 *
 * **Withdrawal is offered only for `oczekuje`.** An accepted invitation has already
 * become an account (turning that off is the account screen's job), and an expired one
 * is a link that no longer works. Offering the button on either would earn a refusal,
 * and a button that leads to a refusal teaches people not to trust the interface.
 *
 * **An expiry date that has passed is reported, but does not overrule the backend.**
 * The status is computed when the list is fetched; a tab left open all afternoon shows
 * `oczekuje` for a link that has since died. The screen says so — otherwise somebody
 * hands out a dead link — but the status keeps coming from the server, because that is
 * what the `DELETE` will be judged against.
 */

export interface InvitationVerdict {
  status: InvitationStatus
  badge: Badge
  canRevoke: boolean
  /**
   * The listing says "pending" while the date on the same row has already passed. Not a
   * separate status: it means the list is stale, and the honest thing is to say the link
   * is very likely already dead rather than to relabel the row.
   */
  expiredWhileListed: boolean
}

const badges: Record<InvitationStatus, Badge> = {
  oczekuje: { label: 'oczekuje', color: 'info', icon: 'i-lucide-hourglass' },
  przyjete: { label: 'przyjęte', color: 'success', icon: 'i-lucide-check' },
  wygasle: { label: 'wygasłe', color: 'neutral', icon: 'i-lucide-clock-alert' },
}

/** `now` is an argument, not `new Date()` inside: a verdict that reads the clock cannot
 *  be tested without freezing time, and this one is worth testing on both sides of it. */
export function invitationVerdict(invitation: AdminInvitation, now: Date): InvitationVerdict {
  const expiry = new Date(invitation.expiresAt)
  const pending = invitation.status === 'oczekuje'
  const past = !Number.isNaN(expiry.getTime()) && expiry.getTime() <= now.getTime()

  return {
    status: invitation.status,
    badge: badges[invitation.status],
    canRevoke: pending,
    expiredWhileListed: pending && past,
  }
}

/**
 * The link a person can actually paste somewhere.
 *
 * The backend answers with a path (`/zaproszenie/<token>`), which is right for the API
 * and useless in a message to a colleague. The origin comes from the caller rather than
 * from `window` so this stays pure, and an absolute link is passed through untouched in
 * case the backend ever starts sending one.
 */
export function absoluteInvitationLink(link: string, origin: string): string {
  if (/^https?:\/\//i.test(link)) {
    return link
  }

  const base = origin.replace(/\/+$/, '')

  return link.startsWith('/') ? `${base}${link}` : `${base}/${link}`
}
