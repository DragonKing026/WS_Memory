import axios from 'axios'
import MockAdapter from 'axios-mock-adapter'
import { beforeEach, describe, expect, it } from 'vitest'

import { ApiClient, setApi } from '@/api/client'
import {
  adminInvitationListSchema,
  adminInvitationSchema,
  issuedInvitationSchema,
  type AdminInvitation,
} from '@/features/admin/invitationSchemas'
import { adminInvitationService } from '@/features/admin/invitationService'
import { absoluteInvitationLink, invitationVerdict } from '@/features/admin/invitationState'
import { describeAdminFailure } from '@/features/admin/refusals'

/**
 * Invitations: the contract, the one-shot link, and when withdrawal is offered.
 *
 * The link is the reason for most of what is asserted here. It exists in readable form for
 * the length of one HTTP response — the database keeps a hash — so anything that loses it
 * (a schema that rejects the answer, a path where it is not shown) costs somebody a second
 * invitation and an explanation.
 */
function invitation(overrides: Partial<AdminInvitation> = {}): AdminInvitation {
  return {
    id: '0199f0c0-0000-7000-8000-000000000002',
    email: 'nowy@web-systems.pl',
    invitedBy: 'Artur Ograbek',
    grantsGlobalAdmin: false,
    status: 'oczekuje',
    createdAt: '2026-09-13T09:00:00+00:00',
    expiresAt: '2026-09-20T09:00:00+00:00',
    acceptedAt: null,
    ...overrides,
  }
}

describe('schematy zaproszeń', () => {
  it('przyjmuje odpowiedź dokładnie taką, jak w kontrakcie', () => {
    const answer = {
      invitations: [
        {
          id: '0199f0c0-0000-7000-8000-000000000002',
          email: 'nowy@web-systems.pl',
          invitedBy: 'Artur Ograbek',
          grantsGlobalAdmin: false,
          status: 'oczekuje',
          createdAt: '2026-09-13T09:00:00+00:00',
          expiresAt: '2026-09-20T09:00:00+00:00',
          acceptedAt: null,
        },
      ],
      count: 1,
      limit: 25,
      offset: 0,
      hasMore: false,
    }

    expect(adminInvitationListSchema.parse(answer).invitations[0]?.status).toBe('oczekuje')
  })

  it('przyjmuje wszystkie trzy statusy i przyjęcie z datą', () => {
    expect(adminInvitationSchema.safeParse(invitation({ status: 'wygasle' })).success).toBe(true)
    expect(
      adminInvitationSchema.safeParse(
        invitation({ status: 'przyjete', acceptedAt: '2026-09-14T10:00:00+00:00' }),
      ).success,
    ).toBe(true)
  })

  it('odrzuca status, którego nie zna', () => {
    // Cicha zgoda oznaczałaby wiersz bez etykiety i bez wiadomo jakich akcji.
    expect(adminInvitationSchema.safeParse(invitation({ status: 'anulowane' as never })).success).toBe(
      false,
    )
  })

  it('przyjmuje wystawienie bez linku, bo zaproszenie już istnieje', () => {
    // Odrzucenie całej odpowiedzi schowałoby utworzone zaproszenie za błędem parsowania, a
    // administrator wystawiłby drugie dla tej samej osoby.
    const result = issuedInvitationSchema.safeParse({ invitation: invitation(), link: null })

    expect(result.success).toBe(true)
  })
})

