<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import SearchResult from '@/components/search/SearchResult.vue'
import { kindLabels, type SearchAnswer, type SearchMode } from '@/features/search/schemas'
import { searchService } from '@/features/search/service'
import { useAuthStore } from '@/stores/auth'

/**
 * The search screen — the reason people open WS_Memory at all.
 *
 * Three things here are deliberate rather than decorative.
 *
 * **The mode is explained, not just offered.** "Semantic" and "lexical" mean nothing to
 * most people, and picking the wrong one gives a useless answer from a working system.
 * The explanation sits under the toggle, in one sentence, always visible.
 *
 * **Weak matches are separated and folded away.** Semantic search always answers, only
 * progressively worse, so a flat list makes the twentieth result look like an answer.
 * They stay reachable — sometimes the answer really is down there — but they do not
 * pose as answers.
 *
 * **The empty state says what was searched for.** A blank page leaves the reader
 * wondering whether they mistyped, whether it is broken, or whether the knowledge is
 * genuinely missing. Those need different reactions, so the screen names the query and
 * offers the next move.
 */
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const query = ref('')
const mode = ref<SearchMode>('semantic')
const spaces = ref<string[]>([])
const kind = ref<string | null>(null)
const since = ref('')
const before = ref('')
const onlyVerified = ref(false)
const showWeak = ref(false)

const answer = ref<SearchAnswer | null>(null)
const busy = ref(false)
const problem = ref<string | null>(null)

/** The request in flight, so an answer to an abandoned query cannot overwrite a newer
 *  one — the classic way a search box ends up showing results for what you typed two
 *  keystrokes ago. */
let inFlight: AbortController | null = null

const kindOptions = [
  { label: 'Wszystkie klasy', value: null },
  ...Object.entries(kindLabels)
    // A graph fact is not a drawer and never comes back from a search; offering it as
    // a filter would be offering a way to get nothing.
    .filter(([value]) => value !== 'kg_fact')
    .map(([value, label]) => ({ label, value })),
]

const spaceOptions = computed(() =>
  auth.spaces.map((space) => ({ label: space.name, value: space.slug })),
)

const modeExplanation = computed(() =>
  mode.value === 'semantic'
    ? 'Szuka znaczeniem: „wolne dni” znajdzie zasady urlopów, choć nie ma tam tego słowa.'
    : 'Szuka dokładnych słów: dobre do nazw, identyfikatorów i numerów decyzji.',
)

const visibleResults = computed(() => filtered(answer.value?.results ?? []))
const visibleWeak = computed(() => filtered(answer.value?.weakResults ?? []))

/**
 * Hides unverified results from what is on screen.
 *
 * A view filter, not a search filter, and labelled as one: it narrows the page that
 * came back rather than asking the server to look further. Calling it "search only
 * verified" would promise something else — that the count means "how much verified
 * knowledge exists" — which it would not.
 */
function filtered(hits: SearchAnswer['results']): SearchAnswer['results'] {
  return onlyVerified.value ? hits.filter((hit) => hit.verified) : hits
}

const hiddenByVerified = computed(() => {
  if (!onlyVerified.value || answer.value === null) {
    return 0
  }

  return (
    answer.value.results.length +
    answer.value.weakResults.length -
    visibleResults.value.length -
    visibleWeak.value.length
  )
})

async function run(): Promise<void> {
  const text = query.value.trim()

  if (text === '') {
    answer.value = null
    problem.value = null

    return
  }

  inFlight?.abort()
  const controller = new AbortController()
  inFlight = controller

  busy.value = true
  problem.value = null

  try {
    const result = await searchService.run(
      {
        query: text,
        mode: mode.value,
        spaces: spaces.value,
        kind: kind.value,
        since: since.value === '' ? null : since.value,
        before: before.value === '' ? null : before.value,
      },
      controller.signal,
    )

    // A late answer from an aborted request must not replace a newer one.
    if (controller === inFlight) {
      answer.value = result
      showWeak.value = false
    }
  } catch (error) {
    if (controller.signal.aborted) {
      return
    }

    problem.value = error instanceof Error ? error.message : 'Nie udało się wyszukać.'
    answer.value = null
  } finally {
    if (controller === inFlight) {
      busy.value = false
    }
  }
}

/** Keeps the address bar in step, so a result list can be shared or reloaded. */
function pushQueryToUrl(): void {
  void router.replace({
    name: 'home',
    query: query.value.trim() === '' ? {} : { q: query.value.trim(), tryb: mode.value },
  })
}

function submit(): void {
  pushQueryToUrl()
  void run()
}

onMounted(() => {
  const fromUrl = route.query.q
  const modeFromUrl = route.query.tryb

  if (typeof fromUrl === 'string' && fromUrl !== '') {
    query.value = fromUrl
  }
  if (modeFromUrl === 'semantic' || modeFromUrl === 'lexical') {
    mode.value = modeFromUrl
  }
  if (query.value !== '') {
    void run()
  }
})

// The header search box navigates here with `?q=`; without this the second search from
// the header would change the address and nothing else.
watch(
  () => route.query.q,
  (value) => {
    if (typeof value === 'string' && value !== query.value) {
      query.value = value
      void run()
    }
  },
)

