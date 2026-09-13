<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'

import { ApiError } from '@/api/errors'
import { isInFlight, verdictFor, type DependencyVerdict } from '@/features/admin/dependencyState'
import type {
  Dependency,
  DependencyOverview,
  DependencyUpdate,
  DependencyUpdateStatus,
} from '@/features/admin/schemas'
import { adminService } from '@/features/admin/service'
import { useAuthStore } from '@/stores/auth'

/**
 * Administration → Dependencies: what version of MemPalace is running, what is out
 * there, and a way to close the gap.
 *
 * MemPalace is pinned on purpose (`MEMPALACE_VERSION` in `.env`, the image built with
 * `pip install mempalace==…`), because updating it touches vectors and must never
 * happen by accident. The cost of that decision is the one this screen pays off:
 * nobody learns that something new was released. It was two minor versions behind for
 * a fortnight and we only found out because somebody asked PyPI by hand (TODO-015).
 *
 * Two rules shape everything below.
 *
 * **The panel must not be reassuring when it does not know.** "Nothing newer" and "we
 * could not reach PyPI" arrive as nearly the same payload, and a panel that renders
 * them the same way tells the reader the opposite of the truth. The distinction is
 * decided in `verdictFor` — a pure function with tests — rather than in this template,
 * where the ordering of `v-if`s would be the only thing standing between honest and
 * misleading.
 *
 * **A button that cannot work must not exist.** Updates are carried out by an agent on
 * the host, because no container here is given the Docker socket. When that agent is
 * absent or silent, the screen says so and points at its installation instructions
 * instead of offering a click that ends in a 503.
 */
const auth = useAuthStore()

/**
 * Access is decided here rather than by a route guard, and the difference is what the
 * reader sees. A guard can only redirect — to the home page, or to "no such page" —
 * which leaves somebody who followed a link from a colleague guessing whether they
 * mistyped the address. A refusal written on the page answers the actual question.
 *
 * The API enforces the same rule with a 403; this is not the security boundary, it is
 * the explanation. Nothing is requested when the answer would be 403 anyway, so a
 * reader without the role also gets no red error in the console.
 */
const isAdmin = computed(() => auth.user?.isGlobalAdmin === true)

const overview = ref<DependencyOverview | null>(null)
const loading = ref(false)
const problem = ref<string | null>(null)

/** Name of the dependency whose check / update is in flight — never a bare boolean,
 *  because the spinner belongs on the row that was clicked. */
const checking = ref<string | null>(null)
const ordering = ref<string | null>(null)

/** Which dependency is showing its "are you sure" panel. */
const confirming = ref<string | null>(null)

/** Ids of the update requests whose log has been expanded. */
const openLogs = ref<string[]>([])

const updater = computed(() => overview.value?.updater ?? null)

const rows = computed<{ dependency: Dependency; verdict: DependencyVerdict }[]>(() => {
  const data = overview.value

  if (data === null) {
    return []
  }

  return data.dependencies.map((dependency) => ({
    dependency,
    verdict: verdictFor(dependency, data.updater),
  }))
})

/** True while any request is written or in flight — the reason to keep refreshing. */
const anythingRunning = computed(() =>
  rows.value.some((row) => isInFlight(row.dependency.pendingUpdate)),
)

/**
 * Loads the panel.
 *
 * `silent` is used by the poller: replacing the visible data with a "Wczytuję…" every
 * few seconds would make a running update impossible to read. A failed silent refresh
 * keeps the last known data on screen and only reports the problem, because the
 * previous answer is still the best information available.
 */
async function load(silent = false): Promise<void> {
  if (!silent) {
    loading.value = true
  }
  problem.value = null

  try {
    overview.value = await adminService.dependencies()
  } catch (cause) {
    if (!silent) {
      overview.value = null
    }
    problem.value = describeFailure(cause, 'Nie udało się pobrać stanu zależności.')
  } finally {
    loading.value = false
  }
}

