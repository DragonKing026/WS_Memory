import axios from 'axios'
import MockAdapter from 'axios-mock-adapter'
import { beforeEach, describe, expect, it } from 'vitest'

import { ApiClient, setApi } from '@/api/client'
import { isInFlight, verdictFor } from '@/features/admin/dependencyState'
import {
  dependencyOverviewSchema,
  dependencySchema,
  dependencyUpdateSchema,
  updaterStateSchema,
  type Dependency,
  type DependencyUpdate,
  type UpdaterState,
} from '@/features/admin/schemas'
import { adminService } from '@/features/admin/service'

/**
 * The dependencies panel: the contract boundary and the one piece of logic that decides
 * what the screen says.
 *
 * The endpoints do not exist yet — the backend is being written against the same
 * contract — so everything here runs against stubs. That is not a weakness of these
 * tests: the failure they guard against is not "the request was malformed" but "the
 * panel drew a reassuring conclusion from data that did not support it", and that is
 * decided entirely in `verdictFor`.
 */

function update(overrides: Partial<DependencyUpdate> = {}): DependencyUpdate {
  return {
    id: 'zlecenie-1',
    status: 'succeeded',
    fromVersion: '3.7.0',
    toVersion: '3.9.0',
    requestedBy: 'Artur Ograbek',
    requestedAt: '2026-09-13T11:00:00+00:00',
    finishedAt: '2026-09-13T11:08:00+00:00',
    log: 'kopia zapasowa ok\nprzebudowa ok\ntest semantyki ok',
    ...overrides,
  }
}

function dependency(overrides: Partial<Dependency> = {}): Dependency {
  return {
    name: 'mempalace',
    label: 'MemPalace',
    installed: '3.7.0',
    pinned: '3.7.0',
    latest: '3.9.0',
    updateAvailable: true,
    checkedAt: '2026-09-13T11:00:00+00:00',
    checkProblem: null,
    pendingUpdate: null,
    history: [],
    ...overrides,
  }
}

function updater(overrides: Partial<UpdaterState> = {}): UpdaterState {
  return {
    installed: true,
    lastHeartbeat: '2026-09-13T11:59:00+00:00',
    healthy: true,
    ...overrides,
  }
}

describe('schematy zależności', () => {
  it('przyjmuje odpowiedź dokładnie taką, jak w kontrakcie', () => {
    // Copied from the contract in TODO-015 rather than paraphrased: the point of the
    // assertion is that this exact payload goes through.
    const answer = {
      dependencies: [
        {
          name: 'mempalace',
          label: 'MemPalace',
          installed: '3.7.0',
          pinned: '3.7.0',
          latest: '3.9.0',
          updateAvailable: true,
          checkedAt: '2026-09-13T11:00:00+00:00',
          checkProblem: null,
          pendingUpdate: null,
          history: [],
        },
      ],
      updater: {
        installed: true,
        lastHeartbeat: '2026-09-13T11:59:00+00:00',
        healthy: true,
      },
    }

    expect(dependencyOverviewSchema.parse(answer).dependencies[0]?.latest).toBe('3.9.0')
  })

  it('przyjmuje null tam, gdzie kontrakt na to pozwala', () => {
    // Every one of these nulls is a real state: pałac nie odpowiedział, PyPI nie
    // odpowiedziało, nikt jeszcze nie sprawdzał. A schema that rejected any of them
    // would turn the very situation the panel exists to report into a broken screen.
    const result = dependencySchema.safeParse(
      dependency({
        installed: null,
        latest: null,
        checkedAt: null,
        checkProblem: 'PyPI nie odpowiedziało (timeout po 10 s).',
        updateAvailable: false,
        pendingUpdate: null,
      }),
    )

    expect(result.success).toBe(true)
  })

  it('przyjmuje zlecenie bez daty zakończenia i bez dziennika', () => {
    const result = dependencyUpdateSchema.safeParse(
      update({ status: 'running', finishedAt: null, log: null }),
    )

    expect(result.success).toBe(true)
  })

  it('przyjmuje agenta, który nigdy nie zgłosił pulsu', () => {
    // An agent that was never installed has nothing to have sent.
    const result = updaterStateSchema.safeParse({
      installed: false,
      lastHeartbeat: null,
      healthy: false,
    })

    expect(result.success).toBe(true)
  })

  it('przyjmuje brak przypiętej wersji', () => {
    // `pinned` is optional in the backend's own record, so an installation with the
    // environment variable unset serialises a null here. Refusing it would turn a
    // missing variable into a panel that does not render.
    expect(dependencySchema.safeParse(dependency({ pinned: null })).success).toBe(true)
  })

  it('nie przyjmuje null tam, gdzie kontrakt go nie przewiduje', () => {
    // `updateAvailable` is the backend's conclusion, and the screen has no third way to
    // render it. A null would land in a `v-if` as "no update", silently.
    expect(
      dependencySchema.safeParse(dependency({ updateAvailable: null as never })).success,
    ).toBe(false)
  })

  it('odrzuca nieznany stan zlecenia', () => {
    // A status the interface has no words for would render as an empty badge.
    expect(dependencyUpdateSchema.safeParse(update({ status: 'canceled' as never })).success).toBe(
      false,
    )
  })
})

