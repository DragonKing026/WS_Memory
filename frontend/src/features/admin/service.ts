import { useApi } from '@/api/client'
import { parseOrExplain } from '@/features/auth/schemas'

import {
  dependencyCheckAnswerSchema,
  dependencyOverviewSchema,
  updateOrderedAnswerSchema,
  type Dependency,
  type DependencyOverview,
  type DependencyUpdate,
  type UpdaterState,
} from './schemas'

/**
 * The result of checking a single dependency, normalised.
 *
 * `updater` is null when the endpoint answered with the bare dependency and said
 * nothing about the agent — in which case the screen keeps what it already knew rather
 * than inventing an agent state it was not told about.
 */
export interface DependencyCheckResult {
  dependency: Dependency
  updater: UpdaterState | null
}

/**
 * Everything the administration panel asks the API for.
 *
 * Thin, like the other services: the screen should not know that a check is a POST
 * (it is — checking writes the result to `ws.dependency_state`, so it is not a read),
 * nor that ordering an update answers 202 rather than 200.
 */
export const adminService = {
  async dependencies(): Promise<DependencyOverview> {
    const answer = await useApi().get<unknown>('/admin/dependencies')

    return parseOrExplain(dependencyOverviewSchema, answer, 'zależności')
  },

  /**
   * Asks the backend to check one dependency now, instead of waiting for the
   * scheduler's next six-hourly pass.
   */
  async check(name: string): Promise<DependencyCheckResult> {
    const answer = await useApi().post<unknown>(
      `/admin/dependencies/${encodeURIComponent(name)}/check`,
    )
    const parsed = parseOrExplain(dependencyCheckAnswerSchema, answer, 'sprawdzenie zależności')

    // What the backend actually sends.
    if ('dependency' in parsed) {
      return { dependency: parsed.dependency, updater: parsed.updater }
    }

    if ('dependencies' in parsed) {
      const first = parsed.dependencies[0]

      if (first === undefined) {
        // An envelope with an empty list is not a valid answer to "check this one".
        throw new Error('Sprawdzenie nie zwróciło żadnej zależności.')
      }

      return { dependency: first, updater: parsed.updater }
    }

    return { dependency: parsed, updater: null }
  },

  /**
   * Orders an update. Returns the recorded request, not a finished update: the backend
   * only writes the row, and a host-side agent picks it up — which is why the screen
   * then has to watch for it to progress rather than await a result here.
   *
   * `toVersion` is sent as the backend gave it to us (`latest`), never as something the
   * interface composed. It ends up in `pip install mempalace==<version>` on the host,
   * so it is validated by pattern on the way in and again in the agent; the interface
   * being one more place that cannot invent a value is worth keeping.
   */
  async orderUpdate(name: string, toVersion: string): Promise<DependencyUpdate> {
    const answer = await useApi().post<unknown>(
      `/admin/dependencies/${encodeURIComponent(name)}/update`,
      { toVersion },
    )
    const parsed = parseOrExplain(updateOrderedAnswerSchema, answer, 'zlecenie aktualizacji')

    if ('dependency' in parsed) {
      const recorded = parsed.dependency.pendingUpdate

      if (recorded === null) {
        // The backend accepted the order and then described a dependency with no
        // request on it. Nothing sensible to show, and silently returning a made-up
        // request would put a fake row on the screen.
        throw new Error('Backend przyjął zlecenie, ale go nie zwrócił.')
      }

      return recorded
    }

    return 'pendingUpdate' in parsed ? parsed.pendingUpdate : parsed
  },
}
