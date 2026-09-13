<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import { describePage, formatDateTimeOr } from '@/features/admin/format'
import type { AdminInvitation, IssuedInvitation } from '@/features/admin/invitationSchemas'
import { adminInvitationService } from '@/features/admin/invitationService'
import { absoluteInvitationLink, invitationVerdict } from '@/features/admin/invitationState'
import type { PageMeta } from '@/features/admin/listing'
import { describeAdminFailure } from '@/features/admin/refusals'

/**
 * Administration → Invitations: the only door into this installation.
 *
 * There is no open registration, so every account begins here. The screen is built around
 * one awkward fact, the same one the agent-token screen is built around: **the link is
 * readable exactly once.** The database keeps a hash of the token and nothing else, so a
 * link that is lost is not recoverable — it can only be replaced by withdrawing the
 * invitation and issuing another. Hence a panel that stays put until it is dismissed,
 * with the whole address and a copy button, rather than a toast that slides away while
 * somebody is switching windows to paste it.
 *
 * The second thing this screen has to be clear about is the administrator checkbox. An
 * invitation that grants the global role creates an account that can change everyone
 * else's access from its first sign-in, and whoever ticks that box should know it before
 * the account exists rather than after.
 */
const invitations = ref<AdminInvitation[]>([])
const meta = ref<PageMeta | null>(null)
const busy = ref(false)
const problem = ref<string | null>(null)

const email = ref('')
const grantsAdmin = ref(false)
const issuing = ref(false)

/** The freshly issued invitation, kept on screen until dismissed. See the note above. */
const issued = ref<IssuedInvitation | null>(null)
const copied = ref(false)

const confirmingRevoke = ref<string | null>(null)
const revoking = ref(false)

/** The moment the list was rendered — used to spot rows whose expiry has passed since the
 *  server computed their status. Read once per load, not per render, so the table does not
 *  quietly change its mind between two paints. */
const listedAt = ref(new Date())

const summary = computed(() =>
  meta.value === null
    ? null
    : describePage(invitations.value.length, meta.value, [
        'zaproszenie',
        'zaproszenia',
        'zaproszeń',
      ]),
)

/**
 * The link as a person can paste it.
 *
 * The backend answers with a path; the origin is this application's own, because the
 * invitation is opened in this application.
 */
const issuedLink = computed<string | null>(() => {
  const link = issued.value?.link ?? null

  return link === null ? null : absoluteInvitationLink(link, window.location.origin)
})

async function load(more = false): Promise<void> {
  busy.value = true
  problem.value = null

  try {
    const page = await adminInvitationService.list(more ? invitations.value.length : 0)

    invitations.value = more ? [...invitations.value, ...page.invitations] : page.invitations
    meta.value = page
    listedAt.value = new Date()
  } catch (cause) {
    if (!more) {
      invitations.value = []
      meta.value = null
    }
    problem.value = describeAdminFailure(cause, 'Nie udało się pobrać zaproszeń.')
  } finally {
    busy.value = false
  }
}

async function issue(): Promise<void> {
  issuing.value = true
  problem.value = null
  copied.value = false

  try {
    issued.value = await adminInvitationService.create(email.value, grantsAdmin.value)
    email.value = ''
    grantsAdmin.value = false
    await load()
  } catch (cause) {
    problem.value = describeAdminFailure(cause, 'Nie udało się wystawić zaproszenia.')
  } finally {
    issuing.value = false
  }
}

async function copyLink(): Promise<void> {
  const link = issuedLink.value

  if (link === null) {
    return
  }

  try {
    await navigator.clipboard.writeText(link)
    copied.value = true
  } catch {
    // Clipboard access is refused in some contexts. The address is on screen anyway, so
    // the worst case is selecting it by hand — which is why it is shown in full.
    copied.value = false
  }
}

async function revoke(invitation: AdminInvitation): Promise<void> {
  revoking.value = true
  problem.value = null

  try {
    await adminInvitationService.revoke(invitation.id)
    confirmingRevoke.value = null
    await load()
  } catch (cause) {
    problem.value = describeAdminFailure(cause, 'Nie udało się unieważnić zaproszenia.')
  } finally {
    revoking.value = false
  }
}

onMounted(() => void load())
</script>

