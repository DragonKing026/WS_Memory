import { z } from 'zod'

/**
 * Raw memory entries, as the API describes them.
 *
 * Separate from the search schemas on purpose: a listing carries no relevance and no
 * mode, because it answers no question. Sharing one type would mean inventing those
 * fields for rows that have neither.
 */

export const memoryEntrySchema = z.object({
  drawer: z.string().min(1),
  space: z.string().min(1),
  kind: z.enum(['note', 'document', 'diary', 'kg_fact', 'transcript']),
  title: z.string(),
  tags: z.array(z.string()),
  byAi: z.boolean(),
  verified: z.boolean(),
  /** Only a document has a page of its own; raw memory does not. */
  documentSlug: z.string().nullable(),
  filedAt: z.string(),
})

export const memoryListSchema = z.object({
  entries: z.array(memoryEntrySchema),
  count: z.number(),
  limit: z.number(),
  offset: z.number(),
  hasMore: z.boolean(),
})

export type MemoryEntry = z.infer<typeof memoryEntrySchema>
export type MemoryList = z.infer<typeof memoryListSchema>
