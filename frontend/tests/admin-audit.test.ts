import axios from 'axios'
import MockAdapter from 'axios-mock-adapter'
import { beforeEach, describe, expect, it } from 'vitest'

import { ApiClient, setApi } from '@/api/client'
import { auditEntrySchema, auditListSchema, type AuditEntry } from '@/features/admin/auditSchemas'
import { adminAuditService, emptyAuditFilters } from '@/features/admin/auditService'
import { actorAppearance, describeActors, formatTarget, hasTarget } from '@/features/admin/auditState'

/**
 * The audit log: the contract, and the distinction the whole screen rests on.
 *
 * `actorKind` is that distinction. An agent's entry carries the display name of the person
 * whose token it used, so without the kind the log answers "kto to zrobił" with a name that
 * is true and useless. Hence a test that the three kinds are told apart by more than
 * wording, and a schema that refuses a fourth kind rather than filing it under "system" —
 * a mislabelled actor in an audit log is worse than no log, because it is believed.
 */
function entry(overrides: Partial<AuditEntry> = {}): AuditEntry {
  return {
    id: '0199f0c0-0000-7000-8000-000000000010',
    action: 'doc_write',
    actor: 'Artur Ograbek',
    actorKind: 'czlowiek',
    space: 'wiedza',
    target: { slug: 'umowy/najem', revision: 4 },
    ip: '192.168.1.10',
    createdAt: '2026-09-13T11:00:00+00:00',
    ...overrides,
  }
}

describe('schematy dziennika', () => {
  it('przyjmuje odpowiedź dokładnie taką, jak w kontrakcie', () => {
    const answer = {
      entries: [
        {
          id: '0199f0c0-0000-7000-8000-000000000010',
          action: 'search',
          actor: 'Artur Ograbek',
          actorKind: 'agent',
          space: null,
          target: {},
          ip: null,
          createdAt: '2026-09-13T11:00:00+00:00',
        },
      ],
      count: 1,
      limit: 25,
      offset: 0,
      hasMore: false,
      actions: ['search', 'doc_write', 'login'],
    }

    const parsed = auditListSchema.parse(answer)

    expect(parsed.entries[0]?.actorKind).toBe('agent')
    expect(parsed.actions).toContain('login')
  })

  it('przyjmuje wpis bez przestrzeni, bez adresu IP i bez szczegółów', () => {
    // Logowanie nie należy do żadnej przestrzeni, polecenie z konsoli nie ma adresu, a
    // niejeden wpis nie ma czego dotyczyć. Każdy z tych nulli to stan, nie brak danych.
    expect(
      auditEntrySchema.safeParse(entry({ space: null, ip: null, target: null })).success,
    ).toBe(true)
    expect(auditEntrySchema.safeParse(entry({ target: {} })).success).toBe(true)
  })

  it('nie przyjmuje nieznanego rodzaju sprawcy', () => {
    // Ciche przypisanie do „systemu" zamieniłoby dziennik w źródło fałszywych zdań —
    // gorsze niż brak dziennika, bo czytane z zaufaniem.
    expect(auditEntrySchema.safeParse(entry({ actorKind: 'bot' as never })).success).toBe(false)
  })

  it('przyjmuje akcję, której nie zna', () => {
    // Słownik akcji rośnie z backendem, a lista tych, które faktycznie są, przychodzi w
    // `actions`.
    expect(auditEntrySchema.safeParse(entry({ action: 'nowa_akcja_z_przyszlosci' })).success).toBe(
      true,
    )
  })
})

describe('actorAppearance', () => {
  it('rozróżnia człowieka, agenta i system na trzy różne sposoby', () => {
    const human = actorAppearance('czlowiek')
    const agent = actorAppearance('agent')
    const system = actorAppearance('system')

    expect(new Set([human.label, agent.label, system.label]).size).toBe(3)
    expect(new Set([human.color, agent.color, system.color]).size).toBe(3)
    expect(new Set([human.icon, agent.icon, system.icon]).size).toBe(3)
  })

  it('agenta nazywa agentem, a nie nazwą osoby, której token użył', () => {
    expect(actorAppearance('agent').label).toContain('agent')
  })
})

