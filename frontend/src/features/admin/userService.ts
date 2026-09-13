import { useApi } from '@/api/client'
import { parseOrExplain } from '@/features/auth/schemas'

import { withPaging } from './listing'
import { adminUserAnswerSchema, adminUserListSchema, type AdminUser, type AdminUserList } from './userSchemas'

/**
 * Accounts, from the interface's side.
 *
 * Thin on purpose, like the other services: the screen has no business knowing that
 * changing a role is a POST to a Polish path, or that the answer may or may not be
 * wrapped in an envelope.
 */
export const adminUserService = {
  async list(query: string, offset = 0): Promise<AdminUserList> {
    const params = withPaging(new URLSearchParams(), offset)
    const trimmed = query.trim()

    if (trimmed !== '') {
      params.set('q', trimmed)
    }

    const answer = await useApi().get<unknown>(`/admin/users?${params.toString()}`)

    return parseOrExplain(adminUserListSchema, answer, 'listę użytkowników')
  },

  /**
   * Grants or takes away the global administrator role.
   *
   * The new value is sent, never a toggle instruction. Two people looking at the same
   * stale list would otherwise flip each other's change; with an explicit `admin` the
   * second request simply asks for the state that is already there.
   */
  async setGlobalRole(id: string, admin: boolean): Promise<AdminUser> {
    const answer = await useApi().post<unknown>(
      `/admin/users/${encodeURIComponent(id)}/rola-globalna`,
      { admin },
    )

    return unwrap(parseOrExplain(adminUserAnswerSchema, answer, 'zmianę roli'))
  },

  /** Switches the account on or off. Off also means every agent token of that person
   *  stops working, which is why the screen asks first. */
  async setActivity(id: string, active: boolean): Promise<AdminUser> {
    const answer = await useApi().post<unknown>(
      `/admin/users/${encodeURIComponent(id)}/aktywnosc`,
      { active },
    )

    return unwrap(parseOrExplain(adminUserAnswerSchema, answer, 'zmianę aktywności'))
  },
}

function unwrap(parsed: { user: AdminUser } | AdminUser): AdminUser {
  return 'user' in parsed ? parsed.user : parsed
}
