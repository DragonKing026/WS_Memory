import axios from 'axios'
import MockAdapter from 'axios-mock-adapter'
import { beforeEach, describe, expect, it } from 'vitest'

import { ApiClient, setApi } from '@/api/client'
import { describeAdminFailure } from '@/features/admin/refusals'
import {
  adminSpaceListSchema,
  adminSpaceSchema,
  spaceMemberListSchema,
  spaceMemberSchema,
  type AdminSpace,
  type SpaceMember,
} from '@/features/admin/spaceSchemas'
import { adminSpaceService } from '@/features/admin/spaceService'
import {
  isSpaceMemberRole,
  memberActionsAvailable,
  privacyExplanation,
  spaceBadges,
  spaceCounts,
} from '@/features/admin/spaceState'

/**
 * Spaces: the contract, and the one rule this screen enforces itself.
 *
 * That rule is the privacy one. Everything else about a refusal is left to the backend and
 * quoted, but a member action on a private space is not offered at all — the backend would
 * answer 422, and a button that leads to a refusal teaches people that the interface is
 * guessing. So the test below is not about text; it is about whether the control exists.
 */
function space(overrides: Partial<AdminSpace> = {}): AdminSpace {
  return {
    slug: 'wiedza',
    name: 'Wiedza firmowa',
    description: 'To, co firma postanowiła zapisać.',
    isPrivate: false,
    requiresProposal: false,
    palaceWing: 'wiedza',
    memberCount: 4,
    documentCount: 37,
    createdAt: '2026-09-01T08:00:00+00:00',
    ...overrides,
  }
}

function member(overrides: Partial<SpaceMember> = {}): SpaceMember {
  return {
    userId: '0199f0c0-0000-7000-8000-000000000001',
    displayName: 'Artur Ograbek',
    email: 'artur@web-systems.pl',
    role: 'admin',
    addedAt: '2026-09-01T08:05:00+00:00',
    addedBy: 'Artur Ograbek',
    ...overrides,
  }
}

describe('schematy przestrzeni', () => {
  it('przyjmuje odpowiedź dokładnie taką, jak w kontrakcie', () => {
    const answer = {
      spaces: [
        {
          slug: 'wiedza',
          name: 'Wiedza firmowa',
          description: 'To, co firma postanowiła zapisać.',
          isPrivate: false,
          requiresProposal: true,
          palaceWing: 'wiedza',
          memberCount: 4,
          documentCount: 37,
          createdAt: '2026-09-01T08:00:00+00:00',
        },
      ],
      count: 1,
      limit: 25,
      offset: 0,
      hasMore: false,
    }

    expect(adminSpaceListSchema.parse(answer).spaces[0]?.requiresProposal).toBe(true)
  })

  it('przyjmuje przestrzeń bez opisu i bez skrzydła', () => {
    // Prywatna przestrzeń powstaje przy przyjęciu zaproszenia — bez opisu, który ktoś by
    // wpisał. Schemat, który by tego nie przyjął, wywracałby listę na pierwszym koncie.
    expect(
      adminSpaceSchema.safeParse(space({ description: null, palaceWing: null })).success,
    ).toBe(true)
  })

  it('przyjmuje członkostwo bez wiadomo kogo, kto je nadał', () => {
    expect(spaceMemberSchema.safeParse(member({ addedBy: null })).success).toBe(true)
  })

  it('odrzuca rolę, której nie zna', () => {
    expect(spaceMemberSchema.safeParse(member({ role: 'owner' as never })).success).toBe(false)
  })

  it('przyjmuje pusty skład', () => {
    expect(spaceMemberListSchema.parse({ members: [] }).members).toEqual([])
  })
})

describe('prywatność przestrzeni', () => {
  it('przy prywatnej nie ma akcji na członkach', () => {
    // Backend odmówi (422). Przycisk, który do tej odmowy prowadzi, uczy nie ufać
    // interfejsowi — a to najdroższa rzecz, jakiej może nauczyć panel administracyjny.
    expect(memberActionsAvailable(space({ isPrivate: true }))).toBe(false)
    expect(memberActionsAvailable(space({ isPrivate: false }))).toBe(true)
  })

  it('brak akcji jest wytłumaczony przy prywatnej, a przy zwykłej nie ma czego tłumaczyć', () => {
    expect(privacyExplanation(space({ isPrivate: true }))).toContain('422')
    expect(privacyExplanation(space())).toBeNull()
  })

  it('prywatna i wymagająca propozycji są widoczne w oznaczeniach', () => {
    const labels = spaceBadges(space({ isPrivate: true, requiresProposal: true })).map(
      (badge) => badge.label,
    )

    expect(labels).toEqual(['prywatna', 'wymaga propozycji'])
    expect(spaceBadges(space())).toEqual([])
  })
})

describe('isSpaceMemberRole', () => {
  it('przepuszcza trzy role i nic poza nimi', () => {
    // Wartość z kontrolki jest luźno typowana i leci prosto do ciała żądania.
    expect(isSpaceMemberRole('writer')).toBe(true)
    expect(isSpaceMemberRole('owner')).toBe(false)
    expect(isSpaceMemberRole(null)).toBe(false)
    expect(isSpaceMemberRole(undefined)).toBe(false)
  })
})