<template>
  <div class="max-w-3xl space-y-4">
    <div>
      <h1 class="text-xl font-semibold">Zaproszenia</h1>
      <p class="text-sm text-muted">
        Rejestracji z ulicy nie ma — konto powstaje wyłącznie z przyjętego zaproszenia.
        Przyjęcie zakłada od razu prywatną przestrzeń tej osoby.
      </p>
    </div>

    <UAlert
      v-if="problem !== null"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :description="problem"
    />

    <!-- Zostaje na ekranie, aż ktoś je zamknie. Linku nie da się odczytać po raz drugi. -->
    <UCard v-if="issued !== null" class="border-primary">
      <template #header>
        <h2 class="font-medium">Zaproszenie dla {{ issued.invitation.email }} wystawione</h2>
      </template>

      <template v-if="issuedLink !== null">
        <UAlert
          color="warning"
          variant="subtle"
          class="mb-3"
          icon="i-lucide-eye-off"
          title="Ten link widzisz jeden raz"
          description="W bazie jest tylko skrót tokena. Po zamknięciu tego panelu nie da
            się go odzyskać — jedyne wyjście to unieważnić zaproszenie i wystawić nowe."
        />

        <p class="mb-2 text-sm text-muted">Przekaż to tej osobie:</p>
        <pre class="overflow-x-auto rounded bg-elevated p-3 text-xs">{{ issuedLink }}</pre>

        <UAlert
          v-if="issued.invitation.grantsGlobalAdmin"
          class="mt-3"
          color="warning"
          variant="subtle"
          icon="i-lucide-shield-alert"
          description="To zaproszenie nadaje rolę administratora globalnego. Konto po
            przyjęciu będzie mogło zmieniać role i wyłączać konta — także Twoje."
        />
      </template>

      <!-- Zaproszenie już istnieje: powstało, zanim ta odpowiedź dotarła. Dlatego ekran
           mówi, co się stało, a nie udaje, że wystawienie się nie udało. -->
      <UAlert
        v-else
        color="error"
        variant="subtle"
        icon="i-lucide-link-2-off"
        title="Zaproszenie powstało, ale backend nie zwrócił linku"
        description="Bez linku jest bezużyteczne, a odczytać go już nie da się nigdzie.
          Unieważnij je na liście poniżej i wystaw ponownie."
      />

      <p class="mt-3 text-xs text-muted">
        Wystawione {{ formatDateTimeOr(issued.invitation.createdAt, 'teraz') }} · wygasa
        {{ formatDateTimeOr(issued.invitation.expiresAt, 'nie wiadomo kiedy') }}
      </p>

      <template #footer>
        <div class="flex flex-col gap-2 sm:flex-row">
          <UButton v-if="issuedLink !== null" icon="i-lucide-clipboard" @click="copyLink">
            {{ copied ? 'Skopiowane' : 'Kopiuj link' }}
          </UButton>
          <UButton variant="ghost" color="neutral" @click="((issued = null), (copied = false))">
            {{ issuedLink === null ? 'Zamknij' : 'Przekazałem, zamknij' }}
          </UButton>
        </div>
      </template>
    </UCard>

    <UCard>
      <template #header>
        <h2 class="font-medium">Wystaw zaproszenie</h2>
      </template>

      <form class="space-y-4" @submit.prevent="issue">
        <UFormField
          label="Adres e-mail"
          name="email"
          help="Poczty jeszcze nie wysyłamy — link dostaniesz na ekran i przekazujesz go sam."
        >
          <UInput v-model="email" type="email" required class="w-full" />
        </UFormField>

        <UCheckbox v-model="grantsAdmin" label="Nadaj rolę administratora globalnego" />

        <!-- Ostrzeżenie pokazuje się przy zaznaczonym polu, nie po fakcie: konto z tą rolą
             może od pierwszego zalogowania zmieniać zasięg wszystkich pozostałych. -->
        <UAlert
          v-if="grantsAdmin"
          color="warning"
          variant="subtle"
          icon="i-lucide-shield-alert"
          title="Co to znaczy"
        >
          <template #description>
            <p>
              Ta osoba będzie mogła zarządzać kontami, zaproszeniami, przestrzeniami i
              rolami — w tym odebrać rolę Tobie i wyłączyć Twoje konto.
            </p>
            <p class="mt-1">
              Nie dostaje dostępu do treści przestrzeni, w których nie jest członkiem, także
              prywatnych. Może sobie taki dostęp nadać, ale to zostaje w dzienniku audytu.
            </p>
          </template>
        </UAlert>

        <UButton type="submit" :loading="issuing" :disabled="email.trim() === ''">
          Wystaw zaproszenie
        </UButton>
      </form>
    </UCard>

    <UCard>
      <template #header>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="font-medium">Wystawione zaproszenia</h2>
          <p v-if="summary !== null" class="text-xs text-muted">{{ summary }}</p>
        </div>
      </template>

      <p v-if="busy && invitations.length === 0" class="text-sm text-muted">Wczytuję…</p>
      <p v-else-if="invitations.length === 0" class="text-sm text-muted">
        Nikt jeszcze nikogo nie zaprosił z tego ekranu. Pierwsze konto powstaje zwykle
        poleceniem <code>ws:user:invite</code> w konsoli, i tamto zaproszenia tu nie zostawia.
      </p>

      <ul v-else class="divide-y divide-default">
        <li v-for="invitation in invitations" :key="invitation.id" class="py-3 first:pt-0 last:pb-0">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
              <p class="flex flex-wrap items-center gap-2">
                <span class="truncate font-medium">{{ invitation.email }}</span>
                <UBadge
                  :color="invitationVerdict(invitation, listedAt).badge.color"
                  :icon="invitationVerdict(invitation, listedAt).badge.icon"
                  variant="subtle"
                  size="sm"
                >
                  {{ invitationVerdict(invitation, listedAt).badge.label }}
                </UBadge>
                <UBadge
                  v-if="invitation.grantsGlobalAdmin"
                  color="warning"
                  variant="subtle"
                  icon="i-lucide-shield"
                  size="sm"
                >
                  nadaje rolę administratora
                </UBadge>
              </p>

              <p class="mt-1 text-xs text-muted">
                zaprosił {{ invitation.invitedBy }} ·
                {{ formatDateTimeOr(invitation.createdAt, 'bez daty') }}
                <template v-if="invitation.acceptedAt !== null">
                  · przyjęte {{ formatDateTimeOr(invitation.acceptedAt, 'bez daty') }}
                </template>
                <template v-else>
                  · wygasa {{ formatDateTimeOr(invitation.expiresAt, 'bez daty') }}
                </template>
              </p>

              <!-- Status policzył serwer w chwili pobrania listy. Karta otwarta od
                   godziny pokazuje „oczekuje" przy linku, który już nie działa — lepiej
                   to powiedzieć, niż pozwolić komuś przekazać martwy adres. -->
              <p
                v-if="invitationVerdict(invitation, listedAt).expiredWhileListed"
                class="mt-1 text-xs text-warning"
              >
                Termin minął już po pobraniu tej listy — link najpewniej nie działa.
                Odśwież ekran, żeby zobaczyć aktualny stan.
              </p>
            </div>

            <div class="shrink-0">
              <UButton
                v-if="
                  invitationVerdict(invitation, listedAt).canRevoke &&
                  confirmingRevoke !== invitation.id
                "
                size="sm"
                variant="ghost"
                color="error"
                icon="i-lucide-ban"
                @click="confirmingRevoke = invitation.id"
              >
                Unieważnij
              </UButton>

              <div
                v-else-if="invitationVerdict(invitation, listedAt).canRevoke"
                class="flex items-center gap-2"
              >
                <span class="text-xs text-muted">Odciąć ten link?</span>
                <UButton color="error" size="xs" :loading="revoking" @click="revoke(invitation)">
                  Tak
                </UButton>
                <UButton
                  variant="subtle"
                  color="neutral"
                  size="xs"
                  @click="confirmingRevoke = null"
                >
                  Nie
                </UButton>
              </div>
            </div>
          </div>
        </li>
      </ul>

      <template v-if="meta !== null && meta.hasMore" #footer>
        <UButton variant="subtle" size="sm" :loading="busy" @click="load(true)">
          Pokaż kolejne
        </UButton>
      </template>
    </UCard>
  </div>
</template>
