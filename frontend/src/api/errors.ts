/**
 * What can go wrong with a request, named rather than left as an HTTP number.
 *
 * Every screen reacts to these, and reacting to `err.response?.status === 403`
 * scattered across components is how one screen ends up saying "not found" where
 * another says "forbidden" — for the same situation. The backend is deliberate about
 * answering 404 where 403 would disclose something (inviolable rule 7), and that
 * intent has to survive into the interface.
 */
export type ApiProblem =
  /** No valid token. The session is over; the only cure is signing in again. */
  | { kind: 'unauthenticated'; message: string }
  /** Signed in, but this action is not allowed. Shown as-is — the backend only
   *  answers this where the caller already knows the resource exists. */
  | { kind: 'forbidden'; message: string }
  /** Not there — or not yours, which the backend answers identically on purpose. */
  | { kind: 'missing'; message: string }
  /** The request was understood and refused: a validation rule, a malformed slug. */
  | { kind: 'rejected'; message: string }
  /** The space wants this to go through the review queue. */
  | { kind: 'needsProposal'; message: string }
  /** Too many requests. */
  | { kind: 'throttled'; message: string }
  /** The server broke, or nothing answered at all. */
  | { kind: 'unavailable'; message: string }

export class ApiError extends Error {
  constructor(
    readonly problem: ApiProblem,
    readonly status: number | null,
  ) {
    super(problem.message)
    this.name = 'ApiError'
  }

  get kind(): ApiProblem['kind'] {
    return this.problem.kind
  }

  /** True when signing in again is what would help. */
  get needsSignIn(): boolean {
    return this.problem.kind === 'unauthenticated'
  }
}

/**
 * Turns a status code and whatever the body said into a named problem.
 *
 * The message comes from the backend when it sent one, because those messages are
 * written for the person reading them — "Adres „Umowy Najmu” jest nieprawidłowy"
 * beats anything this file could invent. The fallbacks are only for when there is
 * nothing to quote.
 */
export function problemFrom(status: number | null, body: unknown): ApiProblem {
  const message = messageFrom(body)

  switch (status) {
    case 401:
      return { kind: 'unauthenticated', message: message ?? 'Sesja wygasła. Zaloguj się ponownie.' }
    case 403:
      return { kind: 'forbidden', message: message ?? 'Nie masz uprawnień do tej operacji.' }
    case 404:
      return { kind: 'missing', message: message ?? 'Nie ma takiego zasobu.' }
    case 409:
      return { kind: 'needsProposal', message: message ?? 'Ta przestrzeń wymaga przejścia przez kolejkę propozycji.' }
    case 422:
    case 400:
      return { kind: 'rejected', message: message ?? 'Żądanie zostało odrzucone.' }
    case 429:
      return { kind: 'throttled', message: message ?? 'Za dużo żądań. Zwolnij i spróbuj ponownie.' }
    default:
      return {
        kind: 'unavailable',
        message: message ?? 'Serwer nie odpowiedział. Spróbuj ponownie za chwilę.',
      }
  }
}

function messageFrom(body: unknown): string | null {
  if (typeof body === 'object' && body !== null && 'error' in body) {
    const value = (body as { error: unknown }).error

    if (typeof value === 'string' && value.trim() !== '') {
      return value
    }
  }

  return null
}
