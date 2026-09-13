<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import { describePage, formatDateTimeOr } from '@/features/admin/format'
import type { PageMeta } from '@/features/admin/listing'
import { describeAdminFailure } from '@/features/admin/refusals'
import type { AdminSpace, SpaceMember, SpaceMemberRole } from '@/features/admin/spaceSchemas'
import { adminSpaceService } from '@/features/admin/spaceService'
import {
  canGrantAccess,
  grantConfirmationFor,
  grantRefusalNeedsInvitation,
  isSpaceMemberRole,
  memberActionsAvailable,
  memberRoleLabels,
  memberRoleOptions,
  privacyExplanation,
  spaceBadges,
  spaceCounts,
  type GrantConfirmation,
} from '@/features/admin/spaceState'

/**
 * Administration → Spaces: what exists, how much is in it, and who is in it.
 *
 * Membership is the point. A space is the unit of access in this installation — roles are
 * granted per space, and nothing else grants reading rights, not even the global
 * administrator role (which manages accounts and roles, and deliberately does not open
 * anybody's content). So this is the screen where somebody's reach actually changes.
 *
 * Granting access is the reason the screen exists at all. It takes an e-mail address rather
 * than picking from a list of accounts, because that is what the route takes — and because
 * the person being let in is by definition not on the membership list yet. An address with
 * no account behind it is the refusal worth handling well: it has an obvious next step, so
 * the refusal carries a link to the invitations screen instead of only a diagnosis.
 *
 * Members load on expansion rather than with the list. A page of twenty-five spaces would
 * otherwise be twenty-six requests, twenty-five of which nobody asked for — and the reader
 * opens this screen with one space in mind.
 *
 * **Private spaces get no member controls at all**, and that is the one rule this screen
 * enforces on its own. The backend refuses such a change with 422; offering the button
 * anyway would produce a refusal a click later, and a refusal a click later is how people
 * learn to distrust an interface. The reason is stated on the row itself, because "why is
 * there nothing here" is a fair question.
 */
const spaces = ref<AdminSpace[]>([])
const meta = ref<PageMeta | null>(null)
const busy = ref(false)
const problem = ref<string | null>(null)

/** Slugs whose members are on screen. A list rather than one slug: comparing two spaces
 *  is the normal reason to be here. */
const expanded = ref<string[]>([])
const members = ref<Record<string, SpaceMember[]>>({})
const membersBusy = ref<string[]>([])
const membersProblem = ref<Record<string, string>>({})

/** `slug:userId` of the membership whose request is in flight, and of the removal being
 *  confirmed. Keys rather than booleans, so one spinner belongs to one row. */
const savingMember = ref<string | null>(null)
const confirmingRemoval = ref<string | null>(null)
const refusal = ref<{ key: string; message: string } | null>(null)

/** The grant form, kept per space rather than once for the screen: two spaces can be open
 *  at the same time, and a half-typed address belongs to the one it was typed into. */
const grantEmail = ref<Record<string, string>>({})
const grantRole = ref<Record<string, SpaceMemberRole>>({})
const grantBusy = ref<string | null>(null)
const grantConfirming = ref<string | null>(null)
const grantRefusal = ref<{
  slug: string
  message: string
  /** Whether the refusal is worth answering with a link rather than with a retry. */
  needsInvitation: boolean
} | null>(null)

const summary = computed(() =>
  meta.value === null
    ? null
    : describePage(spaces.value.length, meta.value, ['przestrzeń', 'przestrzenie', 'przestrzeni']),
)

async function load(more = false): Promise<void> {
  busy.value = true
  problem.value = null

  try {
    const page = await adminSpaceService.list(more ? spaces.value.length : 0)

    spaces.value = more ? [...spaces.value, ...page.spaces] : page.spaces
    meta.value = page
  } catch (cause) {
    if (!more) {
      spaces.value = []
      meta.value = null
    }
    problem.value = describeAdminFailure(cause, 'Nie udało się pobrać przestrzeni.')
  } finally {
    busy.value = false
  }
}

function isExpanded(space: AdminSpace): boolean {
  return expanded.value.includes(space.slug)
}

async function toggle(space: AdminSpace): Promise<void> {
  if (isExpanded(space)) {
    expanded.value = expanded.value.filter((slug) => slug !== space.slug)

    return
  }

  expanded.value = [...expanded.value, space.slug]

  // Fetched once and kept: collapsing and expanding again is how somebody scrolls a long
  // list, not a request to re-read the membership.
  if (members.value[space.slug] === undefined) {
    await loadMembers(space)
  }
}

