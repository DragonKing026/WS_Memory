import axios, { type AxiosInstance, type AxiosRequestConfig } from 'axios'

import { ApiError, problemFrom } from './errors'

/**
 * The only place that knows the API exists.
 *
 * No component ever calls axios. That is not tidiness: attaching the token, naming
 * the failures and reacting to an expired session are three rules that must hold on
 * every request, and a rule enforced in forty call sites is a rule that will be
 * missing from the forty-first.
 *
 * **There is no token refresh, and that is a decision rather than a gap** (D-017):
 * `gesdinet/jwt-refresh-token-bundle` does not support Symfony 8, and writing
 * refresh-token rotation by hand to save one sign-in a day is a poor trade. So a 401
 * ends the session, and this client's job is to make that as survivable as possible:
 * it reports the problem, hands control to whoever registered `onSessionLost`, and
 * never silently swallows the response a screen was waiting for.
 */

/** Where the token lives between page loads. See the note on storage below. */
const TOKEN_KEY = 'ws_memory_token'

/**
 * The base address, always absolute.
 *
 * Absolute because derived addresses are computed with `new URL(path, base)`, which
 * refuses a relative base. Defaults to the current origin, since nginx serves the app
 * and `/api` from one origin — which is precisely what keeps the browser from ever
 * touching CORS.
 */
function baseUrl(): string {
  const configured = import.meta.env.VITE_API_BASE_URL

  if (typeof configured === 'string' && configured.trim() !== '') {
    return configured.replace(/\/+$/, '')
  }

  return `${globalThis.location?.origin ?? 'http://127.0.0.1:8080'}/api`
}

export interface TokenStore {
  read(): string | null
  write(token: string): void
  clear(): void
}

/**
 * The token in `localStorage`.
 *
 * A named trade-off, not an oversight. An httpOnly cookie would be safer against XSS
 * but needs CSRF protection and a backend that sets it — a change to the stateless
 * JWT design, not a frontend choice. `sessionStorage` would narrow the exposure and
 * sign the user out whenever they open a link in a new tab. So: `localStorage`, an
 * eight-hour token, and the risk written down where risks are written down
 * (SECURITY.md) rather than left for somebody to discover.
 *
 * Every access is guarded, because storage throws in a private window and returns
 * nothing after site data is cleared.
 */
export const browserTokenStore: TokenStore = {
  read() {
    try {
      return globalThis.localStorage?.getItem(TOKEN_KEY) ?? null
    } catch {
      return null
    }
  },
  write(token) {
    try {
      globalThis.localStorage?.setItem(TOKEN_KEY, token)
    } catch {
      // A session that lasts until the tab closes beats refusing to sign in.
    }
  },
  clear() {
    try {
      globalThis.localStorage?.removeItem(TOKEN_KEY)
    } catch {
      // Nothing to do: there is no storage to clear.
    }
  },
}

export class ApiClient {
  private readonly http: AxiosInstance
  private sessionLost: ((problem: ApiError) => void) | null = null

  constructor(
    private readonly tokens: TokenStore = browserTokenStore,
    http?: AxiosInstance,
  ) {
    this.http =
      http ??
      axios.create({
        baseURL: baseUrl(),
        // Long enough for a semantic search over the whole base, short enough that a
        // hung request does not look like a frozen interface.
        timeout: 30_000,
        headers: { Accept: 'application/json' },
      })

    this.http.interceptors.request.use((config) => {
      const token = this.tokens.read()

      if (token !== null) {
        config.headers.set('Authorization', `Bearer ${token}`)
      }

      return config
    })
  }

  /**
   * Called when the session ends mid-flight.
   *
   * A callback rather than a router import, so this file keeps knowing nothing about
   * routing — and so a test can assert the notification without mounting a router.
   */
  onSessionLost(handler: (problem: ApiError) => void): void {
    this.sessionLost = handler
  }

  get token(): string | null {
    return this.tokens.read()
  }

  setToken(token: string): void {
    this.tokens.write(token)
  }

  clearToken(): void {
    this.tokens.clear()
  }

  async get<T>(path: string, config?: AxiosRequestConfig): Promise<T> {
    return this.request<T>({ ...config, method: 'GET', url: path })
  }

  async post<T>(path: string, body?: unknown, config?: AxiosRequestConfig): Promise<T> {
    return this.request<T>({ ...config, method: 'POST', url: path, data: body })
  }

  async put<T>(path: string, body?: unknown, config?: AxiosRequestConfig): Promise<T> {
    return this.request<T>({ ...config, method: 'PUT', url: path, data: body })
  }

  async delete<T>(path: string, config?: AxiosRequestConfig): Promise<T> {
    return this.request<T>({ ...config, method: 'DELETE', url: path })
  }

  private async request<T>(config: AxiosRequestConfig): Promise<T> {
    try {
      const response = await this.http.request<T>(config)

      return response.data
    } catch (cause) {
      throw this.translate(cause)
    }
  }

  /**
   * Turns an axios failure into an ApiError, and ends the session on a 401.
   *
   * The token is cleared here rather than by the caller, because the alternative is
   * an interface that keeps sending a credential the server has already rejected —
   * and every one of those requests looks to a reader like the application hanging.
   */
  private translate(cause: unknown): ApiError {
    // isAxiosError(), not instanceof: the check has to hold even when two copies of
    // axios end up in the graph — a bundler hoisting one for tests is enough, and the
    // failure mode is every error turning into "unexpected browser error".
    if (!axios.isAxiosError(cause)) {
      return new ApiError(
        { kind: 'unavailable', message: 'Nieoczekiwany błąd po stronie przeglądarki.' },
        null,
      )
    }

    const status = cause.response?.status ?? null
    const error = new ApiError(problemFrom(status, cause.response?.data), status)

    if (error.needsSignIn) {
      this.clearToken()
      this.sessionLost?.(error)
    }

    return error
  }
}

/**
 * The one instance the application uses, created on first use.
 *
 * A function rather than `export const api = new ApiClient()` for two reasons. It
 * keeps the axios instance — and its read of `window.location` — out of module load,
 * which matters because importing this file must not require a browser. And it gives
 * tests a seam: `setApi()` replaces the instance, so a store can be tested against a
 * mocked transport without the store knowing anything about testing.
 */
let instance: ApiClient | null = null

export function useApi(): ApiClient {
  return (instance ??= new ApiClient())
}

/**
 * Replaces the shared instance.
 *
 * Used by tests, and available to `main.ts` if the application ever needs a client
 * configured differently. Deliberately not called anywhere in normal operation: two
 * clients in one page would mean two places holding a token.
 */
export function setApi(client: ApiClient): void {
  instance = client
}
