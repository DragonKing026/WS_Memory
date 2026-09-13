import { z } from 'zod'

/** Documents, as the API describes them. */

export const documentRevisionSchema = z.object({
  number: z.number(),
  title: z.string(),
  byAi: z.boolean(),
  authorUserId: z.string().nullable(),
  authorAgentTokenId: z.string().nullable(),
  changeNote: z.string().nullable(),
  createdAt: z.string(),
})

export const documentSchema = z.object({
  slug: z.string().min(1),
  title: z.string(),
  space: z.string().min(1),
  status: z.enum(['draft', 'published']),
  currentRevision: z.number().nullable(),
  /** Who wrote it and whether anybody vouched for it — two different questions. */
  authoredByAi: z.boolean(),
  verified: z.boolean(),
  verifiedBy: z.string().nullable(),
  verifiedAt: z.string().nullable(),
  archived: z.boolean(),
  updatedAt: z.string(),
  revision: documentRevisionSchema,
  content: z.string(),
})

export const documentListItemSchema = z.object({
  slug: z.string().min(1),
  title: z.string(),
  space: z.string().min(1),
  status: z.enum(['draft', 'published']),
  currentRevision: z.number().nullable(),
  authoredByAi: z.boolean(),
  verified: z.boolean(),
  verifiedBy: z.string().nullable(),
  verifiedAt: z.string().nullable(),
  archived: z.boolean(),
  updatedAt: z.string(),
})

export const documentListSchema = z.object({
  documents: z.array(documentListItemSchema),
  /** The size of this page, not of the space — see `hasMore`. */
  count: z.number(),
  limit: z.number(),
  offset: z.number(),
  hasMore: z.boolean(),
})

export const documentHistorySchema = z.object({
  slug: z.string().min(1),
  currentRevision: z.number().nullable(),
  revisions: z.array(
    documentRevisionSchema.extend({
      /** Resolved server-side: a revision stores raw ids, and a column of UUIDs
       *  answers "who wrote this" with "no idea". */
      authorName: z.string(),
    }),
  ),
})

export const diffLineSchema = z.object({
  type: z.enum(['kept', 'added', 'removed']),
  line: z.string(),
  from: z.number().nullable(),
  to: z.number().nullable(),
})

export const revisionDiffSchema = z.object({
  from: z.number(),
  to: z.number(),
  identical: z.boolean(),
  added: z.number(),
  removed: z.number(),
  lines: z.array(diffLineSchema),
})

export type DocumentDetail = z.infer<typeof documentSchema>
export type DocumentHistory = z.infer<typeof documentHistorySchema>
export type HistoryRevision = DocumentHistory['revisions'][number]
export type RevisionDiff = z.infer<typeof revisionDiffSchema>
export type DiffLine = z.infer<typeof diffLineSchema>
export type DocumentListItem = z.infer<typeof documentListItemSchema>
export type DocumentList = z.infer<typeof documentListSchema>
