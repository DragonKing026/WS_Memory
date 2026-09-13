<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import type {
  DocumentHistory,
  HistoryRevision,
  RevisionDiff,
} from '@/features/documents/schemas'
import { documentPath } from '@/features/documents/paths'
import { documentService } from '@/features/documents/service'
import { useAuthStore } from '@/stores/auth'

/**
 * The history of a document, and the comparison of any two of its revisions.
 *
 * **Any two, not just neighbours** — that is the whole design. A document an agent has
 * corrected four times in a row makes the last step almost meaningless; the question a
 * reader actually has is "what changed since the version I read", which is a
 * comparison across an arbitrary distance.
 *
 * Rolling back **adds** a revision carrying the old text. It never deletes anything,
 * and the screen says so before asking for confirmation: "undo" that shortened history
 * would make the history untrustworthy, which is the one thing it cannot be.
 */
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const space = computed(() => String(route.params.space ?? ''))
const slug = computed(() => {
  const raw = route.params.slug

  return Array.isArray(raw) ? raw.join('/') : String(raw ?? '')
})

const history = ref<DocumentHistory | null>(null)
const diff = ref<RevisionDiff | null>(null)
// Zero means "not chosen". `USelect` types its model from the items, so a nullable
// ref would need a cast at every use — a sentinel is less machinery than that.
const from = ref(0)
const to = ref(0)

const busy = ref(false)
const comparing = ref(false)
const rollingBack = ref(false)
const problem = ref<string | null>(null)
const confirmingRollback = ref<number | null>(null)

const canWrite = computed(() => {
  const membership = auth.spaces.find((item) => item.slug === space.value)

  return membership?.role === 'writer' || membership?.role === 'admin'
})

/** Newest first: the recent end of a history is the end people look at. */
const revisions = computed(() =>
  [...(history.value?.revisions ?? [])].sort((a, b) => b.number - a.number),
)

const canCompare = computed(() => from.value > 0 && to.value > 0 && from.value !== to.value)

async function load(): Promise<void> {
  busy.value = true
  problem.value = null

  try {
    history.value = await documentService.history(space.value, slug.value)

    // Sensible default: the two most recent, which is the comparison most people want
    // first. Anything else is a click away.
    const numbers = revisions.value.map((r) => r.number)
    from.value = fromUrl('od') ?? numbers[1] ?? 0
    to.value = fromUrl('do') ?? numbers[0] ?? 0

    if (canCompare.value) {
      await compare()
    }
  } catch (error) {
    problem.value = error instanceof Error ? error.message : 'Nie udało się wczytać historii.'
  } finally {
    busy.value = false
  }
}

function fromUrl(name: string): number | null {
  const raw = route.query[name]
  const parsed = typeof raw === 'string' ? Number.parseInt(raw, 10) : Number.NaN

  return Number.isFinite(parsed) ? parsed : null
}

async function compare(): Promise<void> {
  if (!canCompare.value) {
    return
  }

  comparing.value = true
  problem.value = null

  try {
    diff.value = await documentService.diff(space.value, slug.value, from.value, to.value)
  } catch (error) {
    diff.value = null
    problem.value = error instanceof Error ? error.message : 'Nie udało się porównać.'
  } finally {
    comparing.value = false
  }
}

