import { z } from 'zod'

import { pageMetaSchema } from './listing'

/**
 * The wording of a mail, and the closed list of what may appear in it.
 *
 * `placeholders` comes from the server, and that is the point of the editing screen
 * being usable at all. The list of places lives in one enum in the backend; a copy of
 * it here would go stale silently and the screen would then offer a place the server is
 * about to refuse, or hide one it accepts.
 *
 * `sensitive` is the shorter list that may not go in the subject, because subjects are
 * stored in the mail journal. The screen says so rather than leaving somebody to find
 * out from a 422.
 */
export const mailTemplateSchema = z.object({
  key: z.string().min(1),
  /** The template's name in Polish, from the backend: this screen keeps no dictionary. */
  label: z.string(),
  whenSent: z.string(),
  subject: z.string(),
  body: z.string(),
  placeholders: z.array(z.string()),
  sensitive: z.array(z.string()),
  sampleValues: z.record(z.string(), z.string()),
  /** Subject and body rendered on the sample values — never on a real token. */
  preview: z.object({ subject: z.string(), body: z.string() }),
  updatedAt: z.string(),
  /** Null on a template nobody has edited yet — the wording the migration seeded. */
  updatedByEmail: z.string().nullable(),
})

export const mailTemplateListSchema = z.object({
  templates: z.array(mailTemplateSchema),
})

export const mailTemplateSavedSchema = z.object({
  template: mailTemplateSchema,
})

export const mailPreviewSchema = z.object({
  preview: z.object({ subject: z.string(), body: z.string() }),
})

export const testSendSchema = z.object({
  mailLogId: z.string().min(1),
  /** The administrator's own address. The endpoint takes no recipient, deliberately. */
  recipient: z.string(),
  message: z.string(),
})

/**
 * A line of the mail journal.
 *
 * **There is no body field and there must never be one.** An invitation mail carries a
 * working token, so the journal keeps metadata only (D-038). A strict object rather
 * than a permissive one: were the backend ever to start sending a body, this schema
 * would fail loudly instead of quietly rendering a credential on an administration
 * screen.
 */
export const mailLogEntrySchema = z
  .object({
    id: z.string().min(1),
    recipient: z.string(),
    templateKey: z.string(),
    /** The template's Polish name, or its raw key for a mail this version no longer sends. */
    templateLabel: z.string(),
    subject: z.string(),
    status: z.enum(['queued', 'sent', 'failed']),
    /** „w kolejce" / „wysłany" / „nieudany" — the Polish word comes with the value. */
    statusLabel: z.string(),
    attempts: z.number(),
    /** The mail server's own sentence. Null while nothing has failed. */
    failureReason: z.string().nullable(),
    queuedAt: z.string(),
    lastAttemptAt: z.string().nullable(),
    sentAt: z.string().nullable(),
  })
  .strict()

export const mailLogListSchema = pageMetaSchema.extend({
  entries: z.array(mailLogEntrySchema),
  /** The vocabulary of states with their Polish names, for the filter. */
  statuses: z.array(z.object({ value: z.string(), label: z.string() })),
})

export type MailTemplate = z.infer<typeof mailTemplateSchema>
export type MailPreview = z.infer<typeof mailPreviewSchema>['preview']
export type TestSend = z.infer<typeof testSendSchema>
export type MailLogEntry = z.infer<typeof mailLogEntrySchema>
export type MailLogList = z.infer<typeof mailLogListSchema>
export type MailStatus = MailLogEntry['status']
