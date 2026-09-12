import { useApi } from '@/api/client'

import {
  acceptInvitationResponseSchema,
  currentUserSchema,
  parseOrExplain,
  signInResponseSchema,
  type CurrentUser,
} from './schemas'

/**
 * Everything the auth domain asks the API for.
 *
 * Thin on purpose — the state lives in the Pinia store and the HTTP rules live in the
 * client. This layer exists so a screen never has to know that the sign-in path is
 * `/login` while the current user is at `/me`.
 */
export const authService = {
  async signIn(email: string, password: string): Promise<string> {
    const answer = await useApi().post<unknown>('/login', { email, password })

    return parseOrExplain(signInResponseSchema, answer, 'logowanie').token
  },

  async currentUser(): Promise<CurrentUser> {
    const answer = await useApi().get<unknown>('/me')

    return parseOrExplain(currentUserSchema, answer, 'bieżący użytkownik')
  },

  /**
   * Turns an invitation into an account. Deliberately does not sign in afterwards:
   * the password the person just chose is the one they should use once, immediately,
   * or they will never learn whether they typed what they meant.
   */
  async acceptInvitation(token: string, displayName: string, password: string): Promise<string> {
    const answer = await useApi().post<unknown>('/invitations/accept', {
      token,
      displayName,
      password,
    })

    return parseOrExplain(acceptInvitationResponseSchema, answer, 'akceptacja zaproszenia').email
  },
}
