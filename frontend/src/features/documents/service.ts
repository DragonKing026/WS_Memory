import { useApi } from '@/api/client'
import { parseOrExplain } from '@/features/auth/schemas'

import {
  documentListSchema,
  documentSchema,
  type DocumentDetail,
  type DocumentList,
} from './schemas'

/** A slug may contain slashes (`umowy/najem`), so each segment is encoded separately —
 *  encoding the whole thing would turn the path into one literal name. */
function encodeSlug(slug: string): string {
  return slug.split('/').map(encodeURIComponent).join('/')
}

export const documentService = {
  async list(space: string, offset = 0): Promise<DocumentList> {
    // The listing is paged server-side and the page size is the server's business:
    // asking for "everything" is what made this endpoint fall over on a space with
    // ten thousand documents.
    const params = new URLSearchParams()
    if (offset > 0) {
      params.set('offset', String(offset))
    }

    const query = params.toString()
    const answer = await useApi().get<unknown>(
      `/spaces/${encodeURIComponent(space)}/documents${query === '' ? '' : `?${query}`}`,
    )

    return parseOrExplain(documentListSchema, answer, 'lista dokumentów')
  },

  async read(space: string, slug: string): Promise<DocumentDetail> {
    const answer = await useApi().get<unknown>(
      `/spaces/${encodeURIComponent(space)}/documents/${encodeSlug(slug)}`,
    )

    return parseOrExplain(documentSchema, answer, 'dokument')
  },
}
