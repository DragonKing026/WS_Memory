import { useApi } from '@/api/client'
import { parseOrExplain } from '@/features/auth/schemas'

import {
  documentListSchema,
  documentSchema,
  type DocumentDetail,
  type DocumentListItem,
} from './schemas'

/** A slug may contain slashes (`umowy/najem`), so each segment is encoded separately —
 *  encoding the whole thing would turn the path into one literal name. */
function encodeSlug(slug: string): string {
  return slug.split('/').map(encodeURIComponent).join('/')
}

export const documentService = {
  async list(space: string): Promise<DocumentListItem[]> {
    const answer = await useApi().get<unknown>(
      `/spaces/${encodeURIComponent(space)}/documents`,
    )

    return parseOrExplain(documentListSchema, answer, 'lista dokumentów').documents
  },

  async read(space: string, slug: string): Promise<DocumentDetail> {
    const answer = await useApi().get<unknown>(
      `/spaces/${encodeURIComponent(space)}/documents/${encodeSlug(slug)}`,
    )

    return parseOrExplain(documentSchema, answer, 'dokument')
  },
}
