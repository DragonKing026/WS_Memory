import { z } from 'zod'

import { pageMetaSchema } from './listing'

/**
 * The audit log.
 *
 * `actorKind` is the field the whole screen is built around. "Who did this" is the only
 * reason anybody opens an audit log, and in this installation the answer has three
 * genuinely different meanings: a person signed in, an agent acting with somebody's
 * token, or the installation itself. Collapsing them — showing only a name — would hide
 * precisely the thing that is being looked for, since an agent's rows carry the name of
 * the person whose token it used.
 *
 * A strict enum, not a permissive string. If a fourth kind ever appears, the screen must
 * fail loudly rather than quietly file it under "system": a mislabelled actor in an audit
 * log is worse than no audit log, because it is believed.
 */
export const auditActorKindSchema = z.enum(['czlowiek', 'agent', 'system'])

export const auditEntrySchema = z.object({
  id: z.string().min(1),
  /** Free-form on purpose: the vocabulary (`search`, `doc_write`, `login`, …) grows with
   *  the backend, and the list of what actually occurs comes back in `actions`. */
  action: z.string(),
  actor: z.string(),
  actorKind: auditActorKindSchema,
  /** Null for actions that belong to no space — signing in, issuing a token. */
  space: z.string().nullable(),
  /**
   * Whatever the action was about, verbatim. Kept as an unopened object and shown as
   * JSON: this is the part of a row nobody can predict the shape of, and a field-by-field
   * rendering would silently drop the one key that mattered in an incident.
   *
   * Nullable as well as possibly empty — an action with no object may arrive either way,
   * and both mean "nothing to expand".
   */
  target: z.record(z.string(), z.unknown()).nullable(),
  /** Null when the request had no usable address — console commands, internal jobs. */
  ip: z.string().nullable(),
  createdAt: z.string(),
})

export const auditListSchema = pageMetaSchema.extend({
  entries: z.array(auditEntrySchema),
  /**
   * The vocabulary of actions, for the filter.
   *
   * It comes from the server because only the server knows what has actually been
   * recorded; a hard-coded list in the interface would offer filters that match nothing
   * and omit the action somebody is looking for.
   */
  actions: z.array(z.string()),
})

export type AuditActorKind = z.infer<typeof auditActorKindSchema>
export type AuditEntry = z.infer<typeof auditEntrySchema>
export type AuditList = z.infer<typeof auditListSchema>
