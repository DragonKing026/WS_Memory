import { z } from 'zod'

/**
 * The shape of what the API sends, checked at the boundary.
 *
 * Every response passes through one of these before it reaches a store. The reason is
 * in docs/07: when the backend changes a field name, a test here fails immediately
 * instead of producing `undefined` three screens away, where the cause is no longer
 * visible. That is worth the few lines even for responses we control both ends of —
 * especially for those, because both ends move.
 */

export const spaceMembershipSchema = z.object({
  slug: z.string().min(1),
  name: z.string(),
  /** null when the backend knows a membership but no role — treated as no access. */
  role: z.enum(['reader', 'writer', 'admin']).nullable(),
  isPrivate: z.boolean(),
  requiresProposal: z.boolean(),
})

export const currentUserSchema = z.object({
  id: z.string().min(1),
  email: z.string(),
  displayName: z.string(),
  isGlobalAdmin: z.boolean(),
  spaces: z.array(spaceMembershipSchema),
})

export const signInResponseSchema = z.object({
  token: z.string().min(1),
})

export const acceptInvitationResponseSchema = z.object({
  id: z.string().min(1),
  email: z.string(),
  displayName: z.string(),
})

export type SpaceMembership = z.infer<typeof spaceMembershipSchema>
export type CurrentUser = z.infer<typeof currentUserSchema>

/**
 * Parses, or fails with a message that says which contract broke.
 *
 * Zod's own error is precise and unreadable to anybody who is not looking at the
 * schema. The name in the message is what turns "expected string, received undefined"
 * into something actionable in a bug report.
 */
export function parseOrExplain<T>(schema: z.ZodType<T>, value: unknown, what: string): T {
  const result = schema.safeParse(value)

  if (!result.success) {
    throw new Error(
      `Odpowiedź API dla „${what}” nie zgadza się z kontraktem: ${result.error.issues
        .map((issue) => `${issue.path.join('.') || '(korzeń)'} — ${issue.message}`)
        .join('; ')}`,
    )
  }

  return result.data
}