/**
 * The four states the screen must never confuse, plus the two that fall out of the same
 * data: a request in flight, and a dependency nobody has checked yet.
 *
 * The ordering assertions are the valuable ones. Every pair below arrives as an almost
 * identical payload, and the difference between them is the difference between a panel
 * that is useful and one that is actively misleading.
 */
describe('verdictFor', () => {
  it('„jest nowsza wersja” — podaje wersję do zlecenia', () => {
    const verdict = verdictFor(dependency(), updater())

    expect(verdict.kind).toBe('updateAvailable')
    expect(verdict.offeredVersion).toBe('3.9.0')
    expect(verdict.newerVersion).toBe('3.9.0')
  })

  it('„masz najnowszą” — spokojnie i bez przycisku', () => {
    const verdict = verdictFor(
      dependency({ installed: '3.9.0', pinned: '3.9.0', updateAvailable: false }),
      updater(),
    )

    expect(verdict.kind).toBe('upToDate')
    expect(verdict.offeredVersion).toBeNull()
    expect(verdict.lastSuccessfulCheckAt).toBe('2026-09-13T11:00:00+00:00')
  })

  it('„nie udało się sprawdzić” wygrywa nad „masz najnowszą”', () => {
    // The payload is the same as the case above apart from `checkProblem`. If this
    // ordering were reversed, a panel that never reached PyPI would announce that
    // everything is up to date — which is the bug this whole feature exists to prevent.
    const verdict = verdictFor(
      dependency({
        updateAvailable: false,
        latest: null,
        checkProblem: 'PyPI nie odpowiedziało (timeout po 10 s).',
      }),
      updater(),
    )

    expect(verdict.kind).toBe('checkFailed')
    expect(verdict.checkProblem).toBe('PyPI nie odpowiedziało (timeout po 10 s).')
    // The date of the last attempt that actually succeeded — what makes the message
    // honest instead of merely apologetic.
    expect(verdict.lastSuccessfulCheckAt).toBe('2026-09-13T11:00:00+00:00')
    expect(verdict.offeredVersion).toBeNull()
  })

  it('„nie udało się sprawdzić” bez ani jednej udanej próby nie udaje wiedzy', () => {
    const verdict = verdictFor(
      dependency({
        installed: null,
        latest: null,
        updateAvailable: false,
        checkedAt: null,
        checkProblem: 'Brak sieci.',
      }),
      updater(),
    )

    expect(verdict.kind).toBe('checkFailed')
    expect(verdict.lastSuccessfulCheckAt).toBeNull()
  })

  it('brak agenta znosi przycisk, ale nie ukrywa nowszej wersji', () => {
    const verdict = verdictFor(dependency(), updater({ installed: false, lastHeartbeat: null, healthy: false }))

    expect(verdict.kind).toBe('updaterMissing')
    // Nothing to click: the click would end in a 503.
    expect(verdict.offeredVersion).toBeNull()
    // But the fact itself is still reported — withholding it would be a second lie.
    expect(verdict.newerVersion).toBe('3.9.0')
  })

  it('agent zainstalowany, lecz milczący, traktowany jest jak nieobecny', () => {
    // `installed: true` with a stale pulse is the case worth catching: a timer that has
    // been failing for weeks. Whether anything will happen on click is decided by
    // `healthy`, not by whether somebody once ran the installer.
    const verdict = verdictFor(dependency(), updater({ healthy: false }))

    expect(verdict.kind).toBe('updaterMissing')
    expect(verdict.offeredVersion).toBeNull()
  })

  it('nieudane sprawdzenie wygrywa nad brakiem agenta', () => {
    // Both are true; the one shown is the one that describes the versions on screen.
    // The agent's absence is reported separately and unconditionally by the panel, so
    // this ordering hides nothing.
    const verdict = verdictFor(
      dependency({ updateAvailable: false, checkProblem: 'PyPI nie odpowiedziało.' }),
      updater({ installed: false, lastHeartbeat: null, healthy: false }),
    )

    expect(verdict.kind).toBe('checkFailed')
    expect(verdict.offeredVersion).toBeNull()
  })

  it('trwające zlecenie wygrywa ze wszystkim', () => {
    const running = update({ status: 'running', finishedAt: null, log: null })
    const verdict = verdictFor(
      dependency({ pendingUpdate: running, checkProblem: 'PyPI nie odpowiedziało.' }),
      updater(),
    )

    expect(verdict.kind).toBe('updateRunning')
    expect(verdict.running?.id).toBe('zlecenie-1')
    expect(verdict.justFinished).toBeNull()
    // No second button: the backend would answer 409.
    expect(verdict.offeredVersion).toBeNull()
  })

  it('zlecenie zapisane, ale jeszcze niepodjęte, też jest trwające', () => {
    const verdict = verdictFor(
      dependency({ pendingUpdate: update({ status: 'pending', finishedAt: null, log: null }) }),
      updater(),
    )

    expect(verdict.kind).toBe('updateRunning')
  })

  it('zlecenie zakończone nie blokuje panelu, a jego dziennik zostaje do wglądu', () => {
    // The field is called `pendingUpdate`, but it also carries the request that has
    // just ended — which is how the log of a failed semantic test reaches the screen.
    const verdict = verdictFor(
      dependency({
        installed: '3.9.0',
        pinned: '3.9.0',
        updateAvailable: false,
        pendingUpdate: update({ status: 'failed', log: 'test semantyki: NIE ZDAŁ' }),
      }),
      updater(),
    )

    expect(verdict.kind).toBe('upToDate')
    expect(verdict.running).toBeNull()
    expect(verdict.justFinished?.log).toBe('test semantyki: NIE ZDAŁ')
  })

  it('brak jakiegokolwiek sprawdzenia nie jest tym samym co „aktualna”', () => {
    const verdict = verdictFor(
      dependency({ latest: null, updateAvailable: false, checkedAt: null }),
      updater(),
    )

    expect(verdict.kind).toBe('neverChecked')
  })

  it('rozjazd .env z obrazem widać przy każdym innym stanie', () => {
    // The quiet fifth case: somebody edited `MEMPALACE_VERSION` and did not rebuild.
    const verdict = verdictFor(dependency({ installed: '3.7.0', pinned: '3.8.0' }), updater())

    expect(verdict.drift).toEqual({ installed: '3.7.0', pinned: '3.8.0' })
    // And it does not disturb the main verdict.
    expect(verdict.kind).toBe('updateAvailable')
  })

  it('brak przypiętej wersji to nie rozjazd', () => {
    // Nothing to compare against. Reporting a mismatch here would send somebody to fix
    // a discrepancy that does not exist.
    expect(verdictFor(dependency({ pinned: null }), updater()).drift).toBeNull()
  })

  it('nieznana wersja działająca to nie rozjazd', () => {
    // `installed: null` means MCP `initialize` could not be reached. Calling that a
    // configuration mismatch would send somebody to edit `.env` for nothing.
    const verdict = verdictFor(dependency({ installed: null }), updater())

    expect(verdict.drift).toBeNull()
  })

  it('nie proponuje aktualizacji do niczego, gdy backend nie podał wersji', () => {
    // A contradictory payload (`updateAvailable` without a `latest`) would otherwise
    // produce a button labelled "Aktualizuj do null".
    const verdict = verdictFor(dependency({ latest: null, updateAvailable: true }), updater())

    expect(verdict.offeredVersion).toBeNull()
    expect(verdict.newerVersion).toBeNull()
    expect(verdict.kind).toBe('upToDate')
  })

  it('isInFlight rozpoznaje tylko stany nieostateczne', () => {
    expect(isInFlight(null)).toBe(false)
    expect(isInFlight(update({ status: 'pending' }))).toBe(true)
    expect(isInFlight(update({ status: 'running' }))).toBe(true)
    expect(isInFlight(update({ status: 'succeeded' }))).toBe(false)
    expect(isInFlight(update({ status: 'failed' }))).toBe(false)
  })
})

