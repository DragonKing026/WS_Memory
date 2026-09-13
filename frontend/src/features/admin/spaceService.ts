import { useApi } from '@/api/client'
import { parseOrExplain } from '@/features/auth/schemas'

import { PAGE_SIZE, withPaging } from './listing'
import {
  adminSpaceListSchema,
  spaceMemberAnswerSchema,
  spaceMemberListSchema,
  type AdminSpaceList,
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
