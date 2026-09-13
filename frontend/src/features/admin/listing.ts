import { z } from 'zod'

/**
 * What the four administration listings have in common.
 *
 * Users, invitations, spaces and the audit log are four different questions, but they
 * are paged identically — `count`, `limit`, `offset`, `hasMore` — and every one of them
 * has a "show the next lot" button rather than numbered pages. Writing that envelope
 * out four times would mean four chances for one of them to read `count` as "rows on
 * this page" and quietly disagree with the other three.
 */
export const pageMetaSchema = z.object({
  /** Rows matching the query in total, **not** rows in this answer. */
  count: z.number(),
  limit: z.number(),
  offset: z.number(),
  hasMore: z.boolean(),
})

export type PageMeta = z.infer<typeof pageMetaSchema>

/**
 * How many rows one page asks for.
 *
 * Sent explicitly rather than left to the backend's default, because "show the next
 * lot" computes the offset from how many rows are already on screen. If the server
 * changed its default between two requests, that arithmetic would skip or repeat rows,
 * and nothing on screen would say so.
 */
export const PAGE_SIZE = 25

/** Adds the paging pair to a query the caller has already filled with its filters. */
export function withPaging(params: URLSearchParams, offset: number): URLSearchParams {
  params.set('limit', String(PAGE_SIZE))

  if (offset > 0) {
    params.set('offset', String(offset))
  }

  return params
}
