import { useApi } from '@/api/client'
import { parseOrExplain } from '@/features/auth/schemas'

import { auditListSchema, type AuditList } from './auditSchemas'
import { withPaging } from './listing'

/**
 * The filters the audit screen sends.
 *
 * Every field is nullable rather than optional, and that is not a stylistic choice:
 * with `exactOptionalPropertyTypes` an absent key and a key holding `undefined` are
 * different types, and a filter object assembled from form fields inevitably has the
 * latter. `null` means "not filtering by this" everywhere, once.
 */
export interface AuditFilters {
  action: string | null
  space: string | null
  actor: string | null
  since: string | null
  before: string | null
}

export const emptyAuditFilters: AuditFilters = {
  action: null,
  space: null,
  actor: null,
  since: null,
  before: null,
}

export const adminAuditService = {
  async list(filters: AuditFilters, offset = 0): Promise<AuditList> {
    const params = withPaging(new URLSearchParams(), offset)

    for (const [name, value] of Object.entries(filters)) {
      if (value !== null && value.trim() !== '') {
        params.set(name, value.trim())
      }
    }

    const answer = await useApi().get<unknown>(`/admin/audit?${params.toString()}`)

    return parseOrExplain(auditListSchema, answer, 'dziennik audytu')
  },
}
