import { z } from 'zod'

import { pageMetaSchema } from './listing'

/**
 * Invitations — the only way an account comes into being here (there is no open
 * registration).
 *
 * The statuses are Polish because the backend's are: `oczekuje`, `przyjete`, `wygasle`.
 * Not translated on the way in, deliberately — a mapping table between two vocabularies
 * for the same three states is a thing to keep in sync for no gain, and the value also
 * travels in `error` messages, where a reader would otherwise see one word on screen and
 * another in a refusal.
 */
export const invitationStatusSchema = z.enum(['oczekuje', 'przyjete', 'wygasle'])

export const adminInvitationSchema = z.object({
  id: z.string().min(1),
  email: z.string(),
  /** Display name of whoever issued it. An invitation is an act with an author, and it
   *  is the only trace of who let a person in. */
  invitedBy: z.string(),
  grantsGlobalAdmin: z.boolean(),
  status: invitationStatusSchema,
  createdAt: z.string(),
  expiresAt: z.string(),
  /** Null until somebody uses the link — which is exactly what `status` already says,
   *  but this is the timestamp, and the list is read for dates. */
  acceptedAt: z.string().nullable(),
})

export const adminInvitationListSchema = pageMetaSchema.extend({
  invitations: z.array(adminInvitationSchema),
})

/**
 * The answer to issuing one: the invitation, and the link that is only ever shown now.
 *
 * `link` is nullable although the contract does not say so. The reason is the order of
 * events: by the time this answer arrives the invitation **exists**. Refusing the whole
 * payload over a missing link would hide a created invitation behind a parse error, and
 * the administrator would issue a second one for the same person. A null instead lets
 * the screen say the one true thing — "it was created, the link did not come back,
 * unieważnij je i wystaw ponownie".
 */
export const issuedInvitationSchema = z.object({
  invitation: adminInvitationSchema,
  link: z.string().min(1).nullable(),
})

export type InvitationStatus = z.infer<typeof invitationStatusSchema>
export type AdminInvitation = z.infer<typeof adminInvitationSchema>
export type AdminInvitationList = z.infer<typeof adminInvitationListSchema>
export type IssuedInvitation = z.infer<typeof issuedInvitationSchema>
