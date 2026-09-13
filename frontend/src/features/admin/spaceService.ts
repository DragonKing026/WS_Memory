import { useApi } from '@/api/client'
import { parseOrExplain } from '@/features/auth/schemas'

import { PAGE_SIZE, withPaging } from './listing'
import {
  adminSpaceListSchema,
  spaceGrantSchema,
  spaceMemberAnswerSchema,
  spaceMemberListSchema,
  type AdminSpaceList,
  type SpaceGrant,
  type SpaceMember,
  type SpaceMemberRole,
} from './spaceSchemas'

export const adminSpaceService = {
  /**
   * The installation's spaces — every one of them, not the reader's memberships.
   *
   * `limit` is an argument because two screens need different amounts: the spaces list
   * pages like the others, while the audit filter needs the whole catalogue at once to
   * offer it as a choice.
   */
  async list(offset = 0, limit = PAGE_SIZE): Promise<AdminSpaceList> {
    const params = withPaging(new URLSearchParams(), offset)
    params.set('limit', String(limit))

    const answer = await useApi().get<unknown>(`/admin/spaces?${params.toString()}`)

    return parseOrExplain(adminSpaceListSchema, answer, 'listę przestrzeni')
  },

  async members(slug: string): Promise<SpaceMember[]> {
    const answer = await useApi().get<unknown>(
      `/admin/spaces/${encodeURIComponent(slug)}/members`,
    )

    return parseOrExplain(spaceMemberListSchema, answer, 'członków przestrzeni').members
  },

  /**
   * Gives somebody a role in a space — the only operation here that widens a reach.
   *
   * Two things about it are unlike the rest of this file. It goes to the product's own
   * `/spaces/{slug}/members`, not to an `/admin/…` route, because granting a role is what
   * a space administrator does whether or not they administer the installation. And its
   * subject is an **e-mail address**, not a user id: the person being let in is by
   * definition not on the membership list yet, so there is no row to point at.
   *
   * It upserts. An address that is already a member has its role changed instead, which
   * makes a repeated grant harmless rather than a conflict — and means the caller cannot
   * tell from the answer whether anybody new appeared. Re-reading the membership is the
   * only way to know, and it is what the screen does.
   */
  async grantAccess(slug: string, email: string, role: SpaceMemberRole): Promise<SpaceGrant> {
    const answer = await useApi().post<unknown>(`/spaces/${encodeURIComponent(slug)}/members`, {
      email: email.trim(),
      role,
    })

    return parseOrExplain(spaceGrantSchema, answer, 'nadanie dostępu do przestrzeni')
  },

  /**
   * Changes one person's role in one space.
   *
   * Returns the member as the backend now sees them, whichever of the three plausible
   * shapes it answered with. When it answers with the whole list, the one asked about is
   * picked out by id rather than by position — a list ordered differently would otherwise
   * put somebody else's role on the row that was clicked.
   */
  async setRole(slug: string, userId: string, role: SpaceMemberRole): Promise<SpaceMember> {
    const answer = await useApi().put<unknown>(
      `/admin/spaces/${encodeURIComponent(slug)}/members/${encodeURIComponent(userId)}`,
      { role },
    )
    const parsed = parseOrExplain(spaceMemberAnswerSchema, answer, 'zmianę roli w przestrzeni')

    if ('member' in parsed) {
      return parsed.member
    }

    if ('members' in parsed) {
      const found = parsed.members.find((member) => member.userId === userId)

      if (found === undefined) {
        throw new Error('Backend przyjął zmianę roli, ale nie zwrócił tej osoby.')
      }

      return found
    }

    return parsed
  },

  async removeMember(slug: string, userId: string): Promise<void> {
    await useApi().delete<unknown>(
      `/admin/spaces/${encodeURIComponent(slug)}/members/${encodeURIComponent(userId)}`,
    )
  },
}
