<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import MarkdownEditor from '@/components/documents/MarkdownEditor.vue'
import MarkdownView from '@/components/documents/MarkdownView.vue'
import { clearDraft, readDraft, saveDraft, type Draft } from '@/features/documents/draft'
import { documentHistoryPath, documentPath } from '@/features/documents/paths'
import { documentService } from '@/features/documents/service'
import { useAuthStore } from '@/stores/auth'

/**
 * Writing a document.
 *
 * Four things here are not decoration.
 *
 * **The preview renders exactly the text that will be sent.** Same string, same
 * renderer as the document screen. A preview that differs from the result teaches
 * people to distrust it and then to ignore it.
 *
 * **A change note is required.** History without change notes is a list of timestamps;
 * "what changed and why" is the only reason anybody opens it. Enforced here rather
 * than only asked for, because an optional field in a hurry is an empty field.
 *
 * **The draft survives the tab closing.** Written to `localStorage` a moment after
 * typing stops, restored on return — with a question, never silently, because the
 * draft may be older than what is now on the server.
 *
 * **A revision written while you type is a warning, not a block.** An agent can save
 * at any moment. Refusing the save would lose the person's work; saving silently would
 * lose the agent's. So the screen says what happened and lets the writer decide.
 */
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const space = computed(() => String(route.params.space ?? ''))
const slug = computed(() => {
  const raw = route.params.slug

  return Array.isArray(raw) ? raw.join('/') : String(raw ?? '')
})

const title = ref('')
const content = ref('')
const changeNote = ref('')

/** The revision the editor was opened on; null for a document that does not exist yet. */
const baseRevision = ref<number | null>(null)
const isNew = ref(false)

const busy = ref(false)
const saving = ref(false)
const problem = ref<string | null>(null)
const conflict = ref<number | null>(null)
const offeredDraft = ref<Draft | null>(null)
const preview = ref(true)

const canWrite = computed(() => {
  const membership = auth.spaces.find((item) => item.slug === space.value)

  return membership?.role === 'writer' || membership?.role === 'admin'
})

const canSave = computed(
  () => title.value.trim() !== '' && content.value.trim() !== '' && changeNote.value.trim() !== '',
)

let draftTimer: ReturnType<typeof setTimeout> | null = null

async function load(): Promise<void> {
  busy.value = true
  problem.value = null

  try {
    const document = await documentService.read(space.value, slug.value)

    title.value = document.title
    content.value = document.content
    baseRevision.value = document.currentRevision
    isNew.value = false
  } catch (error) {
    // A document that is not there is the normal way to create one: the editor opens
    // empty rather than showing an error for something the writer is about to fix.
    if (error instanceof Error && /nie znaleziono|not found|404/i.test(error.message)) {
      isNew.value = true
      title.value = ''
      content.value = ''
      baseRevision.value = null
    } else {
      problem.value = error instanceof Error ? error.message : 'Nie udało się wczytać dokumentu.'
    }
  } finally {
    busy.value = false
  }

  const draft = readDraft(space.value, slug.value)
  if (draft !== null && draft.content !== content.value) {
    offeredDraft.value = draft
  }
}

function restoreDraft(): void {
  if (offeredDraft.value === null) {
    return
  }

  content.value = offeredDraft.value.content
  changeNote.value = offeredDraft.value.changeNote
  offeredDraft.value = null
}

function discardDraft(): void {
  clearDraft(space.value, slug.value)
  offeredDraft.value = null
}

/** Whether somebody saved a revision while this editor was open. */
async function checkForNewRevision(): Promise<boolean> {
  if (isNew.value) {
    return false
  }

  try {
    const current = await documentService.read(space.value, slug.value)

    if (current.currentRevision !== baseRevision.value) {
      conflict.value = current.currentRevision
      return true
    }
  } catch {
    // Cannot check — the save itself will fail loudly enough if something is wrong.
    // Blocking here would turn a network blip into lost work.
  }

  return false
}

async function save(force = false): Promise<void> {
  if (!canSave.value || saving.value) {
    return
  }

  saving.value = true
  problem.value = null

  try {
    if (!force && (await checkForNewRevision())) {
      return
    }

    await documentService.write(space.value, slug.value, {
      title: title.value.trim(),
      content: content.value,
      changeNote: changeNote.value.trim(),
    })

    clearDraft(space.value, slug.value)
    await router.push(documentPath(space.value, slug.value))
  } catch (error) {
    problem.value = error instanceof Error ? error.message : 'Nie udało się zapisać.'
  } finally {
    saving.value = false
    conflict.value = null
  }
}

