<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import DocumentTree from '@/components/documents/DocumentTree.vue'
import { documentEditPath } from '@/features/documents/paths'
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
const router = useRouter()
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

/**
 * Starting a document from the browser.
 *
 * The editor has always been able to create one — opening an address that does not
 * exist is how it works — but nothing in the interface led there, so the only way in
 * was to type the URL by hand. The empty space said the editor "was coming", long
 * after it had arrived.
 *
 * There is no separate creation screen and there should not be: a document is its
 * address plus its content, and the editor already asks for the content.
 */
const canWrite = computed(() => auth.canWriteIn(slug.value))
const naming = ref(false)
const newSlug = ref('')

/** The address rule the server enforces (`ws_doc_write`): lowercase without Polish
 *  marks, digits, hyphens, slash as a folder separator. Checked here so the refusal
 *  arrives while typing rather than after the first save attempt. */
const ADDRESS = /^[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*$/

const addressProblem = computed<string | null>(() => {
  const value = newSlug.value.trim()
  if (value === '') {
    return null
  }

  if (!ADDRESS.test(value)) {
    return 'Małe litery bez ogonków, cyfry i łączniki. Ukośnik robi folder: wdrozenia/backup-bazy.'
  }

  return documents.value.some((item) => item.slug === value)
    ? 'Taki dokument już jest — otworzysz go do edycji, nie założysz drugiego.'
    : null
})

const canOpenEditor = computed(
  () => newSlug.value.trim() !== '' && addressProblem.value === null,
)

function openEditor(): void {
  if (!canOpenEditor.value) {
    return
  }

  void router.push(documentEditPath(slug.value, newSlug.value.trim()))
}

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

        <div class="flex shrink-0 gap-2">
          <UButton
            size="sm"
            variant="subtle"
            icon="i-lucide-search"
            :to="{ name: 'home', query: { q: '', przestrzen: space.slug } }"
          >
            Szukaj tutaj
          </UButton>
          <UButton
            v-if="canWrite"
            size="sm"
            icon="i-lucide-file-plus"
            @click="naming = true"
          >
            Nowy dokument
          </UButton>
        </div>
      </div>

      <UCard v-if="naming && canWrite">
        <p class="font-medium">Adres nowego dokumentu</p>
        <p class="mt-1 text-sm text-muted">
          Adres nazywa <strong>rzecz</strong>, nie okazję: <code>wdrozenia/backup-bazy</code>,
          a nie <code>notatki-ze-spotkania</code>. Jest trwały i widoczny w linkach.
        </p>

        <div class="mt-3 flex flex-wrap items-start gap-2">
          <UInput
            v-model="newSlug"
            class="min-w-64 flex-1"
            placeholder="wdrozenia/backup-bazy"
            autofocus
            @keyup.enter="openEditor"
          />
          <UButton :disabled="!canOpenEditor" @click="openEditor">Pisz</UButton>
          <UButton variant="ghost" @click="naming = false">Anuluj</UButton>
        </div>

        <p v-if="addressProblem" class="mt-2 text-sm text-error">{{ addressProblem }}</p>
      </UCard>

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
            <template v-if="canWrite">
              Zacznij od <strong>Nowego dokumentu</strong> u góry. Pisać może też agent AI
              narzędziem <code>ws_doc_write</code> — w tę samą przestrzeń.
            </template>
            <template v-else>
              Masz tu prawo czytać, ale nie pisać. Pierwszy dokument napisze ktoś z rolą
              piszącego albo agent AI narzędziem <code>ws_doc_write</code>.
            </template>
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