// Changing a filter re-runs immediately: a filter that needs a second click on "search"
// reads as broken.
watch([mode, spaces, kind, since, before], () => {
  if (query.value.trim() !== '') {
    pushQueryToUrl()
    void run()
  }
})
</script>

<template>
  <div class="max-w-4xl space-y-5">
    <form class="space-y-3" @submit.prevent="submit">
      <UInput
        v-model="query"
        size="xl"
        icon="i-lucide-search"
        placeholder="Czego szukasz?"
        autofocus
        class="w-full"
      />

      <div class="flex flex-wrap items-center gap-3">
        <UTabs
          v-model="mode"
          size="sm"
          :items="[
            { label: 'Znaczeniem', value: 'semantic' },
            { label: 'Dokładnie', value: 'lexical' },
          ]"
          :content="false"
        />
        <p class="text-xs text-muted">{{ modeExplanation }}</p>
      </div>

      <div class="flex flex-wrap items-end gap-3">
        <USelectMenu
          v-model="spaces"
          multiple
          :items="spaceOptions"
          value-key="value"
          placeholder="Wszystkie przestrzenie"
          class="min-w-52"
        />
        <USelect v-model="kind" :items="kindOptions" value-key="value" class="min-w-44" />

        <UFormField label="Od" size="xs">
          <UInput v-model="since" type="date" />
        </UFormField>
        <UFormField label="Do" size="xs">
          <UInput v-model="before" type="date" />
        </UFormField>

        <USwitch v-model="onlyVerified" label="Ukryj niezweryfikowane" size="sm" />
      </div>
    </form>

    <UAlert
      v-if="problem"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :description="problem"
    />

    <div v-if="busy" class="text-sm text-muted">Szukam…</div>

    <template v-else-if="answer">
      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <p class="text-sm text-muted">
          {{ answer.count }}
          {{ answer.count === 1 ? 'wynik' : 'wyników' }} dla „{{ answer.query }}”
        </p>
        <p v-if="!answer.coverage.fullText" class="text-xs text-muted">
          {{ answer.coverage.note }}
        </p>
      </div>

      <p v-if="hiddenByVerified > 0" class="text-xs text-muted">
        Ukryto {{ hiddenByVerified }} niezweryfikowanych.
      </p>

      <!-- Empty state that says what happened and what to do about it. The three
           suggestions match the three real causes: wrong mode, too narrow a filter,
           or knowledge that genuinely is not written down anywhere. -->
      <UCard v-if="answer.count === 0">
        <p class="font-medium">Nic nie znaleziono dla „{{ answer.query }}”.</p>
        <ul class="mt-2 text-sm text-muted list-disc pl-5 space-y-1">
          <li v-if="mode === 'lexical'">
            Spróbuj trybu <button type="button" class="underline" @click="mode = 'semantic'">
              „Znaczeniem”</button> — znajdzie treść opisaną innymi słowami.
          </li>
          <li v-else>
            Spróbuj trybu
            <button type="button" class="underline" @click="mode = 'lexical'">
              „Dokładnie”</button> — jeśli szukasz konkretnej nazwy albo numeru.
          </li>
          <li v-if="spaces.length > 0 || kind !== null || since !== '' || before !== ''">
            Zdejmij filtry — szukasz teraz w zawężonym zakresie.
          </li>
          <li>
            Jeśli tego naprawdę nie ma, to luka w dokumentacji. Warto ją uzupełnić,
            zamiast pytać kogoś na czacie.
          </li>
        </ul>
      </UCard>

      <div v-else>
        <SearchResult
          v-for="hit in visibleResults"
          :key="hit.drawer ?? hit.title"
          :hit="hit"
          :mode="answer.mode"
        />

        <p
          v-if="visibleResults.length === 0 && visibleWeak.length > 0"
          class="py-4 text-sm text-muted"
        >
          Nic nie pasuje mocno. Poniżej dalsze, słabiej pasujące.
        </p>

        <!-- Visible but folded: these are not answers, and presenting them as such is
             how a search screen loses trust. Reachable, because sometimes they are. -->
        <div v-if="visibleWeak.length > 0" class="mt-4">
          <button
            type="button"
            class="text-sm text-muted underline"
            @click="showWeak = !showWeak"
          >
            {{ showWeak ? 'Ukryj' : 'Pokaż' }} dalsze, słabiej pasujące ({{
              visibleWeak.length
            }})
          </button>

          <div v-if="showWeak" class="mt-2 opacity-75">
            <SearchResult
              v-for="hit in visibleWeak"
              :key="hit.drawer ?? hit.title"
              :hit="hit"
              :mode="answer.mode"
            />
          </div>
        </div>
      </div>
    </template>

    <!-- Nothing typed yet. Rather than an empty screen, the one thing worth knowing. -->
    <UCard v-else>
      <template #header>
        <h2 class="font-medium">Cześć, {{ auth.user?.displayName }}.</h2>
      </template>

      <p class="text-sm text-muted">
        Masz dostęp do {{ auth.spaces.length }} przestrzeni, z czego do
        {{ auth.writableSpaces.length }} z prawem zapisu. Zacznij pisać, żeby przeszukać
        wszystko naraz.
      </p>
    </UCard>
  </div>
</template>
