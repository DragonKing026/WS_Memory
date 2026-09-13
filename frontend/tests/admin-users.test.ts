import axios from 'axios'
import MockAdapter from 'axios-mock-adapter'
import { beforeEach, describe, expect, it } from 'vitest'

import { ApiClient, setApi } from '@/api/client'
import { ApiError } from '@/api/errors'
import { describeAdminFailure } from '@/features/admin/refusals'
import { adminUserListSchema, adminUserSchema, type AdminUser } from '@/features/admin/userSchemas'
import { adminUserService } from '@/features/admin/userService'
import {
  activityChangeFor,
  deactivationNotice,
  isViewer,
  resourceSummary,
  roleChangeFor,
} from '@/features/admin/userState'

/**
 * The accounts screen: the contract, the payload each button sends, and the one rule this
 * screen deliberately does **not** implement.
 *
 * The endpoints do not exist yet — the backend is being written against the same contract —
 * so this runs against stubs. What is being guarded is not "the request was well formed"
 * but three things that would each be invisible in review: that a toggle sends the new value
 * rather than the current one, that a refusal reaches the screen as the backend's own
 * sentence, and that no `null` in the contract can take the list down.
 */
function user(overrides: Partial<AdminUser> = {}): AdminUser {
  return {
    id: '0199f0c0-0000-7000-8000-000000000001',
    email: 'artur@web-systems.pl',
    displayName: 'Artur Ograbek',
    isGlobalAdmin: true,
    isActive: true,
    createdAt: '2026-09-01T08:00:00+00:00',
    lastLoginAt: '2026-09-13T07:30:00+00:00',
    spaceCount: 3,
    tokenCount: 1,
    ...overrides,
  }
}

describe('schematy kont', () => {
  it('przyjmuje odpowiedź dokładnie taką, jak w kontrakcie', () => {
    // Przepisane z kontraktu, nie sparafrazowane: sensem testu jest, że przechodzi
    // dokładnie ten ładunek.
    const answer = {
      users: [
        {
          id: '0199f0c0-0000-7000-8000-000000000001',
          email: 'artur@web-systems.pl',
          displayName: 'Artur Ograbek',
          isGlobalAdmin: true,
          isActive: true,
          createdAt: '2026-09-01T08:00:00+00:00',
          lastLoginAt: null,
          spaceCount: 3,
          tokenCount: 1,
        },
      ],
      count: 1,
      limit: 25,
      offset: 0,
      hasMore: false,
    }

    expect(adminUserListSchema.parse(answer).users[0]?.displayName).toBe('Artur Ograbek')
  })

  it('przyjmuje konto, które nigdy się nie logowało', () => {
    // Zwyczajny stan przyjętego zaproszenia, którego nikt jeszcze nie użył — i nie to
    // samo co „logował się dawno". Schemat, który by go odrzucił, wywracałby ekran na
    // najbardziej normalnym wierszu.
    expect(adminUserSchema.safeParse(user({ lastLoginAt: null })).success).toBe(true)
  })

  it('nie waliduje adresu e-mail po drodze', () => {
    // Adres sprawdzono przy wystawianiu zaproszenia. Schemat surowszy niż zawartość bazy
    // wygasiłby ekran, na którym takie konto się naprawia.
    expect(adminUserSchema.safeParse(user({ email: 'stare konto bez @' })).success).toBe(true)
  })
})

describe('co wysyła klik', () => {
  it('rola: administratorowi odbiera, zwykłemu nadaje', () => {
    // Gdyby przycisk wysyłał stan obecny, wyglądałby na niedziałający — a nikt nie zgłasza
    // „przycisk nic nie robi" jako błędu, tylko klika mocniej.
    expect(roleChangeFor(user({ isGlobalAdmin: true })).value).toBe(false)
    expect(roleChangeFor(user({ isGlobalAdmin: false })).value).toBe(true)
  })

  it('aktywność: włączone wyłącza, wyłączone włącza', () => {
    expect(activityChangeFor(user({ isActive: true })).value).toBe(false)
    expect(activityChangeFor(user({ isActive: false })).value).toBe(true)
  })

  it('potwierdzenie nazywa osobę, o którą pyta', () => {
    // Lista podobnych wierszy i pytanie bez nazwy to przepis na wyłączenie nie tego konta.
    expect(roleChangeFor(user()).confirmTitle).toContain('Artur Ograbek')
  })
})

describe('ostrzeżenie przed wyłączeniem konta', () => {
  it('mówi o tokenach agentów i podaje ich liczbę w poprawnej formie', () => {
    expect(deactivationNotice(user({ tokenCount: 1 }))).toContain('1 token')
    expect(deactivationNotice(user({ tokenCount: 3 }))).toContain('3 tokeny')
    expect(deactivationNotice(user({ tokenCount: 7 }))).toContain('7 tokenów')
  })

  it('przy braku tokenów nie udaje, że jest co odcinać', () => {
    const notice = deactivationNotice(user({ tokenCount: 0 }))

    expect(notice).toContain('nie ma czego odcinać')
    expect(notice).not.toContain('0 tokenów')
  })
})

describe('wiersz czytającego', () => {
  it('rozpoznaje konto osoby patrzącej na ekran', () => {
    // Obie odmowy backendu dotyczą działania na sobie, więc oznaczenie tego wiersza
    // zamienia 409 w coś przewidywalnego.
    expect(isViewer(user(), '0199f0c0-0000-7000-8000-000000000001')).toBe(true)
    expect(isViewer(user(), 'ktos-inny')).toBe(false)
  })

  it('bez zalogowanej osoby nie wskazuje nikogo', () => {
    expect(isViewer(user(), null)).toBe(false)
  })
})