/**
 * The service against a stubbed transport.
 *
 * Worth testing for one reason: the contract for the two POSTs is ambiguous about the
 * envelope ("as above, for one dependency", "202 with `pendingUpdate`"), so the service
 * accepts both readings. These are the tests that say so out loud — if the backend
 * settles on one, nothing here has to change.
 */
describe('adminService', () => {
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

  it('wczytuje przegląd zależności', async () => {
    mock
      .onGet(/\/admin\/dependencies$/)
      .reply(200, { dependencies: [dependency()], updater: updater() })

    const answer = await adminService.dependencies()

    expect(answer.dependencies[0]?.name).toBe('mempalace')
    expect(answer.updater.healthy).toBe(true)
  })

  // Kształt, który backend NAPRAWDĘ wysyła. Panel i backend powstawały równolegle
  // z kontraktu opisanego prozą i przeczytały go inaczej: backend wysyła
  // `{dependency, updater}`, panel spodziewał się samej zależności. Wyszło dopiero
  // przy zszywaniu obu stron, więc oba wywołania mają na to test.
  it('sprawdzenie rozumie kształt {dependency, updater}', async () => {
    mock
      .onPost(/\/admin\/dependencies\/mempalace\/check$/)
      .reply(200, { dependency: dependency({ latest: '3.10.0' }), updater: updater() })

    const result = await adminService.check('mempalace')

    expect(result.dependency.latest).toBe('3.10.0')
    expect(result.updater?.healthy).toBe(true)
  })

  it('zlecenie rozumie kształt {dependency, updater}', async () => {
    const zlecenie = {
      id: '0199f0c0-0000-7000-8000-000000000001',
      status: 'pending' as const,
      fromVersion: '3.7.0',
      toVersion: '3.9.0',
      requestedBy: 'Artur Ograbek',
      requestedAt: '2026-09-13T12:00:00+00:00',
      finishedAt: null,
      log: null,
    }
    mock.onPost(/\/admin\/dependencies\/mempalace\/update$/).reply(202, {
      dependency: dependency({ pendingUpdate: zlecenie }),
      updater: updater(),
    })

    const ordered = await adminService.orderUpdate('mempalace', '3.9.0')

    expect(ordered.toVersion).toBe('3.9.0')
    expect(ordered.status).toBe('pending')
  })

  it('zlecenie przyjęte, ale niezwrócone, jest błędem — nie pustym wierszem', async () => {
    mock
      .onPost(/\/admin\/dependencies\/mempalace\/update$/)
      .reply(202, { dependency: dependency({ pendingUpdate: null }), updater: updater() })

    // Cicha zgoda wstawiłaby na ekran zlecenie, którego nie ma.
    await expect(adminService.orderUpdate('mempalace', '3.9.0')).rejects.toThrow(
      /nie zwrócił/i,
    )
  })

  it('sprawdzenie rozumie odpowiedź w kopercie', async () => {
    mock
      .onPost(/\/admin\/dependencies\/mempalace\/check$/)
      .reply(200, { dependencies: [dependency({ latest: '3.10.0' })], updater: updater() })

    const result = await adminService.check('mempalace')

    expect(result.dependency.latest).toBe('3.10.0')
    expect(result.updater?.healthy).toBe(true)
  })

  it('sprawdzenie rozumie odpowiedź bez koperty i nie wymyśla stanu agenta', async () => {
    mock
      .onPost(/\/admin\/dependencies\/mempalace\/check$/)
      .reply(200, dependency({ latest: '3.10.0' }))

    const result = await adminService.check('mempalace')

    expect(result.dependency.latest).toBe('3.10.0')
    // Null, not a guess: the endpoint said nothing about the agent, so the screen keeps
    // whatever it already knew.
    expect(result.updater).toBeNull()
  })

  it('zlecenie aktualizacji wysyła wskazaną wersję i zwraca zapisane zlecenie', async () => {
    mock.onPost(/\/admin\/dependencies\/mempalace\/update$/).reply((config) => {
      expect(JSON.parse(String(config.data))).toEqual({ toVersion: '3.9.0' })

      return [202, { pendingUpdate: update({ status: 'pending', finishedAt: null, log: null }) }]
    })

    const ordered = await adminService.orderUpdate('mempalace', '3.9.0')

    expect(ordered.status).toBe('pending')
    expect(ordered.toVersion).toBe('3.9.0')
  })

  it('zlecenie aktualizacji rozumie też odpowiedź bez koperty', async () => {
    mock
      .onPost(/\/admin\/dependencies\/mempalace\/update$/)
      .reply(202, update({ status: 'pending', finishedAt: null, log: null }))

    expect((await adminService.orderUpdate('mempalace', '3.9.0')).status).toBe('pending')
  })

  it('odmowa dla nie-administratora dochodzi jako nazwany problem, nie jako wyjątek axiosa', async () => {
    mock.onGet(/\/admin\/dependencies$/).reply(403, { error: 'Wymagana rola administratora.' })

    await expect(adminService.dependencies()).rejects.toMatchObject({
      name: 'ApiError',
      status: 403,
    })
  })
})
