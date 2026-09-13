<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'

import { describePage, formatDateTimeOr } from '@/features/admin/format'
import type { PageMeta } from '@/features/admin/listing'
import { describeAdminFailure } from '@/features/admin/refusals'
import type { AdminUser } from '@/features/admin/userSchemas'
import { adminUserService } from '@/features/admin/userService'
import {
  activityBadge,
  activityChangeFor,
  deactivationNotice,
  isViewer,
  resourceSummary,
  roleBadge,
  roleChangeFor,
} from '@/features/admin/userState'
import { useAuthStore } from '@/stores/auth'

/**
 * Administration → Accounts: who may use this installation, and with what reach.
 *
 * Two actions, and both are asked about first. That is not uniform caution — it is what
 * the actions do. The global role hands somebody the ability to change everyone else's
 * access, including the reader's own; switching an account off cuts that person's agents
 * mid-sentence, and they will read it as the gateway breaking rather than as a decision
 * somebody made on this screen. Neither is destructive, and that is precisely why they
 * deserve a sentence: a mistake here is invisible until it bites.
 *
 * **Refusals are quoted, not restated.** The backend blocks taking the role off yourself,
 * off the last administrator, and deactivating your own account, and it explains which
 * one it was in `error`. Writing those sentences here as well would give the installation
 * two descriptions of one rule, and they would disagree the first time the rule moved.
 * So the panel shows both buttons, sends the request, and prints what came back.
 */
const auth = useAuthStore()

const users = ref<AdminUser[]>([])
const meta = ref<PageMeta | null>(null)
const query = ref('')
const busy = ref(false)
const problem = ref<string | null>(null)

/** Which row is being asked about, and about what — the two actions confirm separately. */
const confirming = ref<string | null>(null)
/** The row whose request is in flight. A bare boolean would spin every button at once. */
const saving = ref<string | null>(null)
/**
 * A refusal shown **at the row it belongs to**, not at the top of the screen. These
 * messages name a person and a rule ("nie możesz odebrać roli sobie"), and a list of
 * lookalike rows with one sentence floating above it is how the wrong row gets blamed.
 */
const refusal = ref<{ id: string; message: string } | null>(null)

const summary = computed(() =>
  meta.value === null
    ? null
    : describePage(users.value.length, meta.value, ['użytkownik', 'użytkownicy', 'użytkowników']),
)

async function load(more = false): Promise<void> {
  busy.value = true
  problem.value = null

  try {
    const page = await adminUserService.list(query.value, more ? users.value.length : 0)

    users.value = more ? [...users.value, ...page.users] : page.users
    meta.value = page
  } catch (cause) {
    if (!more) {
      users.value = []
      meta.value = null
    }
    problem.value = describeAdminFailure(cause, 'Nie udało się pobrać listy kont.')
  } finally {
    busy.value = false
  }
}

/**
 * Runs one of the two changes.
 *
 * The answer replaces the row rather than triggering a reload: the endpoint returns the
 * account as it now is, and re-reading the whole page would also re-apply the search and
 * throw away every page the reader had loaded past the first.
 */
async function apply(user: AdminUser, action: 'role' | 'activity'): Promise<void> {
  const key = rowKey(user, action)
  saving.value = key
  refusal.value = null

  try {
    const updated =
      action === 'role'
        ? await adminUserService.setGlobalRole(user.id, roleChangeFor(user).value)
        : await adminUserService.setActivity(user.id, activityChangeFor(user).value)

    users.value = users.value.map((row) => (row.id === updated.id ? updated : row))
    confirming.value = null

    // The reader's own row carries the role that decides whether this screen is reachable
    // at all, so the session is re-read rather than left to find out on the next
    // navigation. `restore()` is the store's "ask the API who I am again" — the same call
    // a page load makes.
    if (isViewer(user, auth.user?.id ?? null)) {
      await auth.restore()
    }
  } catch (cause) {
    // The confirmation panel stays open: the refusal is an answer to the question it
    // asked, and closing it would file that answer next to nothing.
    refusal.value = {
      id: user.id,
      message: describeAdminFailure(cause, 'Nie udało się zapisać zmiany.'),
    }
  } finally {
    saving.value = null
  }
}

function rowKey(user: AdminUser, action: 'role' | 'activity'): string {
  return `${user.id}:${action}`
}

/** Opens one confirmation, and clears any refusal left over from a previous attempt —
 *  a "no" to an earlier question has no business sitting under a new one. */
