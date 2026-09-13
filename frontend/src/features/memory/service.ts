import { useApi } from '@/api/client'
import { parseOrExplain } from '@/features/auth/schemas'

import { memoryListSchema, type MemoryList } from './schemas'

export interface MemoryFilters {
  spaces: string[]
  kind: string | null
  since: string | null
  before: string | null
}

export const memoryService = {
  async browse(filters: MemoryFilters, offset = 0): Promise<MemoryList> {
    const params = new URLSearchParams()

    if (filters.kind !== null) {
      params.set('kind', filters.kind)
    }
    if (filters.since !== null) {
      params.set('since', filters.since)
    }
    if (filters.before !== null) {
      params.set('before', filters.before)
    }
    if (offset > 0) {
      params.set('offset', String(offset))
    }

    // Repeated rather than comma-joined: a slug is free-form enough that a comma in
    // one would silently split it into two spaces that do not exist.
    for (const space of filters.spaces) {
      params.append('spaces[]', space)
    }

    const query = params.toString()
    const answer = await useApi().get<unknown>(`/memory${query === '' ? '' : `?${query}`}`)

    return parseOrExplain(memoryListSchema, answer, 'pamięć')
  },
}
