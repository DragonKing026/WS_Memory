<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'

import type { AuditEntry } from '@/features/admin/auditSchemas'
import { adminAuditService, type AuditFilters } from '@/features/admin/auditService'
import { actorAppearance, describeActors, formatTarget, hasTarget } from '@/features/admin/auditState'
import { describePage, formatDateTimeOr } from '@/features/admin/format'
import type { PageMeta } from '@/features/admin/listing'
import { describeAdminFailure } from '@/features/admin/refusals'
import { adminSpaceService } from '@/features/admin/spaceService'

/**
 * Administration → Audit log: who did what, and with whose hands.
 *
 * Everything on this screen serves one question. Nobody opens an audit log to browse; they
 * open it because something happened and the name attached to it is not enough — an agent
 * acts with a person's token, so its rows carry that person's name. `actorKind` is the
 * column that separates "Artur wrote this" from "Artur's agent wrote this", and it is the
 * reason the three kinds are given their own word, colour and icon rather than being left
 * implicit in a name.
 *
 * The log is read-only, and the screen says so out loud. There is no endpoint to edit or
 * clear an entry and there will not be one: a log that can be tidied is a log whose silence
 * proves nothing, and the whole point of writing agent activity down is that it cannot be
 * quietly unwritten.
 */
const entries = ref<AuditEntry[]>([])
const meta = ref<PageMeta | null>(null)
const busy = ref(false)
const problem = ref<string | null>(null)

const action = ref<string | null>(null)
const space = ref<string | null>(null)
const actor = ref('')
const since = ref('')
const before = ref('')

/**
 * The action vocabulary, accumulated rather than replaced.
 *
 * The answer carries `actions`, and a **filtered** answer may sensibly carry only the
 * actions present in it — which would empty the picker the moment somebody used it, leaving
 * them unable to pick anything else. So the known vocabulary only ever grows.
 */
const knownActions = ref<string[]>([])

/** Spaces for the filter, from the administration catalogue rather than from the reader's
 *  own memberships: the log spans the whole installation, and offering only your own spaces
 *  would quietly hide the rows somebody came here to find. */
const spaceSlugs = ref<string[]>([])

/** Ids of the entries whose `target` is unfolded. */
const openTargets = ref<string[]>([])

const actionItems = computed(() => [
  { label: 'Wszystkie akcje', value: null },
  ...knownActions.value.map((name) => ({ label: name, value: name })),
])

const spaceItems = computed(() => [
  { label: 'Wszystkie przestrzenie', value: null },
  ...spaceSlugs.value.map((slug) => ({ label: slug, value: slug })),
])

const summary = computed(() =>
  meta.value === null
    ? null
    : describePage(entries.value.length, meta.value, ['wpis', 'wpisy', 'wpisów']),
)

const actorBreakdown = computed(() => describeActors(entries.value))

function currentFilters(): AuditFilters {
  return {
    action: action.value,
    space: space.value,
    actor: actor.value.trim() === '' ? null : actor.value.trim(),
    since: since.value === '' ? null : since.value,
    before: before.value === '' ? null : before.value,
  }
}

async function load(more = false): Promise<void> {
  busy.value = true
  problem.value = null

  try {
    const page = await adminAuditService.list(currentFilters(), more ? entries.value.length : 0)

    entries.value = more ? [...entries.value, ...page.entries] : page.entries
    meta.value = page
    knownActions.value = [...new Set([...knownActions.value, ...page.actions])].sort()
  } catch (cause) {
    if (!more) {
      entries.value = []
      meta.value = null
    }
    problem.value = describeAdminFailure(cause, 'Nie udało się pobrać dziennika.')
  } finally {
    busy.value = false
  }
}

/**
 * Loads the space list for the filter.
 *
 * A failure here is not reported: the filter simply offers nothing but "wszystkie", and the
 * log itself is on screen. An error message about a dropdown would compete with the reason
 * somebody is reading this page.
 */
async function loadSpaces(): Promise<void> {
  try {
    // One request, deliberately large: this is a picker, and paging a dropdown would hide
    // exactly the rarely-used space somebody is looking for.
    const page = await adminSpaceService.list(0, 200)

    spaceSlugs.value = page.spaces.map((entry) => entry.slug)
  } catch {
    spaceSlugs.value = []
  }
}

function toggleTarget(entry: AuditEntry): void {
  openTargets.value = openTargets.value.includes(entry.id)
    ? openTargets.value.filter((id) => id !== entry.id)
    : [...openTargets.value, entry.id]
}

/** Filtering by the actor of a row that is already on screen — the value comes from the
 *  data, so there is no guessing how the backend matches it. */
function filterByActor(entry: AuditEntry): void {
  actor.value = entry.actor
}

/**
 * Filters reload from the first page, after a pause.
 *
 * Appending would mix two answers into one list, and the pause exists for the actor field:
 * a request per keystroke arrives out of order, and the list then settles on whichever
 * reply was slowest.
 */
const FILTER_DELAY_MS = 300
let debounce: number | null = null

watch([action, space, actor, since, before], () => {
  if (debounce !== null) {
    window.clearTimeout(debounce)
  }

  debounce = window.setTimeout(() => void load(), FILTER_DELAY_MS)
})

onUnmounted(() => {
  if (debounce !== null) {
    window.clearTimeout(debounce)
  }
})

onMounted(() => {
  void load()
  void loadSpaces()
})
</script>