function ask(user: AdminUser, action: 'role' | 'activity'): void {
  confirming.value = rowKey(user, action)
  refusal.value = null
}

function cancel(): void {
  confirming.value = null
  refusal.value = null
}

/**
 * Typing waits a moment before asking.
 *
 * Without it every keystroke is a request, and the answers arrive out of order — the list
 * settles on whichever reply was slowest, which for a search box means the wrong one.
 */
const SEARCH_DELAY_MS = 300
let debounce: number | null = null

watch(query, () => {
  if (debounce !== null) {
    window.clearTimeout(debounce)
  }

  debounce = window.setTimeout(() => void load(), SEARCH_DELAY_MS)
})

onUnmounted(() => {
  if (debounce !== null) {
    window.clearTimeout(debounce)
  }
})

onMounted(() => void load())
</script>

<template>
  <!-- Odmowa dla osoby bez roli administratora należy do `AdminLayout`: obowiązuje każdy
       ekran administracyjny, a powtórzona na każdym z nich rozjedzie się przy pierwszym,
       który o niej zapomni. -->
  <div class="max-w-4xl space-y-4">
    <div>
      <h1 class="text-xl font-semibold">Konta</h1>
      <p class="text-sm text-muted">
        Kto może korzystać z tej instalacji i jak daleko sięga. Konta nie powstają tutaj —
        powstają z przyjętego zaproszenia. Tutaj się je wyłącza i nadaje role.
      </p>
    </div>

    <UInput
      v-model="query"
      icon="i-lucide-search"
      placeholder="Szukaj po nazwie lub adresie e-mail"
      class="w-full sm:max-w-md"
    />

    <UAlert
      v-if="problem !== null"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :description="problem"
    />

    <p v-if="busy && users.length === 0" class="text-sm text-muted">Wczytuję…</p>

    <template v-else>
      <p v-if="summary !== null" class="text-sm text-muted">{{ summary }}</p>

      <UCard v-if="users.length === 0 && problem === null">
        <p class="font-medium">Nic nie pasuje.</p>
        <p class="mt-1 text-sm text-muted">
          {{
            query.trim() === ''
              ? 'Backend nie zwrócił ani jednego konta. To niespodzianka — czytasz ten ekran z jakiegoś konta.'
              : 'Żadne konto nie odpowiada temu zapytaniu. Osoba, której szukasz, może mieć jeszcze tylko zaproszenie — sprawdź ekran zaproszeń.'
          }}
        </p>
      </UCard>

      <UCard v-else>
        <ul class="divide-y divide-default">
          <li v-for="user in users" :key="user.id" class="py-3 first:pt-0 last:pb-0">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div class="min-w-0">
                <p class="flex flex-wrap items-center gap-2 font-medium">
                  <span class="truncate">{{ user.displayName }}</span>
                  <!-- Both odmowy backendu dotyczą działania na sobie, więc wiersz
                       czytającego jest oznaczony: inaczej 409 wygląda na awarię. -->
                  <UBadge v-if="isViewer(user, auth.user?.id ?? null)" color="primary" variant="soft" size="sm">
                    to Ty
                  </UBadge>
                </p>
                <p class="truncate text-sm text-muted">{{ user.email }}</p>

                <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                  <UBadge
                    :color="roleBadge(user).color"
                    :icon="roleBadge(user).icon"
                    variant="subtle"
                    size="sm"
                  >
                    {{ roleBadge(user).label }}
                  </UBadge>
                  <UBadge
                    :color="activityBadge(user).color"
                    :icon="activityBadge(user).icon"
                    variant="subtle"
                    size="sm"
                  >
                    {{ activityBadge(user).label }}
                  </UBadge>
                </div>

                <p class="mt-1.5 text-xs text-muted">
                  {{ resourceSummary(user) }} · dołączył
                  {{ formatDateTimeOr(user.createdAt, 'nie wiadomo kiedy') }} · ostatnie
                  logowanie: {{ formatDateTimeOr(user.lastLoginAt, 'nigdy') }}
                </p>
              </div>

              <div class="flex shrink-0 flex-wrap gap-2">
                <UButton
                  size="sm"
                  variant="subtle"
                  color="neutral"
                  icon="i-lucide-shield"
                  :disabled="confirming === rowKey(user, 'role')"
                  @click="ask(user, 'role')"
                >
                  {{ roleChangeFor(user).buttonLabel }}
                </UButton>
                <UButton
                  size="sm"
                  variant="subtle"
                  :color="user.isActive ? 'error' : 'primary'"
                  :icon="user.isActive ? 'i-lucide-ban' : 'i-lucide-rotate-ccw'"
                  :disabled="confirming === rowKey(user, 'activity')"
                  @click="ask(user, 'activity')"
                >
                  {{ activityChangeFor(user).buttonLabel }}
                </UButton>
              </div>
            </div>

            <!-- Potwierdzenie mówi, co się stanie, a nie „jesteś pewien?". Obie zmiany są
                 odwracalne i właśnie dlatego są groźne: pomyłki nie widać, dopóki nie
                 zaboli. -->
            <UAlert
              v-if="confirming === rowKey(user, 'role')"
              class="mt-3"
              color="warning"
              variant="subtle"
              icon="i-lucide-shield-alert"
              :title="roleChangeFor(user).confirmTitle"
            >
              <template #description>
                <template v-if="!user.isGlobalAdmin">
                  <p>
                    Rola administratora globalnego pozwala zarządzać kontami, zaproszeniami,
                    przestrzeniami i rolami — <strong>w tym odebrać rolę Tobie</strong>.
                  </p>
                  <p class="mt-1">
                    Nie daje dostępu do treści przestrzeni, w których ta osoba nie jest
                    członkiem, także prywatnych. Może sobie taki dostęp nadać — i wtedy
                    zostaje to w dzienniku audytu, co jest całą różnicą między czynnością,
                    z której da się rozliczyć, a cichym czytaniem.
                  </p>
                </template>
                <template v-else>
                  <p>
                    Ta osoba straci dostęp do panelu administracyjnego. Członkostwa w
                    przestrzeniach, prywatna przestrzeń i tokeny agentów zostają bez zmian —
                    to jest odebranie roli, nie wyłączenie konta.
                  </p>
                </template>

                <UAlert
                  v-if="refusal !== null && refusal.id === user.id"
                  class="mt-2"
                  color="error"
                  variant="subtle"
                  icon="i-lucide-x-circle"
                  :description="refusal.message"
                />

                <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                  <UButton
                    color="warning"
                    size="sm"
                    :loading="saving === rowKey(user, 'role')"
                    @click="apply(user, 'role')"
                  >
                    {{ roleChangeFor(user).confirmLabel }}
                  </UButton>
                  <UButton
                    size="sm"
                    variant="subtle"
                    color="neutral"
                    @click="cancel"
                  >
                    Nie teraz
                  </UButton>
                </div>
              </template>
            </UAlert>

            <UAlert
              v-if="confirming === rowKey(user, 'activity')"
              class="mt-3"
              :color="user.isActive ? 'error' : 'primary'"
              variant="subtle"
              icon="i-lucide-power"
              :title="activityChangeFor(user).confirmTitle"
            >
              <template #description>
                <template v-if="user.isActive">
                  <p>{{ deactivationNotice(user) }}</p>
                  <p class="mt-1">
                    Konto zostaje w bazie razem ze wszystkim, co napisało — to wyłączenie,
                    nie usunięcie, i da się je włączyć z powrotem.
                  </p>
                </template>
                <template v-else>
                  <p>
                    Konto znów będzie mogło się zalogować i korzystać z API. Jeśli tokeny
                    agentów zostały przy wyłączeniu unieważnione, trzeba wystawić nowe —
                    unieważnienia się nie cofa.
                  </p>
                </template>

                <UAlert
                  v-if="refusal !== null && refusal.id === user.id"
                  class="mt-2"
                  color="error"
                  variant="subtle"
                  icon="i-lucide-x-circle"
                  :description="refusal.message"
                />

                <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                  <UButton
                    :color="user.isActive ? 'error' : 'primary'"
                    size="sm"
                    :loading="saving === rowKey(user, 'activity')"
                    @click="apply(user, 'activity')"
                  >
                    {{ activityChangeFor(user).confirmLabel }}
                  </UButton>
                  <UButton
                    size="sm"
                    variant="subtle"
                    color="neutral"
                    @click="cancel"
                  >
                    Nie teraz
                  </UButton>
                </div>
              </template>
            </UAlert>
          </li>
        </ul>
      </UCard>

      <UButton
        v-if="meta !== null && meta.hasMore"
        variant="subtle"
        size="sm"
        :loading="busy"
        @click="load(true)"
      >
        Pokaż kolejne
      </UButton>
    </template>
  </div>
</template>
