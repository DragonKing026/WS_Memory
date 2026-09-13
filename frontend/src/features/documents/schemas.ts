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
})

export type DocumentDetail = z.infer<typeof documentSchema>
export type DocumentListItem = z.infer<typeof documentListItemSchema>