async function checkNow(dependency: Dependency): Promise<void> {
  checking.value = dependency.name
  problem.value = null

  try {
    const result = await adminService.check(dependency.name)
    const data = overview.value

    if (data === null) {
      // Nothing to merge into — take the whole picture instead.
      await load(true)

      return
    }

    data.dependencies = data.dependencies.map((item) =>
      item.name === result.dependency.name ? result.dependency : item,
    )

    if (result.updater !== null) {
      data.updater = result.updater
    }
  } catch (cause) {
    problem.value = describeFailure(cause, 'Nie udało się sprawdzić wersji.')
  } finally {
    checking.value = null
  }
}

/**
 * Orders the update the reader just confirmed.
 *
 * The target version is taken from the verdict, which took it from the backend's
 * `latest`. The interface never composes a version string: the value ends up in
 * `pip install mempalace==<version>` on the host, and the fewer places that can
 * influence it, the better.
 */
async function orderUpdate(dependency: Dependency, verdict: DependencyVerdict): Promise<void> {
  const target = verdict.offeredVersion

  if (target === null) {
    return
  }

  ordering.value = dependency.name
  problem.value = null

  try {
    await adminService.orderUpdate(dependency.name, target)
    confirming.value = null
    // Re-read rather than patch in the answer: the request is now something the host
    // agent owns, and the panel should start watching the shared truth immediately.
    await load(true)
  } catch (cause) {
    problem.value = describeFailure(cause, 'Nie udało się zlecić aktualizacji.')
  } finally {
    ordering.value = null
  }
}

/**
 * Keeps the panel in step with a request it does not control.
 *
 * The work happens on the host — backup, rebuild, restart, semantic test — and takes
 * minutes. Nothing pushes progress here, so the panel asks again while something is in
 * flight, and stops the moment it is not. Five seconds is chosen to be frequent enough
 * that the status feels live and rare enough to be invisible in the logs of a stack
 * that is, at that moment, restarting.
 */
const POLL_MS = 5_000
let poller: number | null = null

function stopPolling(): void {
  if (poller !== null) {
    window.clearInterval(poller)
    poller = null
  }
}

watch(anythingRunning, (running) => {
  if (running && poller === null) {
    poller = window.setInterval(() => void load(true), POLL_MS)

    return
  }

  if (!running) {
    stopPolling()
  }
})

// A timer surviving the screen would keep polling an endpoint nobody is looking at,
// and on a lost session would do it while the client tears the token down.
onUnmounted(stopPolling)

onMounted(() => {
  if (isAdmin.value) {
    void load()
  }
})

/**
 * Shows or hides one request's log.
 *
 * Takes a nullable request on purpose. In the template the call sits inside a click
 * handler, and TypeScript does not carry a `v-if`'s narrowing of an object property
 * into a callback — so the alternative would be a non-null assertion on a value the
 * template has already proved. A no-op on null says the same thing without lying to
 * the compiler.
 */
function toggleLog(update: DependencyUpdate | null): void {
  if (update === null) {
    return
  }

  openLogs.value = openLogs.value.includes(update.id)
    ? openLogs.value.filter((id) => id !== update.id)
    : [...openLogs.value, update.id]
}

const statusLabels: Record<DependencyUpdateStatus, string> = {
  pending: 'zlecone, czeka na agenta',
  running: 'w toku',
  succeeded: 'zakończone powodzeniem',
  failed: 'nieudane',
}

const statusColors: Record<DependencyUpdateStatus, 'neutral' | 'info' | 'success' | 'error'> = {
  pending: 'neutral',
  running: 'info',
  succeeded: 'success',
  failed: 'error',
}

function formatDateTime(value: string | null): string | null {
  if (value === null) {
    return null
  }

  const date = new Date(value)

  return Number.isNaN(date.getTime())
    ? value
    : date.toLocaleString('pl-PL', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      })
}

/**
 * Names the failure in the words that fit this screen.
 *
 * The shared client maps status codes to generic problems, and two of them are wrong
 * here: 409 becomes "this space requires the proposal queue" (there are no spaces on
 * this screen), and 503 falls into the catch-all "the server did not answer" — when in
 * fact the server answered clearly that the host agent is not there. Both are worth
 * saying properly, because both have a different next step.
 */