<template>
  <div class="max-w-5xl space-y-4">
    <div>
      <h1 class="text-xl font-semibold">Dziennik audytu</h1>
      <p class="text-sm text-muted">
        Każde wyszukanie, każdy zapis, każde logowanie i każde wywołanie agenta. Przy
        wpisach agentów widnieje nazwa osoby, której token został użyty — dlatego kolumna
        „kto" rozróżnia człowieka, agenta i system.
      </p>
      <p class="mt-1 text-sm text-muted">
        <strong>Dziennika nie da się zmieniać ani czyścić</strong> — nie ma na to ani
        przycisku, ani endpointu. Wpisy zostają 24 miesiące, potem zamieniają się w
        statystyki.
      </p>
    </div>

    <div class="flex flex-wrap items-end gap-3">
      <USelect v-model="action" :items="actionItems" value-key="value" class="min-w-44" />
      <USelect v-model="space" :items="spaceItems" value-key="value" class="min-w-52" />

      <UFormField label="Kto" size="xs">
        <UInput v-model="actor" placeholder="nazwa z kolumny „kto”" />
      </UFormField>
      <UFormField label="Od" size="xs">
        <UInput v-model="since" type="date" />
      </UFormField>
      <UFormField label="Do" size="xs">
        <UInput v-model="before" type="date" />
      </UFormField>
    </div>

    <UAlert
      v-if="problem !== null"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :description="problem"
    />

    <p v-else-if="busy && entries.length === 0" class="text-sm text-muted">Wczytuję…</p>

    <template v-else>
      <p class="text-sm text-muted">
        <template v-if="summary !== null">{{ summary }}</template>
        <!-- Policzone po tym, co wczytane, i tak też nazwane: backend przysyła sumę dla
             zapytania, nie rozbicie na sprawców. -->
        <template v-if="actorBreakdown !== null">
          · w tej partii: {{ actorBreakdown }}
        </template>
      </p>

      <UCard v-if="entries.length === 0">
        <p class="font-medium">Żaden wpis nie odpowiada tym filtrom.</p>
        <p class="mt-1 text-sm text-muted">
          Pusty wynik nie znaczy, że nic się nie działo — znaczy, że nic takiego nie zapisano
          w tym zakresie. Poszerz daty albo zdejmij filtr akcji.
        </p>
      </UCard>

      <!-- Tabela w kontenerze z własnym przewijaniem: na telefonie ma się przewijać ona,
           a nie cała strona. -->
      <div v-else class="overflow-x-auto rounded border border-default">
        <table class="min-w-full text-sm">
          <thead class="bg-elevated text-left text-xs uppercase tracking-wide text-muted">
            <tr>
              <th class="px-3 py-2 font-medium">Kiedy</th>
              <th class="px-3 py-2 font-medium">Kto</th>
              <th class="px-3 py-2 font-medium">Akcja</th>
              <th class="px-3 py-2 font-medium">Przestrzeń</th>
              <th class="px-3 py-2 font-medium">Szczegóły</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-default">
            <template v-for="entry in entries" :key="entry.id">
              <tr>
                <td class="whitespace-nowrap px-3 py-2 align-top text-muted">
                  {{ formatDateTimeOr(entry.createdAt, 'bez daty') }}
                </td>

                <td class="px-3 py-2 align-top">
                  <div class="flex flex-col gap-1">
                    <UBadge
                      :color="actorAppearance(entry.actorKind).color"
                      :icon="actorAppearance(entry.actorKind).icon"
                      variant="subtle"
                      size="sm"
                      class="w-fit"
                    >
                      {{ actorAppearance(entry.actorKind).label }}
                    </UBadge>
                    <button
                      type="button"
                      class="text-left underline decoration-dotted hover:text-primary"
                      :title="`Pokaż tylko wpisy: ${entry.actor}`"
                      @click="filterByActor(entry)"
                    >
                      {{ entry.actor }}
                    </button>
                  </div>
                </td>

                <td class="px-3 py-2 align-top">
                  <code class="text-xs">{{ entry.action }}</code>
                </td>

                <td class="px-3 py-2 align-top">
                  <span v-if="entry.space === null" class="text-muted">—</span>
                  <code v-else class="text-xs">{{ entry.space }}</code>
                </td>

                <td class="px-3 py-2 align-top">
                  <div class="flex flex-wrap items-center gap-2">
                    <UButton
                      v-if="hasTarget(entry)"
                      size="xs"
                      variant="ghost"
                      color="neutral"
                      :icon="
                        openTargets.includes(entry.id)
                          ? 'i-lucide-chevron-down'
                          : 'i-lucide-chevron-right'
                      "
                      @click="toggleTarget(entry)"
                    >
                      {{ openTargets.includes(entry.id) ? 'Ukryj' : 'Czego dotyczy' }}
                    </UButton>
                    <span v-else class="text-xs text-muted">bez szczegółów</span>

                    <span v-if="entry.ip !== null" class="text-xs text-muted">{{ entry.ip }}</span>
                  </div>
                </td>
              </tr>

              <!-- `target` w całości, jako JSON. Kształt jest inny dla każdej akcji, a
                   rozpisanie go po polach gubiłoby przy awarii właśnie ten klucz, który
                   miał znaczenie. -->
              <tr v-if="openTargets.includes(entry.id)">
                <td colspan="5" class="px-3 pb-3">
                  <pre
                    class="max-h-80 overflow-auto rounded bg-elevated p-3 text-xs whitespace-pre-wrap break-words"
                    >{{ formatTarget(entry) }}</pre
                  >
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <UButton
        v-if="meta !== null && meta.hasMore"
        variant="subtle"
        size="sm"
        :loading="busy"
        @click="load(true)"
      >
        Pokaż kolejne
      </UButton>
    </template>
  </div>
</template>
