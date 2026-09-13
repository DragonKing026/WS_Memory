import { useApi } from '@/api/client'
import { parseOrExplain } from '@/features/auth/schemas'

import {
  adminInvitationListSchema,
  issuedInvitationSchema,
  type AdminInvitationList,
  type IssuedInvitation,
} from './invitationSchemas'
import { withPaging } from './listing'

export const adminInvitationService = {
  async list(offset = 0): Promise<AdminInvitationList> {
    const params = withPaging(new URLSearchParams(), offset)
    const answer = await useApi().get<unknown>(`/admin/invitations?${params.toString()}`)

    return parseOrExplain(adminInvitationListSchema, answer, 'listę zaproszeń')
  },

  /**
   * Issues one. The answer carries the link, and this is the only moment it exists in
   * readable form — the database keeps a hash of the token and nothing else.
   */
  async create(email: string, admin: boolean): Promise<IssuedInvitation> {
    const answer = await useApi().post<unknown>('/admin/invitations', {
      email: email.trim(),
      admin,
    })

    return parseOrExplain(issuedInvitationSchema, answer, 'wystawione zaproszenie')
  },

  /** Withdraws a pending invitation: the link stops working, the row stays in the list. */
  async revoke(id: string): Promise<void> {
    await useApi().delete<unknown>(`/admin/invitations/${encodeURIComponent(id)}`)
  },
}