describe('invitationVerdict', () => {
  const now = new Date('2026-09-15T12:00:00+00:00')

  it('unieważnienie proponuje tylko przy oczekującym', () => {
    // Przyjęte jest już kontem (to ekran kont), a wygasłe to link, który nie działa.
    // Przycisk w obu przypadkach prowadziłby do odmowy.
    expect(invitationVerdict(invitation(), now).canRevoke).toBe(true)
    expect(invitationVerdict(invitation({ status: 'przyjete' }), now).canRevoke).toBe(false)
    expect(invitationVerdict(invitation({ status: 'wygasle' }), now).canRevoke).toBe(false)
  })

  it('termin miniony przy statusie „oczekuje" jest zgłaszany, ale statusu nie nadpisuje', () => {
    // Status policzył serwer przy pobraniu listy. Karta otwarta od godziny pokazuje
    // „oczekuje" przy martwym linku — mówimy to, ale DELETE i tak sądzi się po stanie
    // serwera, więc etykieta zostaje jego.
    const stale = invitationVerdict(
      invitation({ expiresAt: '2026-09-14T09:00:00+00:00' }),
      now,
    )

    expect(stale.expiredWhileListed).toBe(true)
    expect(stale.status).toBe('oczekuje')
    expect(stale.canRevoke).toBe(true)
  })

  it('nie zgłasza wygaśnięcia przy zaproszeniu już przyjętym', () => {
    const accepted = invitationVerdict(
      invitation({ status: 'przyjete', expiresAt: '2026-09-14T09:00:00+00:00' }),
      now,
    )

    expect(accepted.expiredWhileListed).toBe(false)
  })

  it('niedająca się odczytać data nie robi z oczekującego wygasłego', () => {
    expect(invitationVerdict(invitation({ expiresAt: 'kiedyś' }), now).expiredWhileListed).toBe(
      false,
    )
  })
})

describe('absoluteInvitationLink', () => {
  it('ze ścieżki backendu robi adres, który da się wklejać', () => {
    expect(absoluteInvitationLink('/zaproszenie/abc', 'https://wiedza.web-systems.pl')).toBe(
      'https://wiedza.web-systems.pl/zaproszenie/abc',
    )
  })

  it('nie dokleja drugiego ukośnika', () => {
    expect(absoluteInvitationLink('/zaproszenie/abc', 'https://wiedza.web-systems.pl/')).toBe(
      'https://wiedza.web-systems.pl/zaproszenie/abc',
    )
  })

  it('adres bezwzględny przepuszcza bez zmian', () => {
    // Gdyby backend zaczął kiedyś wysyłać cały adres, sklejanie zrobiłoby z niego bzdurę.
    expect(absoluteInvitationLink('https://inny.host/zaproszenie/abc', 'https://tutaj')).toBe(
      'https://inny.host/zaproszenie/abc',
    )
  })
})

describe('adminInvitationService', () => {
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

  it('wystawienie wysyła adres i flagę roli, a odbiera link', async () => {
    mock.onPost(/\/admin\/invitations$/).reply((config) => {
      expect(JSON.parse(String(config.data))).toEqual({
        email: 'nowy@web-systems.pl',
        admin: true,
      })

      return [
        201,
        { invitation: invitation({ grantsGlobalAdmin: true }), link: '/zaproszenie/tajne' },
      ]
    })

    const issued = await adminInvitationService.create('  nowy@web-systems.pl  ', true)

    expect(issued.link).toBe('/zaproszenie/tajne')
    expect(issued.invitation.grantsGlobalAdmin).toBe(true)
  })

  it('wczytuje listę ze stronicowaniem', async () => {
    mock.onGet(/\/admin\/invitations/).reply((config) => {
      expect(config.url).toContain('limit=25')

      return [200, { invitations: [invitation()], count: 1, limit: 25, offset: 0, hasMore: false }]
    })

    expect((await adminInvitationService.list()).invitations).toHaveLength(1)
  })

  it('unieważnienie to DELETE bez treści odpowiedzi', async () => {
    mock.onDelete(/\/admin\/invitations\/[^/]+$/).reply(204)

    await expect(adminInvitationService.revoke(invitation().id)).resolves.toBeUndefined()
  })

  it('odmowa unieważnienia dochodzi treścią backendu', async () => {
    mock
      .onDelete(/\/admin\/invitations\/[^/]+$/)
      .reply(409, { error: 'Zaproszenie zostało już przyjęte.' })

    try {
      await adminInvitationService.revoke(invitation().id)
      expect.unreachable('odmowa miała polecieć wyjątkiem')
    } catch (cause) {
      expect(describeAdminFailure(cause, 'zapasowy tekst')).toBe('Zaproszenie zostało już przyjęte.')
    }
  })

  it('422 przy błędnym adresie pokazuje, co powiedział backend', async () => {
    mock.onPost(/\/admin\/invitations$/).reply(422, { error: 'To nie jest adres e-mail.' })

    try {
      await adminInvitationService.create('bzdura', false)
      expect.unreachable('odmowa miała polecieć wyjątkiem')
    } catch (cause) {
      expect(describeAdminFailure(cause, 'zapasowy tekst')).toBe('To nie jest adres e-mail.')
    }
  })
})
