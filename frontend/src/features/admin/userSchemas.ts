import { z } from 'zod'

import { pageMetaSchema } from './listing'

/**
 * Accounts, as the administration API describes them.
 *
 * The two counters are the reason this shape is worth having at all. The screen's real
 * question is not "who is on the list" but "what breaks if I turn this person off", and
 * the answer is `spaceCount` memberships and `tokenCount` agent tokens — deactivating
 * an account cuts every one of its agents at their next request.
 */
export const adminUserSchema = z.object({
  id: z.string().min(1),
  /**
   * A plain string, not `z.email()`. Nothing is being validated here — the address was
   * checked when the invitation was issued — and a schema stricter than what the
   * database already holds would blank out the very screen somebody opens to deal with
   * such an account.
   */
  email: z.string(),
  displayName: z.string(),
  isGlobalAdmin: z.boolean(),
  isActive: z.boolean(),
  createdAt: z.string(),
  /** Null for an account that has never signed in — the ordinary state of an accepted
   *  invitation nobody has used yet, and not the same as "long ago". */
  lastLoginAt: z.string().nullable(),
  spaceCount: z.number(),
  tokenCount: z.number(),
})

export const adminUserListSchema = pageMetaSchema.extend({
  users: z.array(adminUserSchema),
})

/**
 * The answer to a role or activity change.
 *
 * A union of the enveloped and the bare account, and the reason is a bruise: the
 * dependencies panel and its backend were written in parallel from a contract in prose
 * and read it differently — one sent `{dependency, updater}`, the other expected a bare
 * object, and nobody found out until the two were stitched together. "200 użytkownik"
 * has exactly the same two readings, and these endpoints do not exist yet. Accepting
 * both costs one union branch and removes a whole class of screen-killing parse errors.
 */
export const adminUserAnswerSchema = z.union([
  z.object({ user: adminUserSchema }),
  adminUserSchema,
])

export type AdminUser = z.infer<typeof adminUserSchema>
export type AdminUserList = z.infer<typeof adminUserListSchema>
