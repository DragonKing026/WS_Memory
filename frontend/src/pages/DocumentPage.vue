<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'

import MarkdownView from '@/components/documents/MarkdownView.vue'
import { documentService } from '@/features/documents/service'
import type { DocumentDetail } from '@/features/documents/schemas'
import { useAuthStore } from '@/stores/auth'

/**
 * One document: what it says, who wrote it, and whether anybody checked.
 *
 * The provenance strip above the content is not decoration. Half this base will be
 * written by agents, and a reader deciding how much to trust a page needs to know
 * that before reading it, not after. So it sits above the text rather than in a
 * footer, and it states the human case as plainly as the AI one — a badge that only
 * appears sometimes gets read as "unknown" the rest of the time.
 *
 * Editing and history are TODO-008. The buttons are shown disabled rather than hidden:
 * "this exists and is coming" is more useful than a screen that silently lacks the
 * thing a reader is looking for.
 */
const route = useRoute()
const auth = useAuthStore()

const document = ref<DocumentDetail | null>(null)
const busy = ref(false)
const problem = ref<string | null>(null)

const space = computed(() => String(route.params.space ?? ''))
const slug = computed(() => {
  const raw = route.params.slug

  return Array.isArray(raw) ? raw.join('/') : String(raw ?? '')
})

const canWrite = computed(() => {
  const membership = auth.spaces.find((item) => item.slug === space.value)

  return membership?.role === 'writer' || membership?.role === 'admin'
})

const updatedAt = computed(() => formatDate(document.value?.updatedAt ?? null))
const verifiedAt = computed(() => formatDate(document.value?.verifiedAt ?? null))

function formatDate(value: string | null): string | null {
  if (value === null) {
    return null
  }

  const date = new Date(value)

  return Number.isNaN(date.getTime())
    ? null
    : date.toLocaleString('pl-PL', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      })
}

async function load(): Promise<void> {
  if (space.value === '' || slug.value === '') {
    return
  }

  busy.value = true
  problem.value = null

  try {
    document.value = await documentService.read(space.value, slug.value)
  } catch (error) {
    document.value = null
    problem.value =
      error instanceof Error ? error.message : 'Nie udało się wczytać dokumentu.'
  } finally {
    busy.value = false
  }
}

watch([space, slug], () => void load(), { immediate: true })
</script>

<template>
  <div class="max-w-3xl space-y-4">
    <div v-if="busy" class="text-sm text-muted">Wczytuję…</div>

    <UAlert
      v-else-if="problem"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :description="problem"
    />

    <template v-else-if="document">
      <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
          <RouterLink
            :to="{ name: 'space', params: { space: document.space } }"
            class="text-xs text-muted hover:underline"
          >
            {{ document.space }}
          </RouterLink>
          <h1 class="text-2xl font-semibold break-words">{{ document.title }}</h1>
        </div>

        <div class="flex shrink-0 gap-2">
          <UButton
            size="sm"
            variant="subtle"
            icon="i-lucide-history"
            disabled
            title="Historia rewizji dochodzi w TODO-008"
          >
            Historia
          </UButton>
          <UButton
            v-if="canWrite"
            size="sm"
            icon="i-lucide-pencil"
            disabled
            title="Edytor dochodzi w TODO-008"
          >
            Edytuj
          </UButton>
        </div>
      </div>

      <!-- Provenance, above the text on purpose. -->
      <div class="flex flex-wrap items-center gap-2 text-xs">
        <UBadge v-if="document.authoredByAi" color="warning" variant="subtle" icon="i-lucide-bot">
          Napisane przez AI
        </UBadge>
        <UBadge v-else color="neutral" variant="subtle" icon="i-lucide-user">
          Napisane przez człowieka
        </UBadge>

        <UBadge
          v-if="document.verified"
          color="success"
          variant="subtle"
          icon="i-lucide-badge-check"
        >
          Zweryfikował {{ document.verifiedBy ?? 'człowiek' }}<span v-if="verifiedAt">
            · {{ verifiedAt }}</span>
        </UBadge>
        <UBadge v-else color="neutral" variant="outline">Niezweryfikowane</UBadge>

        <UBadge v-if="document.archived" color="error" variant="subtle">Zarchiwizowane</UBadge>
        <UBadge v-if="document.status === 'draft'" color="neutral" variant="outline">
          Szkic
        </UBadge>

        <span v-if="document.currentRevision !== null" class="text-muted">
          rewizja {{ document.currentRevision }}
        </span>
        <span v-if="updatedAt" class="text-muted">· {{ updatedAt }}</span>
      </div>

      <!-- Said out loud rather than left to a disabled button: a dimmed button with a
           tooltip is invisible to anybody not hovering over it, and "why can't I edit
           this" is a worse question than a plain sentence. -->
      <p class="text-xs text-muted">
        Edycja i historia w przeglądarce jeszcze nie działają. Dokument można zmienić
        przez API albo agentem AI (<code>ws_doc_write</code>).
      </p>

      <UAlert
        v-if="document.authoredByAi && !document.verified"
        color="warning"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        title="Tego nie sprawdził jeszcze człowiek"
        description="Treść napisała AI i nikt jej nie zweryfikował. Traktuj jako punkt
          wyjścia, nie jako ustalenie."
      />

      <UCard>
        <MarkdownView :content="document.content" />
      </UCard>
    </template>
  </div>
</template>
