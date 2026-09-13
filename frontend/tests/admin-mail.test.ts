import axios from 'axios'
import MockAdapter from 'axios-mock-adapter'
import { beforeEach, describe, expect, it } from 'vitest'

import { ApiClient, setApi } from '@/api/client'
import {
  mailLogEntrySchema,
  mailLogListSchema,
  mailTemplateSchema,
  type MailLogEntry,
  type MailTemplate,
} from '@/features/admin/mailSchemas'
import { adminMailService, emptyMailLogFilters } from '@/features/admin/mailService'
import { describeAttempts, isEdited, placeHints, statusAppearance } from '@/features/admin/mailState'

/**
 * The two mail screens: the contract, and the promises the interface makes about it.
 *
 * The test worth keeping above the others is the one refusing a `body` field on a
 * journal row. An invitation mail carries a working token, so the journal keeps metadata
 * only (D-038) — and a schema that merely ignored an unexpected field would let a future
 * backend start sending one, with a credential then rendered on an administration
 * screen and nothing anywhere saying so.
 */
function template(overrides: Partial<MailTemplate> = {}): MailTemplate {
  return {
    key: 'invitation',
    label: 'Zaproszenie do bazy wiedzy',
    whenSent: 'Wysyłane w chwili wystawienia zaproszenia.',
    subject: 'Zaproszenie do bazy wiedzy „{{ instancja }}”',
    body: 'Wejdź na {{ link }}, {{ adres_email }}.',
    placeholders: ['zapraszajacy', 'instancja', 'link', 'wygasa', 'adres_email'],
    sensitive: ['link'],
    sampleValues: {
      zapraszajacy: 'Anna Przykładowa',
      instancja: 'Baza wiedzy',
      link: 'https://przyklad.example.com/zaproszenie/przykladowy-token',
      wygasa: '20 września 2026, 12:00',
      adres_email: 'ktos@example.com',
    },
    preview: { subject: 'Zaproszenie do bazy wiedzy „Baza wiedzy”', body: 'Wejdź na https://…' },
    updatedAt: '2026-09-13T20:00:00+00:00',
    updatedByEmail: null,
    ...overrides,
  }
}

function logEntry(overrides: Partial<MailLogEntry> = {}): MailLogEntry {
  return {
    id: '0199f0c0-0000-7000-8000-000000000010',
    recipient: 'nowy@web-systems.pl',
    templateKey: 'invitation',
    templateLabel: 'Zaproszenie do bazy wiedzy',
    subject: 'Zaproszenie do bazy wiedzy „Baza wiedzy”',
    status: 'queued',
    statusLabel: 'w kolejce',
    attempts: 0,
    failureReason: null,
    queuedAt: '2026-09-13T20:00:00+00:00',
    lastAttemptAt: null,
    sentAt: null,
    ...overrides,
  }
}

describe('schemat dziennika maili', () => {
  it('nie przyjmuje wiersza z treścią wiadomości', () => {
    // Mail z zaproszeniem niesie działający token. Schemat, który dodatkowe pole
    // po cichu pomija, pozwoliłby backendowi zacząć je przysyłać — a wtedy
    // poświadczenie wyświetla się w panelu i nikt tego nie zauważa.
    const withBody = { ...logEntry(), body: 'Cześć, wejdź na https://…/zaproszenie/abc' }

    expect(mailLogEntrySchema.safeParse(withBody).success).toBe(false)
  })

  it('nie przyjmuje nieznanego stanu', () => {
    expect(mailLogEntrySchema.safeParse(logEntry({ status: 'wyslano' as never })).success).toBe(
      false,
    )
  })

  it('przyjmuje wiersz nieudany z powodem i wiersz wysłany bez powodu', () => {
    expect(
      mailLogEntrySchema.safeParse(
        logEntry({ status: 'failed', statusLabel: 'nieudany', attempts: 4, failureReason: 'Connection refused' }),
      ).success,
    ).toBe(true)
    expect(
      mailLogEntrySchema.safeParse(
        logEntry({ status: 'sent', statusLabel: 'wysłany', attempts: 1, sentAt: '2026-09-13T20:01:00+00:00' }),
      ).success,
    ).toBe(true)
  })

  it('przyjmuje odpowiedź ze słownikiem stanów', () => {
    const parsed = mailLogListSchema.parse({
      entries: [logEntry()],
      count: 1,
      limit: 25,
      offset: 0,
      hasMore: false,
      statuses: [
        { value: 'queued', label: 'w kolejce' },
        { value: 'sent', label: 'wysłany' },
        { value: 'failed', label: 'nieudany' },
      ],
    })

    // Polskie nazwy przychodzą z backendu — ekran nie prowadzi własnego słownika.
    expect(parsed.statuses.map((one) => one.label)).toContain('nieudany')
  })
})

describe('schemat szablonu', () => {
  it('wymaga zamkniętej listy miejsc i wartości przykładowych', () => {
    const parsed = mailTemplateSchema.parse(template())

    expect(parsed.placeholders).toContain('link')
    expect(parsed.sampleValues.link).toContain('przykladowy-token')
  })

  it('przyjmuje szablon, którego nikt jeszcze nie zmieniał', () => {
    expect(mailTemplateSchema.safeParse(template({ updatedByEmail: null })).success).toBe(true)
  })
})