async function rollback(revision: number): Promise<void> {
  rollingBack.value = true
  problem.value = null

  try {
    await documentService.rollback(space.value, slug.value, revision)
    confirmingRollback.value = null
    await router.push(documentPath(space.value, slug.value))
  } catch (error) {
    problem.value = error instanceof Error ? error.message : 'Nie udało się cofnąć.'
  } finally {
    rollingBack.value = false
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

function isCurrent(revision: HistoryRevision): boolean {
  return revision.number === history.value?.currentRevision
}

watch([space, slug], () => void load(), { immediate: true })
watch([from, to], () => void compare())
</script>

<template>
  <div class="max-w-4xl space-y-4">
    <div>
      <RouterLink
        :to="documentPath(space, slug)"
        class="text-xs text-muted hover:underline"
      >
        ← {{ space }} / {{ slug }}
      </RouterLink>
      <h1 class="text-xl font-semibold">Historia dokumentu</h1>
    </div>

    <UAlert
      v-if="problem"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :description="problem"
    />

    <div v-if="busy" class="text-sm text-muted">Wczytuję…</div>

    <template v-else-if="history">
      <div class="flex flex-wrap items-end gap-3">
        <UFormField label="Porównaj od rewizji" size="xs">
          <USelect
            v-model="from"
            :items="revisions.map((r) => ({ label: `nr ${r.number}`, value: r.number }))"
            value-key="value"
            class="min-w-32"
          />
        </UFormField>
        <UFormField label="do rewizji" size="xs">
          <USelect
            v-model="to"
            :items="revisions.map((r) => ({ label: `nr ${r.number}`, value: r.number }))"
            value-key="value"
            class="min-w-32"
          />
        </UFormField>
        <p v-if="from === to && from > 0" class="text-xs text-muted pb-2">
          Wybierz dwie różne rewizje.
        </p>
      </div>

      <UCard v-if="comparing"><p class="text-sm text-muted">Porównuję…</p></UCard>

      <UCard v-else-if="diff">
        <template #header>
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-medium">Rewizja {{ diff.from }} → {{ diff.to }}</h2>
            <p class="text-xs text-muted">
              <span class="text-success">+{{ diff.added }}</span>
              <span class="ml-2 text-error">−{{ diff.removed }}</span>
            </p>
          </div>
        </template>

        <p v-if="diff.identical" class="text-sm text-muted">
          Te rewizje mają identyczną treść.
        </p>

        <!-- Text nodes, never v-html: the content is Markdown written by people and
             agents, and a diff rendered as HTML would render whatever it contains. -->
        <div v-else class="overflow-x-auto rounded border border-default">
          <table class="w-full text-xs font-mono">
            <tbody>
              <tr
                v-for="(line, index) in diff.lines"
                :key="index"
                :class="{
                  'bg-success/10': line.type === 'added',
                  'bg-error/10': line.type === 'removed',
                }"
              >
                <!-- align-top na każdej komórce: bez tego komórka domyślnie centruje
                     się w pionie, więc przy zawiniętej linii numer i znak wypadają
                     poniżej pierwszego wiersza tekstu, do którego należą. -->
                <td class="w-12 select-none px-2 py-0.5 text-right align-top text-muted">
                  {{ line.from ?? '' }}
                </td>
                <td class="w-12 select-none px-2 py-0.5 text-right align-top text-muted">
                  {{ line.to ?? '' }}
                </td>
                <td class="w-5 select-none py-0.5 text-center align-top text-muted">
                  {{ line.type === 'added' ? '+' : line.type === 'removed' ? '−' : '' }}
                </td>
                <td class="whitespace-pre-wrap break-words py-0.5 pr-2 align-top">
                  {{ line.line }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </UCard>

      <div>
        <h2 class="mb-2 font-medium">Rewizje</h2>
        <ul class="divide-y divide-default border-y border-default">
          <!-- Znacznik dla testu E2E: liczenie po treści jest kruche (wiersz zawiera
               opis zmiany, autora i datę), a to, ile rewizji jest na liście, jest
               właśnie tym, czego test cofania pilnuje. -->
          <li
            v-for="revision in revisions"
            :key="revision.number"
            data-test="rewizja"
            class="py-3"
          >
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="font-medium">
                  Rewizja {{ revision.number }}
                  <UBadge v-if="isCurrent(revision)" color="primary" variant="subtle" size="sm">
                    bieżąca
                  </UBadge>
                </p>
                <p class="text-sm text-muted break-words">
                  {{ revision.changeNote ?? 'bez opisu zmiany' }}
                </p>
                <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                  <UBadge
                    v-if="revision.byAi"
                    color="warning"
                    variant="subtle"
                    icon="i-lucide-bot"
                  >
                    {{ revision.authorName }}
                  </UBadge>
                  <UBadge v-else color="neutral" variant="subtle" icon="i-lucide-user">
                    {{ revision.authorName }}
                  </UBadge>
                  <span class="text-muted">{{ formatDateTime(revision.createdAt) }}</span>
                </div>
              </div>

              <UButton
                v-if="canWrite && !isCurrent(revision)"
                size="xs"
                variant="subtle"
                color="neutral"
                @click="confirmingRollback = revision.number"
              >
                Cofnij do tej wersji
              </UButton>
            </div>

            <!-- Confirmation says what rollback actually does. "Undo" that shortened
                 history would make the history untrustworthy. -->
            <UAlert
              v-if="confirmingRollback === revision.number"
              class="mt-2"
              color="warning"
              variant="subtle"
              icon="i-lucide-undo-2"
              :title="`Cofnąć do rewizji ${revision.number}?`"
            >
              <template #description>
                <p>
                  Powstanie <strong>nowa rewizja</strong> z treścią tamtej. Nic nie
                  zostanie usunięte — historia idzie do przodu, także przy cofaniu.
                </p>
                <div class="mt-2 flex gap-2">
                  <UButton size="xs" :loading="rollingBack" @click="rollback(revision.number)">
                    Tak, cofnij
                  </UButton>
                  <UButton
                    size="xs"
                    variant="subtle"
                    color="neutral"
                    @click="confirmingRollback = null"
                  >
                    Anuluj
                  </UButton>
                </div>
              </template>
            </UAlert>
          </li>
        </ul>
      </div>
    </template>
  </div>
</template>
