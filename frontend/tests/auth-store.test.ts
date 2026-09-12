import axios from 'axios'
import MockAdapter from 'axios-mock-adapter'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'

import { ApiClient, setApi, useApi } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

/**
 * The session store.
 *
 * The interesting assertions are the ones about not ending up half signed in: a token
 * stored with no user behind it produces an interface that looks signed in and answers
 * 401 to everything, which is far more confusing than a failed sign-in.
 */
/**
 * The store is tested against the shared client with a mocked transport, not against a
 * mocked service. That way the Zod boundary is exercised too — and "the backend changed
 * a field name" is exactly the failure these tests exist to catch early.
 */
let mock: MockAdapter

function memoryTokens(): { read(): string | null; write(t: string): void; clear(): void } {
  let token: string | null = null

  return {
    read: () => token,
    write: (value: string) => {
      token = value
    },
    clear: () => {
      token = null
    },
  }
}

const user = {
  id: '01a0-1',
  email: 'artur@web-systems.pl',
  displayName: 'Artur Ograbek',
  isGlobalAdmin: false,
  spaces: [
    { slug: 'alfa', name: 'Alfa', role: 'writer', isPrivate: false, requiresProposal: false },
    { slug: 'beta', name: 'Beta', role: 'reader', isPrivate: false, requiresProposal: false },
    { slug: 'priv_01a0-1', name: 'Prywatna', role: 'admin', isPrivate: true, requiresProposal: false },
  ],
}

describe('store auth', () => {
  beforeEach(() => {
    setActivePinia(createPinia())

    const http = axios.create({ baseURL: 'http://test/api' })
    mock = new MockAdapter(http)
    setApi(new ApiClient(memoryTokens(), http))
  })

  it('logowanie zapisuje token i wczytuje użytkownika', async () => {
    mock.onPost(/\/login$/).reply(200, { token: 'wsm_dobry' })
    mock.onGet(/\/me$/).reply(200, user)

    const auth = useAuthStore()
    await auth.signIn('artur@web-systems.pl', 'DlugieHaslo123!x')

    expect(auth.signedIn).toBe(true)
    expect(useApi().token).toBe('wsm_dobry')
    expect(auth.user?.displayName).toBe('Artur Ograbek')
  })

  it('złe hasło daje czytelny komunikat i żadnego tokena', async () => {
    mock.onPost(/\/login$/).reply(401, {})

    const auth = useAuthStore()
    await expect(auth.signIn('artur@web-systems.pl', 'złe')).rejects.toThrow()

    expect(auth.signedIn).toBe(false)
    expect(useApi().token).toBeNull()
    expect(auth.problem).toBe('Nieprawidłowy adres e-mail lub hasło.')
  })

  it('udane logowanie z nieudanym /me nie zostawia połowicznej sesji', async () => {
    // Token bez użytkownika za nim daje interfejs, który wygląda na zalogowany
    // i odpowiada 401 na wszystko — gorzej niż nieudane logowanie.
    mock.onPost(/\/login$/).reply(200, { token: 'wsm_dobry' })
    mock.onGet(/\/me$/).reply(500, {})

    const auth = useAuthStore()
    await expect(auth.signIn('artur@web-systems.pl', 'hasło')).rejects.toThrow()

    expect(useApi().token).toBeNull()
    expect(auth.signedIn).toBe(false)
  })

  it('odpowiedź niezgodna z kontraktem pada od razu, nie trzy ekrany dalej', async () => {
    mock.onPost(/\/login$/).reply(200, { token: 'wsm_dobry' })
    mock.onGet(/\/me$/).reply(200, { id: '1', email: 'a@b.pl' })

    const auth = useAuthStore()
    await expect(auth.signIn('a@b.pl', 'hasło')).rejects.toThrow(/kontraktem/)
  })

  it('odtworzenie sesji bez tokena po prostu mówi „nie"', async () => {
    const auth = useAuthStore()

    await expect(auth.restore()).resolves.toBe(false)
    expect(mock.history.get).toHaveLength(0)
  })

  it('odtworzenie z ważnym tokenem wczytuje użytkownika', async () => {
    useApi().setToken('wsm_zapisany')
    mock.onGet(/\/me$/).reply(200, user)

    const auth = useAuthStore()

    await expect(auth.restore()).resolves.toBe(true)
    expect(auth.signedIn).toBe(true)
  })

  it('odtworzenie z wygasłym tokenem nie wywraca strony', async () => {
    useApi().setToken('wsm_wygasly')
    mock.onGet(/\/me$/).reply(401, {})

    const auth = useAuthStore()

    await expect(auth.restore()).resolves.toBe(false)
    expect(auth.signedIn).toBe(false)
  })

  it('uprawnienia do zapisu liczy z listy z serwera, nie z własnych reguł', async () => {
    mock.onPost(/\/login$/).reply(200, { token: 't' })
    mock.onGet(/\/me$/).reply(200, user)

    const auth = useAuthStore()
    await auth.signIn('a@b.pl', 'hasło')

    expect(auth.canWriteIn('alfa')).toBe(true)
    expect(auth.canWriteIn('beta')).toBe(false)
    expect(auth.canWriteIn('kadry')).toBe(false)
    expect(auth.writableSpaces.map((space) => space.slug)).toEqual(['alfa', 'priv_01a0-1'])
  })

  it('utrata sesji pamięta, gdzie był użytkownik, i oddaje to raz', async () => {
    const auth = useAuthStore()
    auth.sessionLost('/s/alfa/umowy/najem', 'Sesja wygasła.')

    expect(auth.signedIn).toBe(false)
    expect(auth.takeReturnTo()).toBe('/s/alfa/umowy/najem')
    expect(auth.takeReturnTo()).toBeNull()
  })

  it('wylogowanie czyści token i użytkownika', async () => {
    mock.onPost(/\/login$/).reply(200, { token: 't' })
    mock.onGet(/\/me$/).reply(200, user)

    const auth = useAuthStore()
    await auth.signIn('a@b.pl', 'hasło')
    auth.signOut()

    expect(useApi().token).toBeNull()
    expect(auth.signedIn).toBe(false)
  })
})
