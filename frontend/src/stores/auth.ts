import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { ApiError } from '@/api/errors'
import { useApi } from '@/api/client'
import { authService } from '@/features/auth/service'
import type { CurrentUser, SpaceMembership } from '@/features/auth/schemas'

/**
 * Who is signed in, and what they may reach.
 *
 * The space list comes from `/api/me` and is never assembled here. That is the same
 * rule the backend follows: permissions are computed in one place, so an interface
 * that decided for itself which spaces to show would eventually show one the server
 * refuses — a menu item that answers 404 is worse than no menu item.
 */
export const useAuthStore = defineStore('auth', () => {
  const user = ref<CurrentUser | null>(null)
  const loading = ref(false)
  const problem = ref<string | null>(null)

  /**
   * Where to return after signing in again.
   *
   * Set when a session is lost mid-action rather than at sign-in, because that is the
   * case worth handling: somebody halfway through reading a document should land back
   * on it, not on a home page that makes them find it again.
   */
  const returnTo = ref<string | null>(null)

  const signedIn = computed(() => user.value !== null)
  const spaces = computed<SpaceMembership[]>(() => user.value?.spaces ?? [])
  const writableSpaces = computed(() =>
    spaces.value.filter((space) => space.role === 'writer' || space.role === 'admin'),
  )

  function spaceBySlug(slug: string): SpaceMembership | undefined {
    return spaces.value.find((space) => space.slug === slug)
  }

  function canWriteIn(slug: string): boolean {
    const role = spaceBySlug(slug)?.role

    return role === 'writer' || role === 'admin'
  }

  async function signIn(email: string, password: string): Promise<void> {
    loading.value = true
    problem.value = null

    try {
      useApi().setToken(await authService.signIn(email, password))
      user.value = await authService.currentUser()
    } catch (cause) {
      // The token is dropped on any failure, including a successful sign-in whose
      // follow-up failed: a stored token with no user behind it produces an interface
      // that looks signed in and answers 401 to everything.
      useApi().clearToken()
      user.value = null
      problem.value = describe(cause)

      throw cause
    } finally {
      loading.value = false
    }
  }

  /**
   * Restores the session from a stored token, on a page load.
   *
   * Answers whether there is a session rather than throwing, because "no session" is
   * the ordinary case on first visit and not an error worth reporting.
   */
  async function restore(): Promise<boolean> {
    if (useApi().token === null) {
      return false
    }

    loading.value = true

    try {
      user.value = await authService.currentUser()

      return true
    } catch {
      // An expired or revoked token. The client has already cleared it.
      user.value = null

      return false
    } finally {
      loading.value = false
    }
  }

  function signOut(): void {
    useApi().clearToken()
    user.value = null
    problem.value = null
  }

  /**
   * Called by the HTTP client when the server rejects the session mid-flight.
   *
   * Records where the person was so they can be put back there. Nothing here
   * navigates — routing belongs to the router, and a store that redirected would make
   * every test of it need one.
   */
  function sessionLost(at: string | null, message: string): void {
    user.value = null
    returnTo.value = at
    problem.value = message
  }

  function takeReturnTo(): string | null {
    const target = returnTo.value
    returnTo.value = null

    return target
  }

  return {
    user,
    loading,
    problem,
    returnTo,
    signedIn,
    spaces,
    writableSpaces,
    spaceBySlug,
    canWriteIn,
    signIn,
    restore,
    signOut,
    sessionLost,
    takeReturnTo,
  }
})

function describe(cause: unknown): string {
  if (cause instanceof ApiError) {
    // 401 on the sign-in path means the credentials were wrong, not that a session
    // expired — and the backend deliberately answers the same way for a wrong
    // password and an unknown account.
    return cause.status === 401 ? 'Nieprawidłowy adres e-mail lub hasło.' : cause.message
  }

  return cause instanceof Error ? cause.message : 'Nie udało się zalogować.'
}
