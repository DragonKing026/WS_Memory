import { ApiError } from '@/api/errors'

/**
 * Turning a refusal into a sentence — and, for one status code, deliberately not.
 *
 * The rule that shapes this file: **a 409 is quoted, never paraphrased.** The backend
 * refuses with a conflict where a rule about someone's reach is at stake — taking the
 * global role off yourself, off the last administrator, deactivating your own account —
 * and it puts the reason in `error`. Writing those reasons a second time here would
 * create two descriptions of one rule, and they would part company the first time the
 * rule changes: the backend would refuse for one reason while the screen explained a
 * different one. So the text on screen is the backend's text.
 *
 * The other codes are named here, because the shared client cannot know which screen it
 * is talking to and its wording for two of them is wrong on these four.
 */

/**
 * The client's own fallback for a 409 with no `error` field.
 *
 * Mirrored rather than imported because it is not exported — and it has to be
 * recognised, because on these screens it is a sentence about a thing that does not
 * exist here (there is no proposal queue in administration). A test pins it down, so a
 * change to the client's wording surfaces as a red test rather than as nonsense on
 * screen.
 */
const PROPOSAL_QUEUE_FALLBACK = 'Ta przestrzeń wymaga przejścia przez kolejkę propozycji.'

export function describeAdminFailure(cause: unknown, fallback: string): string {
  if (!(cause instanceof ApiError)) {
    // Zod's explanation of a broken contract arrives this way, and it is worth showing
    // verbatim: it names the field, which is the only thing that helps here.
    return cause instanceof Error && cause.message !== '' ? cause.message : fallback
  }

  switch (cause.status) {
    case 403:
      return 'Ta operacja jest tylko dla administratora globalnego. Jeśli rola została Ci właśnie odebrana, odśwież stronę — panel nadal pokazuje stan z chwili wejścia.'
    case 404:
      // The backend answers 404 where 403 would disclose something (inviolable rule 7),
      // so this covers both "it is gone" and "it was never yours". Its own message is
      // the only one that knows which.
      return cause.message
    case 409:
      return cause.message === PROPOSAL_QUEUE_FALLBACK
        ? 'Backend odmówił: operacja jest w konflikcie ze stanem, ale nie podał powodu. Odśwież listę — najpewniej ktoś zmienił to samo w tej chwili.'
        : cause.message
    case 422:
      return cause.message
    default:
      return cause.message !== '' ? cause.message : fallback
  }
}