function describeFailure(cause: unknown, fallback: string): string {
  if (cause instanceof ApiError) {
    switch (cause.status) {
      case 403:
        return 'Ten panel jest dostępny tylko dla administratora globalnego.'
      case 409:
        return 'Jedno zlecenie aktualizacji już trwa. Poczekaj, aż się zakończy.'
      case 422:
        return `Backend odrzucił wskazaną wersję: ${cause.message}`
      case 503:
        return 'Agent aktualizacji nie odpowiada, więc zlecenie nie zostało zapisane. Sprawdź jego stan na hoście (docker/systemd/README.md).'
      default:
        return cause.message
    }
  }

  return cause instanceof Error ? cause.message : fallback
}
</script>

<template>
  <!-- Refusal, not a redirect: somebody who followed a colleague's link learns why the
       page is empty instead of wondering whether they mistyped the address. -->
  <div v-if="!isAdmin" class="max-w-3xl">
    <UCard>
      <template #header>
        <h1 class="font-medium">Zależności</h1>
      </template>

      <p class="font-medium">Nie masz dostępu do tego panelu.</p>
      <p class="mt-1 text-sm text-muted">
        Wersje zależności i ich aktualizacje widzi tylko administrator globalny, bo
        aktualizacja pałaca pamięci dotyka danych całej instalacji. Jeśli uważasz, że
        powinieneś tu wchodzić, poproś o tę rolę administratora.
      </p>

      <template #footer>
        <UButton :to="{ name: 'home' }" variant="subtle" color="neutral">
          Wróć do bazy wiedzy
        </UButton>
      </template>
    </UCard>
  </div>

  <div v-else class="max-w-3xl space-y-4">
    <div>
      <h1 class="text-xl font-semibold">Zależności</h1>
      <p class="text-sm text-muted">
        MemPalace jest przypięty na sztywno i to jest celowe — aktualizacja pałaca
        dotyka wektorów, więc nie ma się dziać przypadkiem. Ten ekran istnieje po to,
        żeby przypięcie nie oznaczało niewiedzy.
      </p>
    </div>

    <UAlert
      v-if="problem !== null"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :description="problem"
    />

    <p v-if="loading && overview === null" class="text-sm text-muted">Wczytuję…</p>

    <!-- The agent's state is installation-wide, so it is stated once, above the list,
         rather than repeated inside every dependency card. -->
    <UAlert
      v-else-if="updater !== null && !updater.healthy"
      color="warning"
      variant="subtle"
      icon="i-lucide-plug-zap"
      :title="
        updater.installed
          ? 'Agent aktualizacji milczy'
          : 'Agent aktualizacji nie jest zainstalowany'
      "
    >
      <template #description>
        <p>
          {{
            updater.installed
              ? 'Agent był instalowany, ale nie zgłosił pulsu od dawna — prawdopodobnie jego timer się wywraca. Do czasu naprawy nie da się zlecić aktualizacji.'
              : 'Aktualizacje wykonuje skrypt na hoście, bo żaden kontener tej instalacji nie widzi Dockera. Bez niego nie ma czym zainstalować nowej wersji, więc przycisk aktualizacji jest tu świadomie nieobecny.'
          }}
        </p>
        <p class="mt-1">
          Instalacja i diagnostyka: <code>docker/systemd/README.md</code>.
          <template v-if="updater.lastHeartbeat !== null">
            Ostatni puls: {{ formatDateTime(updater.lastHeartbeat) }}.
          </template>
        </p>
      </template>
    </UAlert>

    <UCard v-if="!loading && rows.length === 0 && problem === null">
      <p class="font-medium">Nie ma tu żadnej zależności do pilnowania.</p>
      <p class="mt-1 text-sm text-muted">
        Backend nie zgłosił ani jednej pozycji. Jeśli to niespodzianka, zajrzyj w
        dziennik workera — stan zależności zapisuje zaplanowane sprawdzenie.
      </p>
    </UCard>

    <UCard v-for="{ dependency, verdict } in rows" :key="dependency.name">
      <template #header>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="font-medium">{{ dependency.label }}</h2>
          <UBadge
            v-if="verdict.kind === 'updateAvailable'"
            color="primary"
            variant="subtle"
            icon="i-lucide-arrow-up-circle"
          >
            Jest nowsza wersja
          </UBadge>
          <UBadge
            v-else-if="verdict.kind === 'upToDate'"
            color="success"
            variant="subtle"
            icon="i-lucide-check"
          >
            Aktualna
          </UBadge>
          <UBadge
            v-else-if="verdict.kind === 'checkFailed'"
            color="warning"
            variant="subtle"
            icon="i-lucide-cloud-off"
          >
            Nie wiadomo
          </UBadge>
          <UBadge
            v-else-if="verdict.kind === 'updateRunning'"
            color="info"
            variant="subtle"
            icon="i-lucide-loader"
          >
            Aktualizacja w toku
          </UBadge>
        </div>
      </template>

      <!-- Three versions in one row, stacked on a phone. Side by side because the
           interesting thing about them is the comparison. -->
      <dl class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div>
          <dt class="text-xs uppercase tracking-wide text-muted">Działa</dt>
          <dd class="font-mono text-sm">
            {{ dependency.installed ?? 'nie wiadomo' }}
          </dd>
        </div>
        <div>
          <dt class="text-xs uppercase tracking-wide text-muted">Przypięta w .env</dt>
          <dd class="font-mono text-sm">{{ dependency.pinned ?? 'nie ustawiona' }}</dd>
        </div>
        <div>
          <dt class="text-xs uppercase tracking-wide text-muted">Najnowsza na PyPI</dt>
          <dd class="font-mono text-sm">{{ dependency.latest ?? 'nie wiadomo' }}</dd>
        </div>
      </dl>

      <!-- The quiet fifth case. Not an error, but the numbers above stop meaning what
           a reader assumes they mean, so it cannot be left unsaid. -->
      <UAlert
        v-if="verdict.drift !== null"
        class="mt-3"
        color="warning"
        variant="subtle"
        icon="i-lucide-git-compare-arrows"
        title="Obraz nie odpowiada konfiguracji"
      >
        <template #description>
          W <code>.env</code> jest {{ verdict.drift.pinned }}, a odpowiada
          {{ verdict.drift.installed }}. Ktoś zmienił zmienną i nie przebudował obrazu —
          po przebudowie ruszy wersja z <code>.env</code>, a nie ta, którą widzisz teraz.
        </template>
      </UAlert>

      <!-- State 3. Kept clearly apart from "aktualna": a panel, which failed to reach
           PyPI, saying that everything is up to date would be stating the opposite of
           what it knows. -->
      <UAlert
        v-if="verdict.kind === 'checkFailed'"
        class="mt-3"
        color="warning"
        variant="subtle"
        icon="i-lucide-cloud-off"
        title="Nie udało się sprawdzić, czy jest coś nowszego"
      >
        <template #description>
          <p>{{ verdict.checkProblem }}</p>
          <p class="mt-1">
            <template v-if="verdict.lastSuccessfulCheckAt !== null">
              Wersje powyżej pochodzą z ostatniej udanej próby:
              {{ formatDateTime(verdict.lastSuccessfulCheckAt) }}. Od tego czasu mogło
              wyjść coś nowego.
            </template>
            <template v-else>
              Nie było jeszcze ani jednej udanej próby, więc o najnowszej wersji nie
              wiadomo nic.
            </template>
          </p>
        </template>
      </UAlert>

      <!-- State 2. -->
      <p v-else-if="verdict.kind === 'upToDate'" class="mt-3 text-sm text-muted">
        Nie ma nic nowszego. Sprawdzone {{ formatDateTime(verdict.lastSuccessfulCheckAt) }};
        sprawdzenie powtarza się samo co sześć godzin.
      </p>

      <p v-else-if="verdict.kind === 'neverChecked'" class="mt-3 text-sm text-muted">
        Jeszcze nie sprawdzano. Zaplanowane sprawdzenie ruszy samo, ale możesz je
        wywołać teraz.
      </p>

      <!-- State 4, at the level of this one dependency: the newer version is named
           even though nothing can install it, because withholding it would be the
           second lie after "no button". -->
      <p
        v-else-if="verdict.kind === 'updaterMissing'"
        class="mt-3 text-sm text-muted"
      >
        <template v-if="verdict.newerVersion !== null">
          Jest nowsza wersja ({{ verdict.newerVersion }}), ale nie ma jej czym zainstalować —
          patrz komunikat o agencie powyżej.
        </template>
        <template v-else>
          Nic nowszego nie znaleziono. Aktualizacji i tak nie da się teraz zlecić —
          patrz komunikat o agencie powyżej.
        </template>
      </p>

      <!-- State 1, and the confirmation that goes with it. -->
      <div v-else-if="verdict.kind === 'updateAvailable'" class="mt-3">
        <p class="text-sm">
          Dostępna jest wersja <strong>{{ verdict.newerVersion }}</strong
          >. Sprawdzone {{ formatDateTime(verdict.lastSuccessfulCheckAt) }}.
        </p>

        <!--
          The confirmation spells out the whole operation instead of asking "jesteś
          pewien?". This is not ceremony: the run dumps and replaces data the entire
          installation depends on, the memory service goes away for part of it, and
          semantic search stops answering while that happens. Somebody agreeing to it
          has to know that beforehand — afterwards is not consent, it is a surprise.
        -->
        <UAlert
          v-if="confirming === dependency.name"
          class="mt-3"
          color="warning"
          variant="subtle"
          icon="i-lucide-shield-alert"
          :title="`Aktualizacja ${dependency.label} do ${verdict.newerVersion} — co się stanie`"
        >
          <template #description>
            <ol class="list-decimal space-y-1 pl-5">
              <li>Kopia zapasowa schematu <code>palace</code> w bazie (przed czymkolwiek innym).</li>
              <li>Przebudowa obrazu pałaca na wskazaną wersję.</li>
              <li>Restart usługi pamięci — kontener zostaje wymieniony na nowy.</li>
              <li>Test semantyki, żeby wyłapać ciche pogorszenie trafności wyszukiwania.</li>
            </ol>
            <p class="mt-2">
              <strong
                >Przez kilka minut wyszukiwanie semantyczne nie będzie działać</strong
              >
              — pytania do pamięci w tym czasie nie dostaną odpowiedzi. Cała droga trwa
              zwykle kilka minut i możesz ją śledzić na tym ekranie.
            </p>
            <p class="mt-1">
              Wykonanie zleca skrypt na hoście, więc od kliknięcia do startu może minąć
              chwila.
            </p>

            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
              <UButton
                color="warning"
                :loading="ordering === dependency.name"
                @click="orderUpdate(dependency, verdict)"
              >
                Rozumiem, aktualizuj do {{ verdict.newerVersion }}
              </UButton>
              <UButton variant="subtle" color="neutral" @click="confirming = null">
                Nie teraz
              </UButton>
            </div>
          </template>
        </UAlert>
      </div>

      <!-- A request in flight. The status is named in words, because "pending" and
           "running" mean different things here: the first is a row nobody has picked
           up yet, the second is a host doing the work. -->
      <div v-if="verdict.running !== null" class="mt-3 rounded border border-default p-3">
        <div class="flex flex-wrap items-center gap-2">
          <UBadge :color="statusColors[verdict.running.status]" variant="subtle">
            {{ statusLabels[verdict.running.status] }}
          </UBadge>
          <span class="text-sm">
            {{ verdict.running.fromVersion }} → {{ verdict.running.toVersion }}
          </span>
        </div>
        <p class="mt-1 text-xs text-muted">
          Zlecił {{ verdict.running.requestedBy }},
          {{ formatDateTime(verdict.running.requestedAt) }}. Odświeżam co kilka sekund,
          aż się skończy.
        </p>
        <p v-if="verdict.running.status === 'running'" class="mt-1 text-xs text-muted">
          Trwa kopia zapasowa, przebudowa, restart i test semantyki — w tym czasie
          wyszukiwanie semantyczne nie odpowiada.
        </p>
      </div>

      <!-- The request that has just ended, with its log. Expanded on demand: it is
           long, and the result is usually enough — but when the semantic test fails,
           this is the only place that says so. -->
      <div
        v-if="verdict.justFinished !== null"
        class="mt-3 rounded border border-default p-3"
      >
        <div class="flex flex-wrap items-center gap-2">
          <UBadge :color="statusColors[verdict.justFinished.status]" variant="subtle">
            {{ statusLabels[verdict.justFinished.status] }}
          </UBadge>
          <span class="text-sm">
            {{ verdict.justFinished.fromVersion }} → {{ verdict.justFinished.toVersion }}
          </span>
        </div>
        <p class="mt-1 text-xs text-muted">
          Zlecił {{ verdict.justFinished.requestedBy }}, zakończone
          {{ formatDateTime(verdict.justFinished.finishedAt) ?? 'bez daty' }}.
        </p>

        <template v-if="verdict.justFinished.log !== null">
          <UButton
            class="mt-2"
            size="xs"
            variant="subtle"
            color="neutral"
            :icon="
              openLogs.includes(verdict.justFinished.id)
                ? 'i-lucide-chevron-down'
                : 'i-lucide-chevron-right'
            "
            @click="toggleLog(verdict.justFinished)"
          >
            {{ openLogs.includes(verdict.justFinished.id) ? 'Ukryj dziennik' : 'Pokaż dziennik' }}
          </UButton>
          <pre
            v-if="openLogs.includes(verdict.justFinished.id)"
            class="mt-2 max-h-80 overflow-auto rounded bg-elevated p-3 text-xs whitespace-pre-wrap break-words"
            >{{ verdict.justFinished.log }}</pre
          >
        </template>
        <p v-else class="mt-2 text-xs text-muted">Agent nie zostawił dziennika.</p>
      </div>

      <template #footer>
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
          <UButton
            icon="i-lucide-refresh-cw"
            variant="subtle"
            :loading="checking === dependency.name"
            @click="checkNow(dependency)"
          >
            Sprawdź teraz
          </UButton>

          <!-- Offered only when it can do something: `offeredVersion` is non-null
               solely in the `updateAvailable` verdict, which already rules out a
               missing agent and a request in flight. -->
          <UButton
            v-if="verdict.offeredVersion !== null && confirming !== dependency.name"
            color="warning"
            icon="i-lucide-arrow-up-circle"
            @click="confirming = dependency.name"
          >
            Aktualizuj do {{ verdict.offeredVersion }}
          </UButton>
        </div>
      </template>
    </UCard>

    <!-- History last, and collapsed to one line per request: it is reference material,
         consulted when something went wrong, not the reason anybody opens the screen. -->
    <UCard v-for="{ dependency } in rows" :key="`${dependency.name}-historia`">
      <template #header>
        <h2 class="font-medium">Historia zleceń — {{ dependency.label }}</h2>
      </template>

      <p v-if="dependency.history.length === 0" class="text-sm text-muted">
        Nikt jeszcze nie zlecał aktualizacji tej zależności.
      </p>

      <ul v-else class="divide-y divide-default">
        <li v-for="entry in dependency.history" :key="entry.id" class="py-3">
          <div class="flex flex-wrap items-center gap-2">
            <UBadge :color="statusColors[entry.status]" variant="subtle" size="sm">
              {{ statusLabels[entry.status] }}
            </UBadge>
            <span class="text-sm">{{ entry.fromVersion }} → {{ entry.toVersion }}</span>
          </div>
          <p class="mt-1 text-xs text-muted">
            {{ entry.requestedBy }} · zlecone {{ formatDateTime(entry.requestedAt) }}
            <template v-if="entry.finishedAt !== null">
              · zakończone {{ formatDateTime(entry.finishedAt) }}
            </template>
          </p>

          <template v-if="entry.log !== null">
            <UButton
              class="mt-2"
              size="xs"
              variant="ghost"
              color="neutral"
              :icon="
                openLogs.includes(entry.id) ? 'i-lucide-chevron-down' : 'i-lucide-chevron-right'
              "
              @click="toggleLog(entry)"
            >
              {{ openLogs.includes(entry.id) ? 'Ukryj dziennik' : 'Pokaż dziennik' }}
            </UButton>
            <pre
              v-if="openLogs.includes(entry.id)"
              class="mt-2 max-h-80 overflow-auto rounded bg-elevated p-3 text-xs whitespace-pre-wrap break-words"
              >{{ entry.log }}</pre
            >
          </template>
        </li>
      </ul>
    </UCard>
  </div>
</template>
