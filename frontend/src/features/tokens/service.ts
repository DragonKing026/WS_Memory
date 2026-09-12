import { useApi } from '@/api/client'
import { parseOrExplain } from '@/features/auth/schemas'

import {
  agentTokenListSchema,
  issuedAgentTokenSchema,
  type AgentToken,
  type IssuedAgentToken,
} from './schemas'

export const tokenService = {
  async list(): Promise<AgentToken[]> {
    const answer = await useApi().get<unknown>('/agent-tokens')

    return parseOrExplain(agentTokenListSchema, answer, 'lista tokenów').tokens
  },

  async issue(label: string, spaceScope: string[] | null): Promise<IssuedAgentToken> {
    const answer = await useApi().post<unknown>('/agent-tokens', {
      label,
      // Omitted rather than sent as null: absent means "everything the owner may see",
      // while an empty array means "nothing" — two different things (docs/02).
      ...(spaceScope === null ? {} : { spaceScope }),
    })

    return parseOrExplain(issuedAgentTokenSchema, answer, 'wystawiony token')
  },

  async revoke(id: string): Promise<void> {
    await useApi().delete<unknown>(`/agent-tokens/${encodeURIComponent(id)}`)
  },
}
