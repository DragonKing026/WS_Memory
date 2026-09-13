import { z } from 'zod'

import { pageMetaSchema } from './listing'

/**
 * Spaces and who is in them.
 *
 * `isPrivate` is the field that changes what this screen is allowed to offer. A private
 * space belongs to one person and is where transcripts of their conversations with
 * agents land by default (D-014); letting an administrator add a member to it would
 * quietly undo the promise that those conversations are theirs (D-016). The backend
 * refuses with 422, and the interface does not offer the action at all — see
 * `spaceState.ts`.
 */
export const spaceMemberRoleSchema = z.enum(['admin', 'writer', 'reader'])

export const adminSpaceSchema = z.object({
  slug: z.string().min(1),
  name: z.string(),
  /** Nullable, although the contract writes it plainly: a space created from the console
   *  or by accepting an invitation has no description, and an empty field is not a
   *  reason for the whole list to fail to parse. */
  description: z.string().nullable(),
  isPrivate: z.boolean(),
  requiresProposal: z.boolean(),
  /** The wing this space maps onto in the palace. Nullable for the same reason as the
   *  description — the mapping is a name, not a guarantee. */
  palaceWing: z.string().nullable(),
  memberCount: z.number(),
  documentCount: z.number(),
  createdAt: z.string(),
})

export const adminSpaceListSchema = pageMetaSchema.extend({
  spaces: z.array(adminSpaceSchema),
})

export const spaceMemberSchema = z.object({
  userId: z.string().min(1),
  displayName: z.string(),
  email: z.string(),
  role: spaceMemberRoleSchema,
  addedAt: z.string(),
  /** Null when nobody added them — the owner of a private space, or a membership created
   *  by the console before there was anyone to record. */
  addedBy: z.string().nullable(),
})

export const spaceMemberListSchema = z.object({
  members: z.array(spaceMemberSchema),
})

/**
 * What granting access answers with: an address and a role, not a membership row.
 *
 * Deliberately its own shape rather than `spaceMemberSchema`. The grant route takes an
 * e-mail address — the person it names may have no membership yet — and answers in the same
 * terms, so it cannot say who that address belongs to, when they were added or by whom.
 * Whoever calls it therefore re-reads the membership list to learn the rest.
 */
export const spaceGrantSchema = z.object({
  space: z.string(),
  member: z.string(),
  role: spaceMemberRoleSchema,
})

/** As with accounts: "200 member" reads two ways, and both are accepted rather than
 *  waiting for the stitching-together to discover which one was meant. */
export const spaceMemberAnswerSchema = z.union([
  z.object({ member: spaceMemberSchema }),
  spaceMemberSchema,
  spaceMemberListSchema,
])

export type SpaceMemberRole = z.infer<typeof spaceMemberRoleSchema>
export type AdminSpace = z.infer<typeof adminSpaceSchema>
export type AdminSpaceList = z.infer<typeof adminSpaceListSchema>
export type SpaceMember = z.infer<typeof spaceMemberSchema>
export type SpaceGrant = z.infer<typeof spaceGrantSchema>
