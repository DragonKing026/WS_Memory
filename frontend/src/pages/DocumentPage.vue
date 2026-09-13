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
 * Verification is offered here rather than on the editor, and only to people: an
 * agent has no way to reach this endpoint at all (D-005). The confirmation spells out
 * what the claim is, because a button labelled only "Verify" gets clicked to make a
 * badge go away.
 */
const route = useRoute()
const auth = useAuthStore()

const document = ref<DocumentDetail | null>(null)
const busy = ref(false)
const problem = ref<string | null>(null)
const verifying = ref(false)
const confirmingVerify = ref(false)

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

async function verify(): Promise<void> {
  verifying.value = true
  problem.value = null

  try {
    await documentService.verify(space.value, slug.value)
    confirmingVerify.value = false
    await load()
  } catch (error) {
    problem.value = error instanceof Error ? error.message : 'Nie udało się zweryfikować.'
  } finally {
    verifying.value = false
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

        <div class="flex shrink-0 flex-wrap gap-2">
          <UButton
            size="sm"
            variant="subtle"
            icon="i-lucide-history"
            :to="{ name: 'history', params: { space, slug } }"
          >
            Historia
          </UButton>
          <UButton
            v-if="canWrite"
            size="sm"
            icon="i-lucide-pencil"
            :to="{ name: 'document-edit', params: { space, slug } }"
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

        <!-- Verification is a human act and the button says what the act means. A
             button labelled only "Verify" gets clicked to make the badge go away;
             one that spells out the claim gets clicked by somebody making it. -->
        <UButton
          v-if="canWrite && !document.verified"
          size="xs"
          variant="subtle"
          icon="i-lucide-badge-check"
          :loading="verifying"
          @click="confirmingVerify = true"
        >
          Zweryfikuj
        </UButton>

        <UBadge v-if="document.archived" color="error" variant="subtle">Zarchiwizowane</UBadge>
        <UBadge v-if="document.status === 'draft'" color="neutral" variant="outline">
          Szkic
        </UBadge>

        <span v-if="document.currentRevision !== null" class="text-muted">
          rewizja {{ document.currentRevision }}
        </span>
        <span v-if="updatedAt" class="text-muted">· {{ updatedAt }}</span>
      </div>

      <UAlert
        v-if="confirmingVerify"
        color="info"
        variant="subtle"
        icon="i-lucide-badge-check"
        title="Potwierdzasz, że treść jest prawdziwa"
      >
        <template #description>
          <p>
            Weryfikacja to twoje oświadczenie, że przeczytałeś ten dokument i treść się
            zgadza. Znika automatycznie przy następnej zmianie — bo „Anna to sprawdziła”
            przestaje być prawdą w chwili, gdy tekst się zmienia.
          </p>
          <div class="mt-2 flex gap-2">
            <UButton size="xs" :loading="verifying" @click="verify">Tak, potwierdzam</UButton>
            <UButton
              size="xs"
              variant="subtle"
              color="neutral"
              @click="confirmingVerify = false"
            >
              Anuluj
            </UButton>
          </div>
        </template>
      </UAlert>

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
