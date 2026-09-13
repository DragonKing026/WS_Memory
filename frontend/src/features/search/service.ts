import { useApi } from '@/api/client'
import { parseOrExplain } from '@/features/auth/schemas'

import { searchAnswerSchema, type SearchAnswer, type SearchMode } from './schemas'

export interface SearchCriteria {
  query: string
  mode: SearchMode
  /** Narrows to these spaces; empty means "wherever I may look". */
  spaces: string[]
  kind: string | null
  since: string | null
  before: string | null
  limit?: number
}

export const searchService = {
  async run(criteria: SearchCriteria, signal?: AbortSignal): Promise<SearchAnswer> {
    const params = new URLSearchParams()
    params.set('q', criteria.query)
    params.set('mode', criteria.mode)

    if (criteria.kind !== null) {
      params.set('kind', criteria.kind)
    }
    if (criteria.since !== null) {
      params.set('since', criteria.since)
    }
    if (criteria.before !== null) {
      params.set('before', criteria.before)
    }
    if (criteria.limit !== undefined) {
      params.set('limit', String(criteria.limit))
    }

    // Repeated rather than comma-joined: a slug is free-form enough that a comma in
    // one would silently split it into two spaces that do not exist.
    for (const space of criteria.spaces) {
      params.append('spaces[]', space)
    }

    // `{ signal: undefined }` is not the same as omitting it under
    // `exactOptionalPropertyTypes`, and the compiler is right to say so.
    const answer = await useApi().get<unknown>(
      `/search?${params.toString()}`,
      signal === undefined ? undefined : { signal },
    )

    return parseOrExplain(searchAnswerSchema, answer, 'wyniki wyszukiwania')
  },
}
