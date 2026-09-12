import { z } from 'zod'

/**
 * Agent tokens, as the API describes them.
 *
 * `token` appears only in the response that created one, which is why it lives in its
 * own schema and why the screen must show it immediately. There is no endpoint that
 * will return it again — the database holds a digest.
 */
export const agentTokenSchema = z.object({
  id: z.string().min(1),
  label: z.string(),
  spaceScope: z.array(z.string()).nullable(),
  expiresAt: z.string().nullable(),
  revokedAt: z.string().nullable(),
  /** The field that makes a dead token identifiable — without it nobody retires any. */
  lastUsedAt: z.string().nullable(),
  lastUsedIp: z.string().nullable(),
  createdAt: z.string(),
  usable: z.boolean(),
})

export const agentTokenListSchema = z.object({
  tokens: z.array(agentTokenSchema),
})

export const issuedAgentTokenSchema = z.object({
  id: z.string().min(1),
  label: z.string(),
  spaceScope: z.array(z.string()).nullable(),
  expiresAt: z.string().nullable(),
  token: z.string().min(1),
  setupCommand: z.string().min(1),
})

export type AgentToken = z.infer<typeof agentTokenSchema>
export type IssuedAgentToken = z.infer<typeof issuedAgentTokenSchema>