// Written a moment after typing stops, not on every keystroke: `localStorage` is
// synchronous and writing on each character stutters a long document.
watch([content, changeNote], () => {
  if (draftTimer !== null) {
    clearTimeout(draftTimer)
  }

  draftTimer = setTimeout(() => {
    if (content.value.trim() !== '') {
      saveDraft(space.value, slug.value, {
        content: content.value,
        changeNote: changeNote.value,
        baseRevision: baseRevision.value,
        savedAt: new Date().toISOString(),
      })
    }
  }, 1500)
})

onMounted(() => void load())

onBeforeUnmount(() => {
  if (draftTimer !== null) {
    clearTimeout(draftTimer)
  }
})
</script>

<template>
  <div class="space-y-4">
    <UAlert
      v-if="!canWrite"
      color="warning"
      variant="subtle"
      icon="i-lucide-lock"
      title="Nie masz prawa zapisu w tej przestrzeni"
      description="Możesz czytać, ale nie zapisywać. O rolę poproś administratora przestrzeni."
    />

    <template v-else>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
          <p class="text-xs text-muted">{{ space }} / {{ slug }}</p>
          <h1 class="text-lg font-semibold">
            {{ isNew ? 'Nowy dokument' : 'Edycja dokumentu' }}
          </h1>
        </div>

        <div class="flex items-center gap-2">
          <USwitch v-model="preview" label="Podgląd" size="sm" />
          <UButton
            variant="ghost"
            color="neutral"
            :to="documentPath(space, slug)"
          >
            Anuluj
          </UButton>
          <UButton :disabled="!canSave" :loading="saving" @click="save()">Zapisz</UButton>
        </div>
      </div>

      <UAlert
        v-if="offeredDraft"
        color="info"
        variant="subtle"
        icon="i-lucide-file-clock"
        title="Masz niezapisany szkic"
      >
        <template #description>
          <p>
            Zapisany
            {{ new Date(offeredDraft.savedAt).toLocaleString('pl-PL') }}. Nie wiadomo, czy
            jest nowszy niż to, co jest w bazie — dlatego pytamy, zamiast przywracać po
            cichu.
          </p>
          <div class="mt-2 flex gap-2">
            <UButton size="xs" @click="restoreDraft">Przywróć szkic</UButton>
            <UButton size="xs" variant="subtle" color="neutral" @click="discardDraft">
              Odrzuć
            </UButton>
          </div>
        </template>
      </UAlert>

      <UAlert
        v-if="conflict !== null"
        color="warning"
        variant="subtle"
        icon="i-lucide-git-branch"
        :title="`W trakcie twojej edycji powstała rewizja nr ${conflict}`"
      >
        <template #description>
          <p>
            Ktoś — człowiek albo agent — zapisał dokument, odkąd go otworzyłeś. Zapis
            teraz **nie usunie** tamtej wersji: dopisze twoją jako kolejną rewizję,
            a tamta zostanie w historii.
          </p>
          <div class="mt-2 flex flex-wrap gap-2">
            <UButton
              size="xs"
              variant="subtle"
              :to="`${documentHistoryPath(space, slug)}?od=${baseRevision ?? ''}&do=${conflict}`"
              target="_blank"
            >
              Zobacz, co się zmieniło
            </UButton>
            <UButton size="xs" :loading="saving" @click="save(true)">
              Zapisz mimo to
            </UButton>
          </div>
        </template>
      </UAlert>

      <UAlert
        v-if="problem"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        :description="problem"
      />

      <div v-if="busy" class="text-sm text-muted">Wczytuję…</div>

      <template v-else>
        <UFormField label="Tytuł" required>
          <UInput v-model="title" placeholder="Nazwa dokumentu" class="w-full" />
        </UFormField>

        <div class="grid gap-3" :class="preview ? 'lg:grid-cols-2' : 'grid-cols-1'">
          <MarkdownEditor
            v-model="content"
            placeholder="Treść w Markdownie…"
            class="min-h-[26rem]"
          />

          <UCard v-if="preview" class="min-h-[26rem] overflow-auto">
            <MarkdownView :content="content" />
          </UCard>
        </div>

        <UFormField
          label="Opis zmiany"
          required
          help="Bez tego historia jest listą dat. Napisz, co i po co zmieniasz."
        >
          <UInput
            v-model="changeNote"
            placeholder="np. „doprecyzowanie progu akceptacji noclegu”"
            class="w-full"
          />
        </UFormField>

        <p v-if="!canSave" class="text-xs text-muted">
          Do zapisu potrzeba tytułu, treści i opisu zmiany.
        </p>
      </template>
    </template>
  </div>
</template>