describe('resourceSummary', () => {
  it('odmienia oba liczniki', () => {
    expect(resourceSummary(user({ spaceCount: 1, tokenCount: 2 }))).toBe(
      '1 przestrzeń · 2 tokeny agentów',
    )
    expect(resourceSummary(user({ spaceCount: 5, tokenCount: 0 }))).toBe(
      '5 przestrzeni · 0 tokenów agentów',
    )
  })
})

describe('describeAdminFailure', () => {
  it('409 cytuje słowami backendu, bez własnego opisu reguły', () => {
    // Najważniejszy test w tym pliku. Backend wie, dlaczego odmówił; drugi opis tej samej
    // reguły rozjedzie się z pierwszym przy pierwszej zmianie.
    const refusal = new ApiError(
      { kind: 'needsProposal', message: 'Nie możesz odebrać roli sobie.' },
      409,
    )

    expect(describeAdminFailure(refusal, 'zapasowy tekst')).toBe('Nie możesz odebrać roli sobie.')
  })

  it('409 bez treści nie zamienia się w zdanie o kolejce propozycji', () => {
    // Domyślka wspólnego klienta mówi o kolejce propozycji, której na tych ekranach nie ma.
    const bare = new ApiError(
      { kind: 'needsProposal', message: 'Ta przestrzeń wymaga przejścia przez kolejkę propozycji.' },
      409,
    )

    expect(describeAdminFailure(bare, 'zapasowy tekst')).not.toContain('propozycji')
    expect(describeAdminFailure(bare, 'zapasowy tekst')).toContain('konflikcie')
  })

  it('403 mówi o roli, a nie o „serwer nie odpowiedział"', () => {
    const forbidden = new ApiError({ kind: 'forbidden', message: 'Forbidden' }, 403)

    expect(describeAdminFailure(forbidden, 'zapasowy tekst')).toContain('administratora globalnego')
  })

  it('błąd kontraktu z Zoda przechodzi w całości', () => {
    // Zawiera nazwę pola — jedyną rzecz, która tu pomaga.
    const parse = new Error('Odpowiedź API dla „listę użytkowników" nie zgadza się z kontraktem: users.0.email')

    expect(describeAdminFailure(parse, 'zapasowy tekst')).toContain('users.0.email')
  })
})

describe('adminUserService', () => {
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

  it('wczytuje listę z limitem, a szukaną frazę wysyła jako q', async () => {
    mock.onGet(/\/admin\/users/).reply((config) => {
      expect(config.url).toContain('limit=25')
      expect(config.url).toContain('q=artur')

      return [200, { users: [user()], count: 1, limit: 25, offset: 0, hasMore: false }]
    })

    expect((await adminUserService.list(' artur ')).users).toHaveLength(1)
  })

  it('puste zapytanie nie wysyła pustego q', async () => {
    mock.onGet(/\/admin\/users/).reply((config) => {
      expect(config.url).not.toContain('q=')

      return [200, { users: [], count: 0, limit: 25, offset: 0, hasMore: false }]
    })

    await adminUserService.list('')
  })

  it('kolejna strona liczy offset od tego, co już jest', async () => {
    mock.onGet(/\/admin\/users/).reply((config) => {
      expect(config.url).toContain('offset=25')

      return [200, { users: [], count: 30, limit: 25, offset: 25, hasMore: false }]
    })

    await adminUserService.list('', 25)
  })

  it('zmiana roli wysyła nowy stan, nie polecenie przełączenia', async () => {
    mock.onPost(/\/admin\/users\/[^/]+\/rola-globalna$/).reply((config) => {
      expect(JSON.parse(String(config.data))).toEqual({ admin: false })

      return [200, { user: user({ isGlobalAdmin: false }) }]
    })

    expect((await adminUserService.setGlobalRole(user().id, false)).isGlobalAdmin).toBe(false)
  })

  it('rozumie odpowiedź bez koperty', async () => {
    // Kontrakt („200 użytkownik") czyta się dwojako, a panel zależności już raz na tym
    // poległ: backend wysłał kopertę, front spodziewał się samego obiektu.
    mock
      .onPost(/\/admin\/users\/[^/]+\/aktywnosc$/)
      .reply(200, user({ isActive: false }))

    expect((await adminUserService.setActivity(user().id, false)).isActive).toBe(false)
  })

  it('409 z backendu dochodzi z jego własną treścią', async () => {
    mock
      .onPost(/\/admin\/users\/[^/]+\/rola-globalna$/)
      .reply(409, { error: 'Nie możesz odebrać roli ostatniemu administratorowi.' })

    try {
      await adminUserService.setGlobalRole(user().id, false)
      expect.unreachable('odmowa miała polecieć wyjątkiem')
    } catch (cause) {
      expect(describeAdminFailure(cause, 'zapasowy tekst')).toBe(
        'Nie możesz odebrać roli ostatniemu administratorowi.',
      )
    }
  })

  it('403 dla nie-administratora dochodzi jako nazwany problem, nie wyjątek axiosa', async () => {
    mock.onGet(/\/admin\/users/).reply(403, { error: 'Wymagana rola administratora.' })

    await expect(adminUserService.list('')).rejects.toMatchObject({
      name: 'ApiError',
      status: 403,
    })
  })
})
