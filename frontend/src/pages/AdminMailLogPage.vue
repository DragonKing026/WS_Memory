<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'

import { describePage, formatDateTimeOr } from '@/features/admin/format'
import { PAGE_SIZE, type PageMeta } from '@/features/admin/listing'
import type { MailLogEntry, MailStatus } from '@/features/admin/mailSchemas'
import { adminMailService, type MailLogFilters } from '@/features/admin/mailService'
import { describeAttempts, statusAppearance } from '@/features/admin/mailState'
import { describeAdminFailure } from '@/features/admin/refusals'

/**
 * Administration → Mail journal: what this installation tried to send.
 *
 * The screen exists for one sentence somebody arrives with: "the invitation never
 * came". So the failure reason is a first-class column rather than something behind an
 * expander — it carries the mail server's own words, which is the difference between
 * fixing a password in the DSN and reading container logs.
 *
 * **No message bodies, and none to show.** An invitation mail carries a working token,
 * so the journal keeps metadata only (D-038); the wording is on the templates screen.
 * The schema for a row is strict, so a future backend that started sending a body would
 * fail loudly here instead of quietly rendering a credential.
 *
 * The state filter offers what the backend says exists, rather than a list compiled in
 * here. And an unknown state is refused by the API rather than ignored: a filter that
 * silently widens makes this screen say "these are the failures" while showing
 * everything.
 */
const entries = ref<MailLogEntry[]>([])
const meta = ref<PageMeta | null>(null)
const busy = ref(false)
const problem = ref<string | null>(null)

const status = ref<MailStatus | null>(null)
const recipient = ref('')

/** The vocabulary of states with their Polish names, from the backend. */
const statuses = ref<{ value: string; label: string }[]>([])

const statusItems = computed(() => [
  { label: 'Wszystkie stany', value: null },
  ...statuses.value.map((one) => ({ label: one.label, value: one.value })),
])

const summary = computed(() =>
  meta.value === null
    ? null
    : describePage(entries.value.length, meta.value, ['wiadomość', 'wiadomości', 'wiadomości']),
)

/**
 * How many of the loaded rows failed.
 *
 * Counted over what is on screen and named as such, like the audit screen's breakdown:
 * the backend sends a total for the query, not a split by state, and inventing one from
 * a page would be a statistic about twenty-five rows dressed up as a statistic about
 * everything.
 */
const failedOnScreen = computed(() => entries.value.filter((one) => one.status === 'failed').length)

const filters = computed<MailLogFilters>(() => ({
  status: status.value,
  recipient: recipient.value.trim() === '' ? null : recipient.value,
}))

async function load(more = false): Promise<void> {
  busy.value = true
  problem.value = null

  try {
    const offset = more ? entries.value.length : 0
    const answer = await adminMailService.log(filters.value, offset)

    entries.value = more ? [...entries.value, ...answer.entries] : answer.entries
    meta.value = { count: answer.count, limit: answer.limit, offset: answer.offset, hasMore: answer.hasMore }
    statuses.value = answer.statuses
  } catch (cause) {
    problem.value = describeAdminFailure(cause, 'Nie udało się wczytać dziennika maili.')
  } finally {
    busy.value = false
  }
}

/** Typing an address should not fire a request per keystroke. */
const FILTER_DELAY_MS = 350
let debounce: number | null = null

watch([status, recipient], () => {
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

onMounted(() => void load())
</script>

<template>
  <div class="max-w-5xl space-y-4">
    <div>
      <h1 class="text-xl font-semibold">Dziennik maili</h1>
      <p class="text-sm text-muted">
        Co ta instancja próbowała wysłać, do kogo i jak się to skończyło. Przy porażce
        widnieje powód podany przez serwer poczty.
      </p>
      <p class="mt-1 text-sm text-muted">
        <strong>Treści wiadomości tu nie ma i nie będzie</strong> — mail z zaproszeniem
        niesie działający token, a to jest tabela otwierana swobodnie. Treść zobaczysz
        w szablonach.
      </p>
    </div>

    <div class="flex flex-wrap items-end gap-3">
      <USelect v-model="status" :items="statusItems" value-key="value" class="min-w-44" />

      <UFormField label="Adresat" size="xs">
        <UInput v-model="recipient" placeholder="fragment adresu" />
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
        <template v-if="failedOnScreen > 0">
          · nieudanych w tej partii: {{ failedOnScreen }}
        </template>
      </p>

      <UCard v-if="entries.length === 0">
        <p class="font-medium">Żadna wiadomość nie odpowiada tym filtrom.</p>
        <p class="mt-1 text-sm text-muted">
          Jeśli dziennik jest pusty w ogóle, to ta instancja nie próbowała jeszcze nic
          wysłać — a przy nieustawionym <code>MAILER_DSN</code> nie wyśle.
        </p>
      </UCard>

      <div v-else class="overflow-x-auto rounded border border-default">
        <table class="min-w-full text-sm">
          <thead class="bg-elevated text-left text-xs uppercase tracking-wide text-muted">
            <tr>
              <th class="px-3 py-2 font-medium">Kiedy</th>
              <th class="px-3 py-2 font-medium">Adresat</th>
              <th class="px-3 py-2 font-medium">Rodzaj</th>
              <th class="px-3 py-2 font-medium">Stan</th>
              <th class="px-3 py-2 font-medium">Powód porażki</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-default">
            <tr v-for="entry in entries" :key="entry.id">
              <td class="whitespace-nowrap px-3 py-2 align-top text-muted">
                {{ formatDateTimeOr(entry.queuedAt, 'bez daty') }}
              </td>

              <td class="px-3 py-2 align-top">
                <button
                  type="button"
                  class="text-left underline decoration-dotted hover:text-primary"
                  :title="`Pokaż tylko wiadomości do: ${entry.recipient}`"
                  @click="recipient = entry.recipient"
                >
                  {{ entry.recipient }}
                </button>
                <p class="text-xs text-muted">{{ entry.subject }}</p>
              </td>

              <td class="px-3 py-2 align-top">{{ entry.templateLabel }}</td>

              <td class="px-3 py-2 align-top">
                <div class="flex flex-col gap-1">
                  <UBadge
                    :color="statusAppearance(entry).color"
                    :icon="statusAppearance(entry).icon"
                    variant="subtle"
                    size="sm"
                    class="w-fit"
                  >
                    {{ statusAppearance(entry).label }}
                  </UBadge>
                  <!-- Liczba prób tylko wtedy, gdy coś znaczy: „1 próba" w każdym
                       wierszu zasłaniałaby ten jeden wiersz, gdzie prób było cztery. -->
                  <span v-if="describeAttempts(entry) !== null" class="text-xs text-muted">
                    {{ describeAttempts(entry) }}
                  </span>
                  <span v-if="entry.sentAt !== null" class="text-xs text-muted">
                    {{ formatDateTimeOr(entry.sentAt, '') }}
                  </span>
                </div>
              </td>

              <td class="px-3 py-2 align-top">
                <span v-if="entry.failureReason === null" class="text-muted">—</span>
                <!-- Słowami serwera poczty, bez parafrazy: to jest ta część, po której
                     poznaje się złe hasło w DSN od zamkniętego portu. -->
                <span v-else class="break-words">{{ entry.failureReason }}</span>
              </td>
            </tr>
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
        Pokaż kolejne {{ PAGE_SIZE }}
      </UButton>
    </template>
  </div>
</template>
