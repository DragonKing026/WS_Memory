import type { Dependency, DependencyUpdate, UpdaterState } from './schemas'

/**
 * Reading a dependency's raw fields as the one situation a person is actually in.
 *
 * This is the whole point of the screen, so it lives in a pure function rather than in
 * a pile of `v-if`s. Two reasons, and the second is the real one:
 *
 * - it can be tested without mounting anything, which means every branch is covered
 *   for the price of a plain object;
 * - the branches are not independent. "No update available" and "we failed to ask
 *   whether there is one" arrive as almost the same payload (`updateAvailable: false`),
 *   and a template that checks them in the wrong order produces a panel saying
 *   "wszystko aktualne" while PyPI has been unreachable for a fortnight. That is not a
 *   cosmetic bug: it is the panel confidently reporting the opposite of the truth, and
 *   it is exactly the failure this feature exists to prevent (TODO-015).
 *
 * So the ordering below is deliberate and is the thing the tests pin down.
 */

/**
 * The mutually exclusive situations, in the order they are decided.
 *
 * 1. `updateRunning` — a request is written or in flight. It outranks everything
 *    because nothing else a reader could do is useful yet, and offering a second
 *    button would earn them a 409.
 * 2. `checkFailed` — the version information on screen is stale and we know it.
 *    Decided **before** the up-to-date / update-available pair, since those two are
 *    conclusions drawn from data we just admitted we could not refresh.
 * 3. `updaterMissing` — there is no agent to carry an update out. Decided before
 *    `updateAvailable` so that no button is offered that cannot do anything; the
 *    newer version is still reported (see `newerVersion`), because "there is 3.9.0
 *    and you cannot install it yet" is useful, and hiding it would be a second lie.
 * 4. `updateAvailable` — a newer version exists and can be ordered.
 * 5. `upToDate` — checked successfully, nothing newer.
 * 6. `neverChecked` — no successful check yet and no recorded failure either. The
 *    state right after the migration runs, before the scheduler's first pass.
 */
export type DependencyVerdictKind =
  | 'updateRunning'
  | 'checkFailed'
  | 'updaterMissing'
  | 'updateAvailable'
  | 'upToDate'
  | 'neverChecked'

export interface VersionDrift {
  installed: string
  pinned: string
}

export interface DependencyVerdict {
  kind: DependencyVerdictKind
  /**
   * The version the update button should ask for — non-null only when an update can
   * actually be ordered. The screen uses it both for the label ("Aktualizuj do 3.9.0")
   * and for the request body, so there is no way for the two to disagree.
   */
  offeredVersion: string | null
  /**
   * A newer version we know about, whether or not it can be installed right now.
   * Set for `updateAvailable`, and also for `updaterMissing` — so the reader learns
   * both facts at once instead of only the one that blocks them.
   */
  newerVersion: string | null
  /**
   * Why the last check failed, carried regardless of `kind`. A missing agent takes
   * priority on screen, but it must not swallow the fact that the versions shown
   * underneath it are unverified.
   */
  checkProblem: string | null
  /**
   * When the shown versions were last actually confirmed — `null` when never.
   *
   * `checkedAt` is the only timestamp the API sends, and it is the time of the last
   * check that SUCCEEDED — a failed attempt fills `checkProblem` and leaves this
   * timestamp, and `latest` with it, where the last good answer put them. That is what
   * makes "nie udało się sprawdzić, ostatnio potwierdzone <data>" honest rather than
   * merely apologetic. If a later change ever stamped failed attempts here too, this
   * is the line where the panel would start to mislead.
   */
  lastSuccessfulCheckAt: string | null
  /**
   * `.env` asks for one version and another one is answering: somebody edited the
   * variable and did not rebuild the image. Reported alongside every `kind` rather
   * than as one of them, because it is orthogonal — it can be true while an update is
   * running, while the check is broken, or while everything else looks calm.
   */
  drift: VersionDrift | null
  /** The request whose progress is being shown, when `kind` is `updateRunning`. */
  running: DependencyUpdate | null
  /**
   * The most recently finished request, when the API still reports it in
   * `pendingUpdate`. Its log is what tells a reader whether the semantic test passed,
   * which is the only way a silent drop in search relevance ever surfaces (D-003).
   */
  justFinished: DependencyUpdate | null
}

/** A request that has not reached a terminal state. */
export function isInFlight(update: DependencyUpdate | null): boolean {
  return update !== null && (update.status === 'pending' || update.status === 'running')
}

/**
 * Decides what the screen shows for one dependency.
 *
 * Pure: same input, same answer, no clock and no network. The `updater` is a separate
 * argument because it is installation-wide — one agent serves every dependency — and
 * folding it into the dependency object would suggest otherwise.
 */
export function verdictFor(dependency: Dependency, updater: UpdaterState): DependencyVerdict {
  const pending = dependency.pendingUpdate
  const running = isInFlight(pending) ? pending : null
  const justFinished = pending !== null && running === null ? pending : null

  // `latest` is checked as well as the boolean: a backend that said
  // `updateAvailable: true` with no version to offer would otherwise produce a button
  // labelled "Aktualizuj do null".
  const newer =
    dependency.updateAvailable && dependency.latest !== null ? dependency.latest : null

  const base = {
    newerVersion: newer,
    checkProblem: dependency.checkProblem,
    lastSuccessfulCheckAt: dependency.checkedAt,
    drift: driftOf(dependency),
    running,
    justFinished,
  }

  if (running !== null) {
    return { ...base, kind: 'updateRunning', offeredVersion: null }
  }

  if (dependency.checkProblem !== null) {
    // Deliberately ahead of the two conclusions below. We do not know what is out
    // there, so we say so instead of guessing in the reassuring direction.
    return { ...base, kind: 'checkFailed', offeredVersion: null }
  }

  if (!updater.healthy) {
    // `newerVersion` survives in `base`, so the screen can report the available
    // version and, in the same breath, that nothing can install it yet.
    return { ...base, kind: 'updaterMissing', offeredVersion: null }
  }

  if (newer !== null) {
    return { ...base, kind: 'updateAvailable', offeredVersion: newer }
  }

  if (dependency.checkedAt === null) {
    // Never asked successfully, and no failure recorded either — the scheduler has
    // simply not run yet. Saying "masz najnowszą" here would be inventing a fact.
    return { ...base, kind: 'neverChecked', offeredVersion: null }
  }

  return { ...base, kind: 'upToDate', offeredVersion: null }
}

/**
 * The quiet fifth case: the running version is not the pinned one.
 *
 * Only reported when both sides are known and differ. An unknown side is not a drift
 * but a different problem: `installed` is null when MCP `initialize` could not be
 * reached, `pinned` when the environment variable is not set. Calling either a mismatch
 * would send somebody to edit `.env` over nothing.
 */
function driftOf(dependency: Dependency): VersionDrift | null {
  const { installed, pinned } = dependency

  if (installed === null || pinned === null || installed === pinned) {
    return null
  }

  return { installed, pinned }
}