describe('spaceCounts', () => {
  it('odmienia oba liczniki', () => {
    expect(spaceCounts(space({ memberCount: 1, documentCount: 0 }))).toBe(
      '1 członek · 0 dokumentów',
    )
    expect(spaceCounts(space({ memberCount: 3, documentCount: 22 }))).toBe(
      '3 członkowie · 22 dokumenty',
    )
  })
})

describe('adminSpaceService', () => {
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

  it('wczytuje listę, a filtr audytu może poprosić o cały katalog', async () => {
    mock.onGet(/\/admin\/spaces\?/).reply((config) => {
      expect(config.url).toContain('limit=200')

      return [200, { spaces: [space()], count: 1, limit: 200, offset: 0, hasMore: false }]
    })

    expect((await adminSpaceService.list(0, 200)).spaces).toHaveLength(1)
  })

  it('wczytuje skład przestrzeni', async () => {
    mock.onGet(/\/admin\/spaces\/wiedza\/members$/).reply(200, { members: [member()] })

    expect((await adminSpaceService.members('wiedza'))[0]?.role).toBe('admin')
  })

  it('zmiana roli wysyła rolę i rozumie odpowiedź w kopercie', async () => {
    mock.onPut(/\/admin\/spaces\/wiedza\/members\/[^/]+$/).reply((config) => {
      expect(JSON.parse(String(config.data))).toEqual({ role: 'writer' })

      return [200, { member: member({ role: 'writer' }) }]
    })

    expect((await adminSpaceService.setRole('wiedza', member().userId, 'writer')).role).toBe(
      'writer',
    )
  })

  it('rozumie odpowiedź bez koperty', async () => {
    mock.onPut(/\/admin\/spaces\/wiedza\/members\/[^/]+$/).reply(200, member({ role: 'reader' }))

    expect((await adminSpaceService.setRole('wiedza', member().userId, 'reader')).role).toBe(
      'reader',
    )
  })

  it('z całego składu wybiera osobę po id, nie po pozycji', async () => {
    // Lista uporządkowana inaczej wstawiłaby na kliknięty wiersz czyjąś rolę.
    mock.onPut(/\/admin\/spaces\/wiedza\/members\/[^/]+$/).reply(200, {
      members: [
        member({ userId: 'ktos-inny', displayName: 'Ktoś Inny', role: 'admin' }),
        member({ role: 'reader' }),
      ],
    })

    const updated = await adminSpaceService.setRole('wiedza', member().userId, 'reader')

    expect(updated.userId).toBe(member().userId)
    expect(updated.role).toBe('reader')
  })

  it('przyjęta zmiana bez zwróconej osoby jest błędem, nie cichą podmianą wiersza', async () => {
    mock
      .onPut(/\/admin\/spaces\/wiedza\/members\/[^/]+$/)
      .reply(200, { members: [member({ userId: 'ktos-inny' })] })

    await expect(adminSpaceService.setRole('wiedza', member().userId, 'reader')).rejects.toThrow(
      /nie zwrócił tej osoby/i,
    )
  })

  it('usunięcie z przestrzeni to DELETE bez treści', async () => {
    mock.onDelete(/\/admin\/spaces\/wiedza\/members\/[^/]+$/).reply(204)

    await expect(
      adminSpaceService.removeMember('wiedza', member().userId),
    ).resolves.toBeUndefined()
  })

  it('odmowa 422 przy prywatnej przestrzeni dochodzi treścią backendu', async () => {
    // Ekran i tak nie stawia tu przycisku, ale gdyby ktoś wywołał to inną drogą, powód
    // pokazuje ten, kto go zna.
    mock
      .onPut(/\/admin\/spaces\/priv_artur\/members\/[^/]+$/)
      .reply(422, { error: 'Składu przestrzeni prywatnej nie można zmieniać.' })

    try {
      await adminSpaceService.setRole('priv_artur', member().userId, 'writer')
      expect.unreachable('odmowa miała polecieć wyjątkiem')
    } catch (cause) {
      expect(describeAdminFailure(cause, 'zapasowy tekst')).toBe(
        'Składu przestrzeni prywatnej nie można zmieniać.',
      )
    }
  })

  it('409 przy odebraniu roli ostatniemu administratorowi przestrzeni cytuje backend', async () => {
    mock
      .onDelete(/\/admin\/spaces\/wiedza\/members\/[^/]+$/)
      .reply(409, { error: 'To ostatni administrator tej przestrzeni.' })

    try {
      await adminSpaceService.removeMember('wiedza', member().userId)
      expect.unreachable('odmowa miała polecieć wyjątkiem')
    } catch (cause) {
      expect(describeAdminFailure(cause, 'zapasowy tekst')).toBe(
        'To ostatni administrator tej przestrzeni.',
      )
    }
  })
})
