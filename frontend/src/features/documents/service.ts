import { useApi } from '@/api/client'
import { parseOrExplain } from '@/features/auth/schemas'

import {
  documentHistorySchema,
  documentListSchema,
  documentSchema,
  revisionDiffSchema,
  type DocumentDetail,
  type DocumentHistory,
  type DocumentList,
  type RevisionDiff,
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

  async write(
    space: string,
    slug: string,
    payload: { title: string; content: string; changeNote: string },
  ): Promise<void> {
    await useApi().put<unknown>(
      `/spaces/${encodeURIComponent(space)}/documents/${encodeSlug(slug)}`,
      payload,
    )
  },

  async history(space: string, slug: string): Promise<DocumentHistory> {
    const answer = await useApi().get<unknown>(
      `/spaces/${encodeURIComponent(space)}/documents/${encodeSlug(slug)}/history`,
    )

    return parseOrExplain(documentHistorySchema, answer, 'historia dokumentu')
  },

  async diff(space: string, slug: string, from: number, to: number): Promise<RevisionDiff> {
    const answer = await useApi().get<unknown>(
      `/spaces/${encodeURIComponent(space)}/documents/${encodeSlug(slug)}/diff?from=${from}&to=${to}`,
    )

    return parseOrExplain(revisionDiffSchema, answer, 'porównanie rewizji')
  },

  async rollback(space: string, slug: string, revision: number): Promise<void> {
    await useApi().post<unknown>(
      `/spaces/${encodeURIComponent(space)}/documents/${encodeSlug(slug)}/rollback`,
      // `toRevision`, not `revision` — the API names it for what it means: the
      // revision to go back TO, not the one being created.
      { toRevision: revision },
    )
  },

  async verify(space: string, slug: string): Promise<void> {
    await useApi().post<unknown>(
      `/spaces/${encodeURIComponent(space)}/documents/${encodeSlug(slug)}/verify`,
    )
  },

  async read(space: string, slug: string): Promise<DocumentDetail> {
    const answer = await useApi().get<unknown>(
      `/spaces/${encodeURIComponent(space)}/documents/${encodeSlug(slug)}`,
    )

    return parseOrExplain(documentSchema, answer, 'dokument')
  },
}
