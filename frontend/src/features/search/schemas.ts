import { z } from 'zod'

/**
 * Search results, as the API describes them.
 *
 * Two fields carry more weight than they look:
 *
 * `snippet` is a **list of parts**, not a string of markup. The backend knows exactly
 * which words matched — it did the tokenising — and sends that as structure so the
 * page can mark them without ever rendering HTML that came out of the database
 * (D-029's neighbourhood; the reasoning is in `Snippet` on the backend). Anything that
 * turns this back into a string and feeds it to `v-html` reintroduces the hole.
 *
 * `coverage` is the mode admitting what it could not look inside. It comes from the
 * server rather than living in a translation file here, because the limitation belongs
 * to the data and will change the day the palace grows a lexical mode.
 */

export const searchModeSchema = z.enum(['semantic', 'lexical'])

export const snippetPartSchema = z.object({
  text: z.string(),
  match: z.boolean(),
})

export const searchHitSchema = z.object({
  space: z.string().min(1),
  kind: z.enum(['note', 'document', 'diary', 'kg_fact', 'transcript']),
  title: z.string(),
  snippet: z.array(snippetPartSchema),
  /** Comparable only against other results of the same mode — the scales differ. */
  score: z.number().nullable(),
  weak: z.boolean(),
  byAi: z.boolean(),
  verified: z.boolean(),
  /** Absent until the worker has filed the content in the palace — seconds, usually. */
  drawer: z.string().nullable(),
  /** Only a document has a page of its own; without this there is nowhere to link. */
  documentSlug: z.string().nullable(),
  at: z.string().nullable(),
})

export const searchCoverageSchema = z.object({
  fullText: z.boolean(),
  note: z.string(),
})

export const searchAnswerSchema = z.object({
  /** Echoed by the server, so an empty result names what was actually searched for. */
  query: z.string(),
  mode: searchModeSchema,
  results: z.array(searchHitSchema),
  weakResults: z.array(searchHitSchema),
  count: z.number(),
  coverage: searchCoverageSchema,
})

export type SearchMode = z.infer<typeof searchModeSchema>
export type SnippetPart = z.infer<typeof snippetPartSchema>
export type SearchHit = z.infer<typeof searchHitSchema>
export type SearchAnswer = z.infer<typeof searchAnswerSchema>

/** The classes of knowledge, labelled for people rather than for the database. */
export const kindLabels: Record<SearchHit['kind'], string> = {
  document: 'Dokument',
  note: 'Notatka',
  diary: 'Dziennik',
  transcript: 'Transkrypt',
  kg_fact: 'Fakt',
}
