import { z } from 'zod'

/**
 * The administrator's view of the dependencies this installation runs on.
 *
 * There is one dependency that matters today — MemPalace — but the API answers with a
 * list, and so does this schema. The reason is the pinning itself: every dependency
 * whose version is frozen in `.env` has the same problem (nobody learns that something
 * new came out), so the second entry is a matter of when, not if.
 *
 * Nullability here is not defensive padding. Each `null` stands for a state the screen
 * has to tell apart from the others, and collapsing any of them into a default would
 * make the panel lie:
 *
 * - `installed` is null when MCP `initialize` could not be reached, i.e. we do not
 *   know what is actually running — which is different from knowing it is old;
 * - `latest` and `checkedAt` are null before the first successful check;
 * - `checkProblem` is the reason the last check failed. `checkProblem !== null`
 *   together with `updateAvailable === false` must never be read as "nothing new":
 *   it means "we did not manage to ask" (TODO-015, completion criteria).
 */

/**
 * How far an update request has got.
 *
 * The backend writes the request to the database and a host-side agent picks it up
 * (D: no container is given the Docker socket), so `pending` — written, not yet
 * claimed — is a state a reader will genuinely see, and it is not the same as
 * `running`. Both terminal states carry a log; `failed` is the one worth reading.
 */
export const dependencyUpdateStatusSchema = z.enum(['pending', 'running', 'succeeded', 'failed'])

export const dependencyUpdateSchema = z.object({
  id: z.string().min(1),
  status: dependencyUpdateStatusSchema,
  fromVersion: z.string(),
  toVersion: z.string(),
  /** Display name of whoever ordered it — an update is an act, and acts have authors. */
  requestedBy: z.string(),
  requestedAt: z.string(),
  /** Null while the request has not finished. */
  finishedAt: z.string().nullable(),
  /**
   * Output of the whole run: backup, rebuild, restart, semantic test. Null until the
   * agent has something to report. The semantic test is the reason this is shown at
   * all — a drop in search relevance is silent (D-003), so the only trace of it is here.
   */
  log: z.string().nullable(),
})

export const dependencySchema = z.object({
  name: z.string().min(1),
  label: z.string(),
  /** What MCP `initialize` says is answering; null when it could not be asked. */
  installed: z.string().nullable(),
  /**
   * What `.env` asked for.
   *
   * Nullable, although the contract does not list it among the nullable fields. The
   * backend's own record models it as an optional version (`DependencyRecord::$pinned`
   * is `?Version`), so a deployment with the variable unset would serialise a null
   * here — and a schema that refused it would turn a missing environment variable into
   * a panel that will not render at all. Shown as "nie wiadomo", and the `.env`-versus-
   * image comparison simply says nothing when either side is unknown.
   */
  pinned: z.string().nullable(),
  latest: z.string().nullable(),
  /**
   * Decided by the backend, not here. Version order is arithmetic per component
   * (`3.10.0` is newer than `3.9.0`) and there is one `Version` value object with
   * tests behind it; a second comparison written in TypeScript would be a second
   * chance to get that wrong.
   */
  updateAvailable: z.boolean(),
  checkedAt: z.string().nullable(),
  checkProblem: z.string().nullable(),
  pendingUpdate: dependencyUpdateSchema.nullable(),
  history: z.array(dependencyUpdateSchema),
})

/**
 * The host-side agent that actually carries updates out.
 *
 * `installed` is what the deployment did; `healthy` is whether it has reported a pulse
 * recently. They differ in the case worth catching: an agent installed months ago
 * whose timer has been failing since. The screen keys the "no point offering a button"
 * decision off `healthy`, because that is the one that answers "will anything happen
 * if I click".
 *
 * `lastHeartbeat` is nullable although the contract does not spell it out: an agent
 * that was never installed has never sent a pulse, so there is nothing to send.
 */
export const updaterStateSchema = z.object({
  installed: z.boolean(),
  lastHeartbeat: z.string().nullable(),
  healthy: z.boolean(),
})

export const dependencyOverviewSchema = z.object({
  dependencies: z.array(dependencySchema),
  updater: updaterStateSchema,
})

/**
 * What the backend answers with for one dependency — both `check` and `update`.
 *
 * This is the shape the finished backend actually sends, and the first branch of the
 * union below. The remaining branches are earlier readings of the same prose contract
 * ("as above, for one dependency", "202 with `pendingUpdate`"), which could each be
 * read two ways.
 *
 * They are kept rather than deleted, and the reason is worth stating: the panel and
 * the backend were written at the same time against a contract in prose, and the two
 * sides read it differently — the backend shipped `{dependency, updater}` while this
 * file expected a bare dependency. Accepting every plausible reading turns a class of
 * screen-killing Zod errors into a non-event, and costs one union branch.
 */
export const dependencySingleAnswerSchema = z.object({
  dependency: dependencySchema,
  updater: updaterStateSchema,
})

export const dependencyCheckAnswerSchema = z.union([
  dependencySingleAnswerSchema,
  dependencyOverviewSchema,
  dependencySchema,
])

export const updateOrderedAnswerSchema = z.union([
  dependencySingleAnswerSchema,
  z.object({ pendingUpdate: dependencyUpdateSchema }),
  dependencyUpdateSchema,
])

export type DependencyUpdateStatus = z.infer<typeof dependencyUpdateStatusSchema>
export type DependencyUpdate = z.infer<typeof dependencyUpdateSchema>
export type Dependency = z.infer<typeof dependencySchema>
export type UpdaterState = z.infer<typeof updaterStateSchema>
export type DependencyOverview = z.infer<typeof dependencyOverviewSchema>
