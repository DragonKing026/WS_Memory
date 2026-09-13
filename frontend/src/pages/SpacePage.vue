<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'

import DocumentTree from '@/components/documents/DocumentTree.vue'
import type { DocumentListItem } from '@/features/documents/schemas'
import { documentService } from '@/features/documents/service'
import { buildTree } from '@/features/documents/tree'
import { useAuthStore } from '@/stores/auth'

/**
 * A space: what is written down in it, as a tree.
 *
 * A tree rather than a list, because a slug carries a path — `umowy/najem`,
 * `procedury/kadry/urlopy` — and that path is the only structure the wiki has.
 * Flattened, it is invisible, and a space with two hundred documents becomes a wall
 * of titles nobody scans.
 *
 * Inside a folder, documents are ordered by last change rather than alphabetically:
 * the question people arrive with is "what moved", and "what exists" is what search
 * is for. The AI and verification marks are on every row, not only on the document
 * page — deciding what to open is exactly when they matter.
 *
 * Access is judged from the store's list, which came from the server. Nothing here
 * decides permissions; a menu assembled locally would eventually offer a space the
 * server refuses.
 */
const route = useRoute()
const auth = useAuthStore()

const slug = computed(() => (typeof route.params.space === 'string' ? route.params.space : ''))
const space = computed(() => auth.spaceBySlug(slug.value))

const documents = ref<DocumentListItem[]>([])
/** The listing is paged server-side: an unpaged one fell over on a space with ten
 *  thousand documents, and the server now decides how much comes at a time. */
const hasMore = ref(false)
const busy = ref(false)
const problem = ref<string | null>(null)
const showArchived = ref(false)

const visible = computed(() =>
  showArchived.value ? documents.value : documents.value.filter((item) => !item.archived),
)

const tree = computed(() => buildTree(visible.value))

/** Folder paths the reader has collapsed. Held here rather than in the tree component
 *  so that loading another page does not spring every folder back open. */
const collapsed = ref(new Set<string>())

function toggle(path: string): void {
  // A new Set rather than mutating: Vue does not track Set membership on its own.
  const next = new Set(collapsed.value)
  next.has(path) ? next.delete(path) : next.add(path)
  collapsed.value = next
}

const archivedCount = computed(() => documents.value.filter((item) => item.archived).length)

const byAiUnverified = computed(
  () => visible.value.filter((item) => item.authoredByAi && !item.verified).length,
)

async function load(): Promise<void> {
  if (space.value === undefined) {
    documents.value = []

    return
  }

  // A fresh space means a fresh listing; without this, switching spaces would append
  // one space's documents to another's.
  if (documents.value.length === 0) {
    hasMore.value = false
  }

  busy.value = true
  problem.value = null

  try {
    const page = await documentService.list(slug.value, documents.value.length)

    documents.value = [...documents.value, ...page.documents]
    hasMore.value = page.hasMore
  } catch (error) {
    documents.value = []
    problem.value = error instanceof Error ? error.message : 'Nie udało się wczytać listy.'
  } finally {
    busy.value = false
  }
}

watch(
  slug,
  () => {
    documents.value = []
    hasMore.value = false
    void load()
  },
  { immediate: true },
)
</script>

<template>
  <div class="max-w-3xl space-y-4">
    <template v-if="space !== undefined">
      <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
          <h1 class="text-xl font-semibold">{{ space.name }}</h1>
          <p class="text-sm text-muted">
            Twoja rola: {{ space.role ?? 'brak' }}.
            <template v-if="space.requiresProposal">
              Ta przestrzeń wymaga przeglądu propozycji przed publikacją.
            </template>
          </p>
        </div>

        <UButton
          size="sm"
          variant="subtle"
          icon="i-lucide-search"
          :to="{ name: 'home', query: { q: '', przestrzen: space.slug } }"
        >
          Szukaj tutaj
        </UButton>
      </div>

      <UAlert
        v-if="problem"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        :description="problem"
      />

      <div v-else-if="busy" class="text-sm text-muted">Wczytuję…</div>

      <template v-else>
        <div class="flex flex-wrap items-center gap-3 text-sm text-muted">
          <span>
            {{ visible.length }}
            {{ visible.length === 1 ? 'dokument' : 'dokumentów' }}
            <template v-if="hasMore">(i więcej)</template>
          </span>
          <span v-if="byAiUnverified > 0">
            · {{ byAiUnverified }} napisanych przez AI i niesprawdzonych
          </span>
          <USwitch
            v-if="archivedCount > 0"
            v-model="showArchived"
            :label="`Pokaż zarchiwizowane (${archivedCount})`"
            size="sm"
          />
        </div>

        <UCard v-if="visible.length === 0">
          <p class="font-medium">Tu jeszcze nic nie ma.</p>
          <p class="mt-1 text-sm text-muted">
            Pierwszy dokument w przestrzeni może napisać człowiek przez API albo agent AI
            narzędziem <code>ws_doc_write</code>. Edytor w przeglądarce dochodzi
            w TODO-008.
          </p>
        </UCard>

        <div v-else class="border-y border-default py-2">
          <DocumentTree
            :folder="tree"
            :space="slug"
            :collapsed="collapsed"
            @toggle="toggle"
          />
        </div>

        <UButton
          v-if="hasMore"
          variant="subtle"
          size="sm"
          :loading="busy"
          class="mt-3"
          @click="load"
        >
          Pokaż kolejne
        </UButton>
      </template>
    </template>

    <!-- Not "you have no access": the same message as for a space that does not exist,
         because saying which it is would confirm that the space is there. -->
    <UAlert
      v-else
      color="warning"
      variant="subtle"
      title="Nie ma takiej przestrzeni"
      description="Sprawdź adres albo poproś o dostęp administratora."
    />
  </div>
</template>