describe('stan dziennika', () => {
  it('rozróżnia trzy stany kolorem i ikoną, a słowo bierze z backendu', () => {
    const queued = statusAppearance(logEntry())
    const sent = statusAppearance(logEntry({ status: 'sent', statusLabel: 'wysłany' }))
    const failed = statusAppearance(logEntry({ status: 'failed', statusLabel: 'nieudany' }))

    expect(new Set([queued.color, sent.color, failed.color]).size).toBe(3)
    expect(new Set([queued.icon, sent.icon, failed.icon]).size).toBe(3)
    expect(failed.label).toBe('nieudany')
  })

  it('liczby prób nie pokazuje, gdy nic nie znaczy', () => {
    // „1 próba" w każdym wierszu zasłania ten jeden wiersz, gdzie prób było cztery.
    expect(describeAttempts(logEntry({ status: 'sent', attempts: 1 }))).toBeNull()
    expect(describeAttempts(logEntry({ status: 'sent', attempts: 3 }))).toBe('3 próby')
    expect(describeAttempts(logEntry({ status: 'failed', attempts: 1 }))).toBe('1 próba')
    expect(describeAttempts(logEntry({ status: 'failed', attempts: 5 }))).toBe('5 prób')
  })
})

describe('edycja szablonu', () => {
  it('zmianą jest różnica wobec zapisanego, nie sam fakt pisania', () => {
    const saved = template()

    expect(isEdited(saved, saved.subject, saved.body)).toBe(false)
    expect(isEdited(saved, 'Inny temat', saved.body)).toBe(true)
    // Wpisane i cofnięte to brak zmiany — bo tak to rozumie człowiek.
    expect(isEdited(saved, saved.subject, saved.body)).toBe(false)
  })

  it('podpowiedzi pokazują, w co zamieni się każde miejsce, i które są tylko do treści', () => {
    const hints = placeHints(template())
    const link = hints.find((one) => one.name === 'link')

    expect(link?.token).toBe('{{ link }}')
    expect(link?.sample).toContain('przykladowy-token')
    expect(link?.bodyOnly).toBe(true)
    expect(hints.find((one) => one.name === 'instancja')?.bodyOnly).toBe(false)
  })
})

describe('adminMailService', () => {
  let mock: MockAdapter

  beforeEach(() => {
    const http = axios.create({ baseURL: 'http://test/api' })
    mock = new MockAdapter(http)
    setApi(
      new ApiClient(
        { read: () => 'wsm_test', write: () => undefined, clear: () => undefined },
        http,
      ),
    )
  })

  it('wysyła tylko te filtry dziennika, które ktoś ustawił', async () => {
    mock.onGet(/\/admin\/mail-log/).reply((config) => {
      const query = new URLSearchParams(String(config.url).split('?')[1] ?? '')

      expect(query.get('status')).toBe('failed')
      expect(query.get('limit')).toBe('25')
      expect(query.has('recipient')).toBe(false)

      return [200, { entries: [], count: 0, limit: 25, offset: 0, hasMore: false, statuses: [] }]
    })

    await adminMailService.log({ ...emptyMailLogFilters, status: 'failed' })
  })

  it('pomija adresata wpisanego samymi odstępami i obcina resztę', async () => {
    mock.onGet(/\/admin\/mail-log/).reply((config) => {
      const query = new URLSearchParams(String(config.url).split('?')[1] ?? '')

      expect(query.get('recipient')).toBe('ktos@web-systems.pl')

      return [200, { entries: [], count: 0, limit: 25, offset: 0, hasMore: false, statuses: [] }]
    })

    await adminMailService.log({ ...emptyMailLogFilters, recipient: '  ktos@web-systems.pl  ' })
  })

  it('podgląd składa serwer, a nie przeglądarka', async () => {
    let asked = false

    mock.onPost('/admin/mail-templates/invitation/preview').reply((config) => {
      asked = true
      const sent = JSON.parse(String(config.data)) as { subject: string; body: string }

      // Treść niezapisana idzie na serwer taka, jaka jest w polu — bez podstawiania
      // czegokolwiek po drodze.
      expect(sent.body).toBe('Niezapisane {{ link }}')

      return [200, { preview: { subject: 'Temat', body: 'Niezapisane https://…' } }]
    })

    const preview = await adminMailService.preview('invitation', 'Temat', 'Niezapisane {{ link }}')

    expect(asked).toBe(true)
    expect(preview.body).toContain('https://…')
  })

  it('wysyłka próbna nie przyjmuje adresata', async () => {
    mock.onPost('/admin/mail-templates/invitation/test').reply((config) => {
      // Pole „odbiorca" nie istnieje i nie ma powstać: formularz z adresatem to sposób
      // na wysłanie maila z tego serwera do obcej osoby z treścią do wyboru nadawcy.
      expect(JSON.parse(String(config.data))).toEqual({})

      return [
        202,
        {
          mailLogId: '0199f0c0-0000-7000-8000-000000000099',
          recipient: 'admin@web-systems.pl',
          message: 'Wiadomość próbna trafiła do kolejki.',
        },
      ]
    })

    const answer = await adminMailService.testSend('invitation')

    expect(answer.recipient).toBe('admin@web-systems.pl')
  })

  it('klucz szablonu jedzie w adresie zakodowany', async () => {
    mock.onPut(/\/admin\/mail-templates\/.+/).reply((config) => {
      expect(String(config.url)).toContain('a%2Fb')

      return [200, { template: template({ key: 'a/b' }) }]
    })

    await adminMailService.save('a/b', 'Temat', 'Treść')
  })
})