async function loadMembers(space: AdminSpace): Promise<void> {
  membersBusy.value = [...membersBusy.value, space.slug]

  const withoutOldProblem = { ...membersProblem.value }
  delete withoutOldProblem[space.slug]
  membersProblem.value = withoutOldProblem

  try {
    members.value = { ...members.value, [space.slug]: await adminSpaceService.members(space.slug) }
  } catch (cause) {
    membersProblem.value = {
      ...membersProblem.value,
      [space.slug]: describeAdminFailure(cause, 'Nie udało się pobrać składu przestrzeni.'),
    }
  } finally {
    membersBusy.value = membersBusy.value.filter((slug) => slug !== space.slug)
  }
}

function memberKey(space: AdminSpace, member: SpaceMember): string {
  return `${space.slug}:${member.userId}`
}

/** Asks about one removal, and clears a refusal left over from a previous attempt. */
function askRemoval(space: AdminSpace, member: SpaceMember): void {
  confirmingRemoval.value = memberKey(space, member)
  refusal.value = null
}

function grantEmailOf(space: AdminSpace): string {
  return grantEmail.value[space.slug] ?? ''
}

/** Reader until somebody says otherwise: the narrowest of the three, so a grant sent in a
 *  hurry gives away the least. */
function grantRoleOf(space: AdminSpace): SpaceMemberRole {
  return grantRole.value[space.slug] ?? 'reader'
}

function setGrantEmail(space: AdminSpace, value: string): void {
  grantEmail.value = { ...grantEmail.value, [space.slug]: value }
  // A confirmation and a refusal were both about the address that was there a moment ago.
  grantConfirming.value = null
  clearGrantRefusal(space)
}

/** Narrowed rather than cast, as with the role picker on a row: the component's payload is
 *  loosely typed and goes straight into a request body. */
function setGrantRole(space: AdminSpace, value: unknown): void {
  if (!isSpaceMemberRole(value)) {
    return
  }

  grantRole.value = { ...grantRole.value, [space.slug]: value }
  grantConfirming.value = null
}

function clearGrantRefusal(space: AdminSpace): void {
  if (grantRefusal.value?.slug === space.slug) {
    grantRefusal.value = null
  }
}

function grantConfirmation(space: AdminSpace): GrantConfirmation | null {
  return grantConfirmationFor(space, grantEmailOf(space), grantRoleOf(space))
}

/**
 * The form's own button — and, for the admin role, deliberately not the button that sends.
 *
 * Where a confirmation is due this only opens it, every time, even if it is already open.
 * The alternative is that pressing Enter twice hands out the role that can hand out the
 * space, without anybody having read what it gives.
 */
async function submitGrant(space: AdminSpace): Promise<void> {
  if (!canGrantAccess(space, grantEmailOf(space))) {
    return
  }

  if (grantConfirmation(space) !== null) {
    grantConfirming.value = space.slug
    clearGrantRefusal(space)

    return
  }

  await sendGrant(space)
}

/**
 * Grants the role, then re-reads the membership.
 *
 * Re-read rather than stitched together from the answer: the route upserts and answers with
 * an address and a role, so it says neither whether somebody new appeared nor what their
 * name is, when they were added or who added them — and those are what the row shows. The
 * private-space rule is checked again here for the same reason as in the other two
 * handlers: the check that decides what is drawn and the check that guards the request are
 * one function, so there is no path where one holds and the other does not.
 */
async function sendGrant(space: AdminSpace): Promise<void> {
  if (!canGrantAccess(space, grantEmailOf(space))) {
    return
  }

  grantBusy.value = space.slug
  clearGrantRefusal(space)

  try {
    await adminSpaceService.grantAccess(space.slug, grantEmailOf(space), grantRoleOf(space))

    grantConfirming.value = null
    grantEmail.value = { ...grantEmail.value, [space.slug]: '' }
    await loadMembers(space)
    syncMemberCount(space)
  } catch (cause) {
    // The confirmation panel stays open where there was one: the refusal answers the
    // question it asked.
    grantRefusal.value = {
      slug: space.slug,
      message: describeAdminFailure(cause, 'Nie udało się nadać dostępu.'),
      needsInvitation: grantRefusalNeedsInvitation(cause),
    }
  } finally {
    grantBusy.value = null
  }
}

