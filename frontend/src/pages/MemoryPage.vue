<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import { kindLabels } from '@/features/search/schemas'
import type { MemoryEntry } from '@/features/memory/schemas'
import { memoryService } from '@/features/memory/service'
import { useAuthStore } from '@/stores/auth'

/**
 * Raw memory: everything that has been filed, newest first.
 *
 * A separate screen from the wiki because it answers a different question. The wiki is
 * what the company has decided to write down; this is what has been noticed — notes an
 * agent thought worth keeping, diary entries, transcripts. Mixed into one list the
 * second would bury the first, and a reader would lose the difference between "we
 * decided this" and "an agent noticed this".
 *
 * The reason to open it is oversight: seeing that an agent has been filing nonsense,
 * or that a topic nobody owns is quietly accumulating notes. Hence the ordering by
 * time and the prominence of who wrote each row.
 */
const auth = useAuthStore()

const spaces = ref<string[]>([])
const kind = ref<string | null>(null)
const since = ref('')
const before = ref('')

const entries = ref<MemoryEntry[]>([])
const hasMore = ref(false)
const busy = ref(false)
const problem = ref<string | null>(null)

const spaceOptions = computed(() =>
  auth.spaces.map((space) => ({ label: space.name, value: space.slug })),
)

const kindOptions = [
  { label: 'Wszystkie klasy', value: null },
  ...Object.entries(kindLabels).map(([value, label]) => ({ label, value })),
]

const byAiCount = computed(() => entries.value.filter((entry) => entry.byAi).length)

async function load(more = false): Promise<void> {
  busy.value = true
  problem.value = null

  try {
    const page = await memoryService.browse(
      {
        spaces: spaces.value,
        kind: kind.value,
        since: since.value === '' ? null : since.value,
        before: before.value === '' ? null : before.value,
      },
      more ? entries.value.length : 0,
    )

    entries.value = more ? [...entries.value, ...page.entries] : page.entries
    hasMore.value = page.hasMore
  } catch (error) {
    entries.value = []
    hasMore.value = false
    problem.value = error instanceof Error ? error.message : 'Nie udało się wczytać pamięci.'
  } finally {
    busy.value = false
  }
}

function formatDateTime(value: string): string {
  const date = new Date(value)

  return Number.isNaN(date.getTime())
    ? value
    : date.toLocaleString('pl-PL', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      })
}

// Changing a filter reloads from the first page: appending to a list filtered
// differently would mix two answers into one.
watch([spaces, kind, since, before], () => void load(), { immediate: true })
</script>

<template>
  <div class="max-w-4xl space-y-4">
    <div>
      <h1 class="text-xl font-semibold">Surowa pamięć</h1>
      <p class="text-sm text-muted">
        Wszystko, co trafiło do pamięci — notatki agentów, dziennik, transkrypty. To nie
        jest dokumentacja: dokumentacja jest tym, co zespół postanowił zapisać, a tutaj
        widać, co zostało zauważone.
      </p>
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
    </div>

    <UAlert
      v-if="problem"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :description="problem"
    />

    <div v-else-if="busy && entries.length === 0" class="text-sm text-muted">Wczytuję…</div>

    <template v-else>
      <p class="text-sm text-muted">
        {{ entries.length }} {{ entries.length === 1 ? 'wpis' : 'wpisów' }}
        <template v-if="hasMore">(i więcej)</template>
        <template v-if="byAiCount > 0"> · {{ byAiCount }} napisanych przez AI</template>
      </p>

      <UCard v-if="entries.length === 0">
        <p class="font-medium">Nic tu jeszcze nie ma.</p>
        <p class="mt-1 text-sm text-muted">
          Pamięć zapełnia się, gdy agenci zapisują notatki (<code>ws_remember</code>),
          prowadzą dziennik albo gdy dokument zostaje opublikowany. Jeśli filtry są
          ustawione, spróbuj je zdjąć.
        </p>
      </UCard>

      <ul v-else class="divide-y divide-default border-y border-default">
        <li v-for="entry in entries" :key="entry.drawer" class="py-3">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <RouterLink
                v-if="entry.documentSlug"
                :to="{
                  name: 'document',
                  params: { space: entry.space, slug: entry.documentSlug },
                }"
                class="font-medium hover:underline break-words"
              >
                {{ entry.title }}
              </RouterLink>
              <!-- No link for raw memory: it has no page of its own, and a link that
                   goes nowhere is worse than plain text. -->
              <span v-else class="font-medium break-words">{{ entry.title }}</span>

              <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                <UBadge color="neutral" variant="subtle">{{ entry.space }}</UBadge>
                <UBadge color="neutral" variant="outline">{{ kindLabels[entry.kind] }}</UBadge>

                <UBadge v-if="entry.byAi" color="warning" variant="subtle" icon="i-lucide-bot">
                  Napisane przez AI
                </UBadge>
                <UBadge v-else color="neutral" variant="subtle" icon="i-lucide-user">
                  Napisane przez człowieka
                </UBadge>

                <UBadge
                  v-if="entry.verified"
                  color="success"
                  variant="subtle"
                  icon="i-lucide-badge-check"
                >
                  Zweryfikowane
                </UBadge>

                <UBadge
                  v-for="tag in entry.tags"
                  :key="tag"
                  color="primary"
                  variant="subtle"
                  size="sm"
                >
                  {{ tag }}
                </UBadge>
              </div>
            </div>

            <span class="shrink-0 text-xs text-muted">{{ formatDateTime(entry.filedAt) }}</span>
          </div>
        </li>
      </ul>

      <UButton v-if="hasMore" variant="subtle" size="sm" :loading="busy" @click="load(true)">
        Pokaż kolejne
      </UButton>
    </template>
  </div>
</template>