describe('szczegóły wpisu', () => {
  it('rozwinięcie proponuje się tylko wtedy, gdy jest co rozwinąć', () => {
    // Rozwinięcie, które otwiera się na `{}`, to obietnica niedotrzymana.
    expect(hasTarget(entry())).toBe(true)
    expect(hasTarget(entry({ target: {} }))).toBe(false)
    expect(hasTarget(entry({ target: null }))).toBe(false)
  })

  it('pokazuje cały obiekt, nie wybrane pola', () => {
    const shown = formatTarget(entry())

    expect(shown).toContain('umowy/najem')
    expect(shown).toContain('revision')
    expect(formatTarget(entry({ target: null }))).toBe('{}')
  })
})

describe('describeActors', () => {
  it('mówi, ile z wczytanej partii pochodzi od kogo', () => {
    const summary = describeActors([
      entry({ actorKind: 'czlowiek' }),
      entry({ actorKind: 'agent' }),
      entry({ actorKind: 'agent' }),
      entry({ actorKind: 'system' }),
    ])

    expect(summary).toBe('1 od człowieka · 2 od agentów · 1 od systemu')
  })

  it('nie wymienia rodzajów, których w partii nie było', () => {
    expect(describeActors([entry({ actorKind: 'agent' })])).toBe('1 od agenta')
  })

  it('przy pustej liście nie mówi nic', () => {
    expect(describeActors([])).toBeNull()
  })
})

describe('adminAuditService', () => {
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

  it('wysyła tylko te filtry, które ktoś ustawił', async () => {
    mock.onGet(/\/admin\/audit/).reply((config) => {
      const query = new URLSearchParams(String(config.url).split('?')[1] ?? '')

      expect(query.get('action')).toBe('doc_write')
      expect(query.get('space')).toBe('wiedza')
      expect(query.get('limit')).toBe('25')
      expect(query.has('actor')).toBe(false)
      expect(query.has('since')).toBe(false)

      return [
        200,
        { entries: [entry()], count: 1, limit: 25, offset: 0, hasMore: false, actions: [] },
      ]
    })

    await adminAuditService.list({
      ...emptyAuditFilters,
      action: 'doc_write',
      space: 'wiedza',
    })
  })

  it('pomija filtr wpisany samymi odstępami i obcina resztę', async () => {
    mock.onGet(/\/admin\/audit/).reply((config) => {
      const query = new URLSearchParams(String(config.url).split('?')[1] ?? '')

      expect(query.has('space')).toBe(false)
      expect(query.get('actor')).toBe('Artur Ograbek')

      return [
        200,
        { entries: [], count: 0, limit: 25, offset: 0, hasMore: false, actions: [] },
      ]
    })

    await adminAuditService.list({
      ...emptyAuditFilters,
      space: '   ',
      actor: '  Artur Ograbek  ',
    })
  })

  it('kolejna strona liczy offset od tego, co już wczytane', async () => {
    mock.onGet(/\/admin\/audit/).reply((config) => {
      expect(String(config.url)).toContain('offset=25')

      return [
        200,
        { entries: [], count: 80, limit: 25, offset: 25, hasMore: true, actions: ['search'] },
      ]
    })

    expect((await adminAuditService.list(emptyAuditFilters, 25)).hasMore).toBe(true)
  })

  it('zakres dat leci tak, jak go wpisano', async () => {
    mock.onGet(/\/admin\/audit/).reply((config) => {
      const query = new URLSearchParams(String(config.url).split('?')[1] ?? '')

      expect(query.get('since')).toBe('2026-09-01')
      expect(query.get('before')).toBe('2026-09-13')

      return [
        200,
        { entries: [], count: 0, limit: 25, offset: 0, hasMore: false, actions: [] },
      ]
    })

    await adminAuditService.list({
      ...emptyAuditFilters,
      since: '2026-09-01',
      before: '2026-09-13',
    })
  })

  it('403 dla nie-administratora dochodzi jako nazwany problem', async () => {
    mock.onGet(/\/admin\/audit/).reply(403, { error: 'Wymagana rola administratora.' })

    await expect(adminAuditService.list(emptyAuditFilters)).rejects.toMatchObject({
      name: 'ApiError',
      status: 403,
    })
  })
})