/**
 * Sets the row's counter from the membership just read.
 *
 * Not incremented or decremented here: the number came from the server and is the thing a
 * reader glances at. Arithmetic on it would be right until two administrators worked at
 * once, and then wrong with no sign of it.
 */
function syncMemberCount(space: AdminSpace): void {
  const count = members.value[space.slug]?.length

  if (count === undefined) {
    return
  }

  spaces.value = spaces.value.map((row) =>
    row.slug === space.slug ? { ...row, memberCount: count } : row,
  )
}

/**
 * Changes one role.
 *
 * The picker's payload arrives loosely typed, so it is narrowed rather than cast: an
 * unchecked value here would travel straight into a request body. The private-space rule
 * is checked again, even though the control is not rendered for such a space — the check
 * that decides what is drawn and the check that guards the request are the same function,
 * so there is no path where one holds and the other does not.
 */
async function changeRole(space: AdminSpace, member: SpaceMember, value: unknown): Promise<void> {
  if (!memberActionsAvailable(space) || !isSpaceMemberRole(value) || value === member.role) {
    return
  }

  const key = memberKey(space, member)
  savingMember.value = key
  refusal.value = null

  try {
    const updated = await adminSpaceService.setRole(space.slug, member.userId, value)

    members.value = {
      ...members.value,
      [space.slug]: (members.value[space.slug] ?? []).map((row) =>
        row.userId === updated.userId ? updated : row,
      ),
    }
  } catch (cause) {
    refusal.value = { key, message: describeAdminFailure(cause, 'Nie udało się zmienić roli.') }
  } finally {
    savingMember.value = null
  }
}

/**
 * Takes somebody out of a space.
 *
 * Afterwards the membership list is re-read and the counter set from it — see
 * `syncMemberCount` for why it is not simply decremented.
 */
async function removeMember(space: AdminSpace, member: SpaceMember): Promise<void> {
  if (!memberActionsAvailable(space)) {
    return
  }

  const key = memberKey(space, member)
  savingMember.value = key
  refusal.value = null

  try {
    await adminSpaceService.removeMember(space.slug, member.userId)
    confirmingRemoval.value = null
    await loadMembers(space)
    syncMemberCount(space)
  } catch (cause) {
    refusal.value = {
      key,
      message: describeAdminFailure(cause, 'Nie udało się usunąć z przestrzeni.'),
    }
  } finally {
    savingMember.value = null
  }
}

onMounted(() => void load())
</script>

