import { useApi } from '@/api/client'
import { parseOrExplain } from '@/features/auth/schemas'

import { withPaging } from './listing'
import {
  mailLogListSchema,
  mailPreviewSchema,
  mailTemplateListSchema,
  mailTemplateSavedSchema,
  testSendSchema,
  type MailLogList,
  type MailPreview,
  type MailLogEntry,
  type MailTemplate,
  type TestSend,
} from './mailSchemas'

/** What the journal screen is filtering by. Nullable rather than optional, as elsewhere:
 *  with `exactOptionalPropertyTypes` an absent key and an `undefined` one differ, and a
 *  filter built from form fields always has the latter. */
export interface MailLogFilters {
  status: MailLogEntry['status'] | null
  recipient: string | null
}

export const emptyMailLogFilters: MailLogFilters = {
  status: null,
  recipient: null,
}

export const adminMailService = {
  async templates(): Promise<MailTemplate[]> {
    const answer = await useApi().get<unknown>('/admin/mail-templates')

    return parseOrExplain(mailTemplateListSchema, answer, 'szablony maili').templates
  },

  async save(key: string, subject: string, body: string): Promise<MailTemplate> {
    const answer = await useApi().put<unknown>(`/admin/mail-templates/${encodeURIComponent(key)}`, {
      subject,
      body,
    })

    return parseOrExplain(mailTemplateSavedSchema, answer, 'zapisany szablon').template
  },

  /**
   * Renders wording that has not been saved.
   *
   * On the server, like every other rendering. A preview drawn in the browser would be a
   * second implementation of the one rule whose whole value is that there is only one —
   * and it would happily show a template the server is about to refuse.
   */
  async preview(key: string, subject: string, body: string): Promise<MailPreview> {
    const answer = await useApi().post<unknown>(
      `/admin/mail-templates/${encodeURIComponent(key)}/preview`,
      { subject, body },
    )

    return parseOrExplain(mailPreviewSchema, answer, 'podgląd maila').preview
  },

  /** Sends the saved wording to the administrator's own address, on sample values. */
  async testSend(key: string): Promise<TestSend> {
    const answer = await useApi().post<unknown>(
      `/admin/mail-templates/${encodeURIComponent(key)}/test`,
      {},
    )

    return parseOrExplain(testSendSchema, answer, 'wysyłkę próbną')
  },

  async log(filters: MailLogFilters, offset = 0): Promise<MailLogList> {
    const params = withPaging(new URLSearchParams(), offset)

    if (filters.status !== null) {
      params.set('status', filters.status)
    }

    if (filters.recipient !== null && filters.recipient.trim() !== '') {
      params.set('recipient', filters.recipient.trim())
    }

    const answer = await useApi().get<unknown>(`/admin/mail-log?${params.toString()}`)

    return parseOrExplain(mailLogListSchema, answer, 'dziennik maili')
  },
}
