import axios from 'axios'
import MockAdapter from 'axios-mock-adapter'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiClient, type TokenStore } from '@/api/client'
import { ApiError } from '@/api/errors'

/**
 * The HTTP client: the one place that attaches the token and names failures.
 *
 * The tests that matter are about what happens when a session ends. There is no token
 * refresh (D-017), so a 401 must do three things every time: clear the stored token,
 * tell whoever is listening, and still report the failure to the caller. Miss the
 * first and the interface keeps sending a credential the server rejected; miss the
 * third and a screen waits for ever for a response that will not come.
 */

function memoryStore(initial: string | null = null): TokenStore {
  let token = initial

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

function clientWith(store: TokenStore): { client: ApiClient; mock: MockAdapter } {
  const http = axios.create({ baseURL: 'http://test/api' })

  return { client: new ApiClient(store, http), mock: new MockAdapter(http) }
}

describe('ApiClient', () => {
  let store: TokenStore

  beforeEach(() => {
    store = memoryStore()
  })

  it('dokłada token do każdego żądania', async () => {
    store.write('wsm_token')
    const { client, mock } = clientWith(store)

    mock.onGet('/me').reply((config) => {
      expect(config.headers?.Authorization).toBe('Bearer wsm_token')

      return [200, { ok: true }]
    })

    await client.get('/me')
    expect(mock.history.get).toHaveLength(1)
  })

  it('nie dokłada nagłówka, gdy tokena nie ma', async () => {
    const { client, mock } = clientWith(store)

    mock.onPost('/login').reply((config) => {
      expect(config.headers?.Authorization).toBeUndefined()

      return [200, { token: 'x' }]
    })

    await client.post('/login', {})
  })

  it('zwraca treść odpowiedzi, nie całą odpowiedź', async () => {
    const { client, mock } = clientWith(store)
    mock.onGet('/me').reply(200, { displayName: 'Artur' })

    await expect(client.get<{ displayName: string }>('/me')).resolves.toEqual({
      displayName: 'Artur',
    })
  })

  describe('koniec sesji', () => {
    it('czyści token, powiadamia i NADAL zgłasza błąd', async () => {
      store.write('wygasły')
      const { client, mock } = clientWith(store)
      const lost = vi.fn()
      client.onSessionLost(lost)

      mock.onGet('/me').reply(401, { error: 'Expired JWT Token' })

      await expect(client.get('/me')).rejects.toBeInstanceOf(ApiError)
      expect(store.read()).toBeNull()
      expect(lost).toHaveBeenCalledOnce()
    })

    it('nie ponawia żądania po 401, bo nie ma czym odświeżyć tokena', async () => {
      // Wprost, żeby nikt nie dodał tu „na wszelki wypadek" ponowienia: endpointu
      // odświeżania nie ma (D-017), więc ponowienie byłoby drugim identycznym 401.
      store.write('wygasły')
      const { client, mock } = clientWith(store)
      mock.onGet('/me').reply(401, {})

      await expect(client.get('/me')).rejects.toBeInstanceOf(ApiError)
      expect(mock.history.get).toHaveLength(1)
    })

    it('nie woła powiadomienia przy innych błędach', async () => {
      store.write('dobry')
      const { client, mock } = clientWith(store)
      const lost = vi.fn()
      client.onSessionLost(lost)

      mock.onGet('/spaces/kadry').reply(404, { error: 'Nie ma takiej przestrzeni.' })

      await expect(client.get('/spaces/kadry')).rejects.toThrow()
      expect(lost).not.toHaveBeenCalled()
      expect(store.read()).toBe('dobry')
    })
  })

  describe('nazywanie błędów', () => {
    it.each([
      [403, 'forbidden'],
      [404, 'missing'],
      [409, 'needsProposal'],
      [422, 'rejected'],
      [429, 'throttled'],
      [500, 'unavailable'],
    ])('kod %i to problem „%s"', async (status, kind) => {
      const { client, mock } = clientWith(store)
      mock.onGet('/cokolwiek').reply(status, {})

      await expect(client.get('/cokolwiek')).rejects.toMatchObject({ kind })
    })

    it('używa komunikatu z backendu, gdy jest', async () => {
      // Komunikaty backendu są pisane dla czytającego człowieka i lepsze niż
      // cokolwiek, co frontend mógłby wymyślić.
      const { client, mock } = clientWith(store)
      mock.onPut('/dokument').reply(422, { error: 'Adres „Umowy Najmu" jest nieprawidłowy.' })

      await expect(client.put('/dokument', {})).rejects.toThrow(
        'Adres „Umowy Najmu" jest nieprawidłowy.',
      )
    })

    it('brak odpowiedzi to „niedostępne", nie cichy sukces', async () => {
      const { client, mock } = clientWith(store)
      mock.onGet('/me').networkError()

      await expect(client.get('/me')).rejects.toMatchObject({ kind: 'unavailable' })
    })
  })
})