<template>
  <div class="max-w-4xl space-y-4">
    <div>
      <h1 class="text-xl font-semibold">Przestrzenie</h1>
      <p class="text-sm text-muted">
        Przestrzeń jest jednostką dostępu: rola nadana tutaj decyduje, kto co czyta i
        zapisuje. Rola administratora globalnego tego nie zastępuje — nie otwiera treści
        przestrzeni, w których nie jesteś członkiem.
      </p>
    </div>

    <UAlert
      v-if="problem !== null"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :description="problem"
    />

    <p v-if="busy && spaces.length === 0" class="text-sm text-muted">Wczytuję…</p>

    <template v-else>
      <p v-if="summary !== null" class="text-sm text-muted">{{ summary }}</p>

      <UCard v-if="spaces.length === 0 && problem === null">
        <p class="font-medium">Nie ma ani jednej przestrzeni.</p>
        <p class="mt-1 text-sm text-muted">
          To niespodzianka: przyjęcie zaproszenia zakłada prywatną przestrzeń, więc pusta
          lista oznacza, że nikt jeszcze nie przyjął zaproszenia — albo że backend
          odpowiedział czymś innym, niż myśli.
        </p>
      </UCard>

      <UCard v-for="space in spaces" :key="space.slug">
        <template #header>
          <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
              <h2 class="flex flex-wrap items-center gap-2 font-medium">
                <span class="truncate">{{ space.name }}</span>
                <UBadge
                  v-for="badge in spaceBadges(space)"
                  :key="badge.label"
                  :color="badge.color"
                  :icon="badge.icon"
                  variant="subtle"
                  size="sm"
                >
                  {{ badge.label }}
                </UBadge>
              </h2>
              <p class="truncate text-xs text-muted">
                <code>{{ space.slug }}</code>
                · skrzydło pałacu: {{ space.palaceWing ?? 'nieprzypisane' }} · założona
                {{ formatDateTimeOr(space.createdAt, 'nie wiadomo kiedy') }}
              </p>
            </div>

            <UButton
              size="sm"
              variant="subtle"
              color="neutral"
              :icon="isExpanded(space) ? 'i-lucide-chevron-down' : 'i-lucide-chevron-right'"
              :loading="membersBusy.includes(space.slug)"
              @click="toggle(space)"
            >
              {{ isExpanded(space) ? 'Ukryj skład' : 'Pokaż skład' }}
            </UButton>
          </div>
        </template>

        <p v-if="space.description !== null && space.description !== ''" class="text-sm">
          {{ space.description }}
        </p>
        <p class="mt-1 text-sm text-muted">{{ spaceCounts(space) }}</p>

        <template v-if="isExpanded(space)">
          <!-- Powiedziane tam, gdzie brakuje przycisków. Backend odmówi zmiany składu
               prywatnej przestrzeni (422), więc ekran o nią nie prosi. -->
          <UAlert
            v-if="privacyExplanation(space) !== null"
            class="mt-3"
            color="warning"
            variant="subtle"
            icon="i-lucide-lock"
            title="Prywatna — składu nie zmienia się tutaj"
            :description="privacyExplanation(space) ?? ''"
          />

          <UAlert
            v-if="membersProblem[space.slug] !== undefined"
            class="mt-3"
            color="error"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            :description="membersProblem[space.slug] ?? ''"
          />

          <p
            v-else-if="membersBusy.includes(space.slug)"
            class="mt-3 text-sm text-muted"
          >
            Wczytuję skład…
          </p>

          <p
            v-else-if="(members[space.slug] ?? []).length === 0"
            class="mt-3 text-sm text-muted"
          >
            Nikogo tu nie ma. Przestrzeń bez członków jest niewidoczna dla wszystkich poza
            tym ekranem.
          </p>

          <ul v-else class="mt-3 divide-y divide-default border-t border-default">
            <li v-for="member in members[space.slug] ?? []" :key="member.userId" class="py-3">
              <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                  <p class="truncate text-sm font-medium">{{ member.displayName }}</p>
                  <p class="truncate text-xs text-muted">
                    {{ member.email }} · dodany
                    {{ formatDateTimeOr(member.addedAt, 'nie wiadomo kiedy') }}
                    <template v-if="member.addedBy !== null"> przez {{ member.addedBy }}</template>
                  </p>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                  <template v-if="memberActionsAvailable(space)">
                    <USelect
                      :model-value="member.role"
                      :items="memberRoleOptions"
                      value-key="value"
                      size="sm"
                      class="min-w-44"
                      :loading="savingMember === memberKey(space, member)"
                      @update:model-value="changeRole(space, member, $event)"
                    />

                    <UButton
                      v-if="confirmingRemoval !== memberKey(space, member)"
                      size="xs"
                      variant="ghost"
                      color="error"
                      icon="i-lucide-user-minus"
                      @click="askRemoval(space, member)"
                    >
                      Usuń
                    </UButton>
                    <template v-else>
                      <span class="text-xs text-muted">Odebrać dostęp?</span>
                      <UButton
                        color="error"
                        size="xs"
                        :loading="savingMember === memberKey(space, member)"
                        @click="removeMember(space, member)"
                      >
                        Tak
                      </UButton>
                      <UButton
                        variant="subtle"
                        color="neutral"
                        size="xs"
                        @click="confirmingRemoval = null"
                      >
                        Nie
                      </UButton>
                    </template>
                  </template>

                  <!-- Prywatna przestrzeń: rola pokazana słowem, bez kontrolki. -->
                  <span v-else class="text-xs text-muted">
                    {{ memberRoleLabels[member.role] }}
                  </span>
                </div>
              </div>

              <UAlert
                v-if="refusal !== null && refusal.key === memberKey(space, member)"
                class="mt-2"
                color="error"
                variant="subtle"
                icon="i-lucide-x-circle"
                :description="refusal.message"
              />
            </li>
          </ul>

          <!-- Formularz tylko tam, gdzie pozostałe akcje na członkach. Przy przestrzeni
               prywatnej backend odmówi (403), a pole, które prowadzi do odmowy, uczy nie
               ufać interfejsowi — tak samo jak przycisk. -->
          <form
            v-if="memberActionsAvailable(space)"
            class="mt-4 border-t border-default pt-4"
            @submit.prevent="submitGrant(space)"
          >
            <h3 class="text-sm font-medium">Nadaj dostęp kolejnej osobie</h3>
            <p class="mt-1 text-xs text-muted">
              Ten adres musi już mieć konto — dostęp nadaje się osobie, nie zaproszeniu.
              Osobie, która jest tu członkiem, wybrana rola zastąpi obecną.
            </p>

            <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end">
              <UFormField label="Adres e-mail" :name="`grant-email-${space.slug}`" class="flex-1">
                <UInput
                  :model-value="grantEmailOf(space)"
                  type="email"
                  autocomplete="off"
                  class="w-full"
                  @update:model-value="setGrantEmail(space, String($event))"
                />
              </UFormField>

              <UFormField label="Rola" :name="`grant-role-${space.slug}`">
                <USelect
                  :model-value="grantRoleOf(space)"
                  :items="memberRoleOptions"
                  value-key="value"
                  class="w-full sm:min-w-44"
                  @update:model-value="setGrantRole(space, $event)"
                />
              </UFormField>

              <UButton
                type="submit"
                icon="i-lucide-user-plus"
                :loading="grantBusy === space.slug"
                :disabled="
                  !canGrantAccess(space, grantEmailOf(space)) || grantConfirming === space.slug
                "
              >
                Nadaj dostęp
              </UButton>
            </div>

            <!-- Potwierdzenie mówi, co ta rola daje, a nie „jesteś pewien?". Administrator
                 przestrzeni jest jedyną z trzech ról, która poszerza zasięg dalej niż o
                 czytanie i pisanie — może wpuścić tu kolejne osoby. -->
            <UAlert
              v-if="grantConfirming === space.slug && grantConfirmation(space) !== null"
              class="mt-3"
              color="warning"
              variant="subtle"
              icon="i-lucide-shield-alert"
              :title="grantConfirmation(space)?.title ?? ''"
            >
              <template #description>
                <p>
                  Administrator przestrzeni nadaje i odbiera w niej role: może wpuścić
                  kolejne osoby — także bez pytania Ciebie — i usunąć stąd każdego.
                </p>
                <p class="mt-1">
                  Czyta i zapisuje wszystko, co w tej przestrzeni jest. Jeśli chodziło o
                  samo czytanie albo pisanie, wybierz węższą rolę: rolę można później
                  zmienić, ale tego, co ktoś zdążył przeczytać, nie da się odczytać z
                  powrotem.
                </p>

                <UAlert
                  v-if="grantRefusal !== null && grantRefusal.slug === space.slug"
                  class="mt-2"
                  color="error"
                  variant="subtle"
                  icon="i-lucide-x-circle"
                  :description="grantRefusal.message"
                />

                <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                  <UButton
                    color="warning"
                    size="sm"
                    :loading="grantBusy === space.slug"
                    @click="sendGrant(space)"
                  >
                    {{ grantConfirmation(space)?.confirmLabel ?? '' }}
                  </UButton>
                  <UButton
                    size="sm"
                    variant="subtle"
                    color="neutral"
                    @click="grantConfirming = null"
                  >
                    Nie teraz
                  </UButton>
                </div>
              </template>
            </UAlert>

            <!-- Odmowa cytowana z backendu: powód opisany w dwóch miejscach rozjeżdża się
                 przy pierwszej zmianie reguły. Odmowa o brakującym koncie ma znany
                 następny krok, więc dostaje odnośnik, nie samą diagnozę. -->
            <UAlert
              v-else-if="grantRefusal !== null && grantRefusal.slug === space.slug"
              class="mt-3"
              color="error"
              variant="subtle"
              icon="i-lucide-x-circle"
            >
              <template #description>
                <!-- Sięgnięte przez `?.`, bo wewnątrz slotu zawężenie z `v-else-if` nie
                     obowiązuje. -->
                <p>{{ grantRefusal?.message ?? '' }}</p>

                <UButton
                  v-if="grantRefusal?.needsInvitation === true"
                  class="mt-2"
                  size="xs"
                  variant="subtle"
                  color="error"
                  icon="i-lucide-mail-plus"
                  :to="{ name: 'admin-invitations' }"
                >
                  Przejdź do zaproszeń
                </UButton>
              </template>
            </UAlert>
          </form>
        </template>
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
