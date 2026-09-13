<script setup lang="ts">
import { onMounted, ref } from 'vue'

import { tokenService } from '@/features/tokens/service'
import type { AgentToken, IssuedAgentToken } from '@/features/tokens/schemas'
import { useAuthStore } from '@/stores/auth'

/**
 * Agent tokens: issuing, scoping, retiring.
 *
 * The screen is built around one awkward fact — the secret is visible **once**. So the
 * newly issued token is not a toast that disappears, but a panel that stays until the
 * person dismisses it, with the ready `claude mcp add` command to copy. Anything more
 * subtle produces a token nobody wrote down and a puzzled user.
 *
 * `lastUsedAt` is shown for every token because it is the field that makes retiring
 * one safe. Without it the list only grows, since nobody can tell what is still in use.
 */
const auth = useAuthStore()

const tokens = ref<AgentToken[]>([])
const loading = ref(false)
const problem = ref<string | null>(null)

const label = ref('')
const scopeAll = ref(true)
const chosenSpaces = ref<string[]>([])
const issuing = ref(false)
const issued = ref<IssuedAgentToken | null>(null)
const copied = ref(false)

async function load(): Promise<void> {
  loading.value = true
  problem.value = null

  try {
    tokens.value = await tokenService.list()
  } catch (cause) {
    problem.value = cause instanceof Error ? cause.message : 'Nie udało się pobrać tokenów.'
  } finally {
    loading.value = false
  }
}

async function issue(): Promise<void> {
  issuing.value = true
  problem.value = null
  copied.value = false

  try {
    issued.value = await tokenService.issue(
      label.value,
      scopeAll.value ? null : chosenSpaces.value,
    )
    label.value = ''
    chosenSpaces.value = []
    scopeAll.value = true
    await load()
  } catch (cause) {
    problem.value = cause instanceof Error ? cause.message : 'Nie udało się wystawić tokena.'
  } finally {
    issuing.value = false
  }
}

/**
 * Which token the reader is being asked about.
 *
 * Revoking takes effect at the agent's very next request and cannot be undone — the
 * only way back is issuing a new token and reconfiguring whatever used the old one.
 * A single click is too little ceremony for that, especially in a list where the rows
 * look alike.
 */
const confirmingRevoke = ref<string | null>(null)
const revoking = ref(false)

async function revoke(token: AgentToken): Promise<void> {
  problem.value = null
  revoking.value = true

  try {
    await tokenService.revoke(token.id)
    confirmingRevoke.value = null
    await load()
  } catch (cause) {
    problem.value = cause instanceof Error ? cause.message : 'Nie udało się unieważnić tokena.'
  } finally {
    revoking.value = false
  }
}

async function copyCommand(): Promise<void> {
  if (issued.value === null) {
    return
  }

  try {
    await navigator.clipboard.writeText(issued.value.setupCommand)
    copied.value = true
  } catch {
    // Clipboard access is refused in some contexts. The command is on screen anyway,
    // so the worst case is copying it by hand.
    copied.value = false
  }
}

function whenUsed(token: AgentToken): string {
  if (token.revokedAt !== null) {
    return 'unieważniony'
  }

  return token.lastUsedAt === null ? 'nigdy nieużywany' : new Date(token.lastUsedAt).toLocaleString('pl-PL')
}

onMounted(load)
</script>

<template>
  <div class="max-w-3xl space-y-6">
    <div>
      <h1 class="text-xl font-semibold">Tokeny agentów AI</h1>
      <p class="text-muted text-sm">
        Token daje agentowi dostęp do tych przestrzeni, które widzisz Ty — nigdy do
        większej liczby. Odebranie Ci roli odbiera ją natychmiast wszystkim Twoim agentom.
      </p>
    </div>

    <UAlert v-if="problem !== null" color="error" variant="subtle" :description="problem" />

    <!-- Stays on screen until dismissed. The secret cannot be read again. -->
    <UCard v-if="issued !== null" class="border-primary">
      <template #header>
        <h2 class="font-medium">Token „{{ issued.label }}” wystawiony</h2>
      </template>

      <UAlert
        color="warning"
        variant="subtle"
        class="mb-3"
        title="Widzisz go jeden raz"
        description="W bazie jest tylko skrót. Jeśli go zgubisz, wystaw nowy — odczytać się nie da."
      />

      <p class="text-sm text-muted mb-2">Wklej to do terminala na maszynie agenta:</p>
      <pre class="text-xs p-3 rounded bg-elevated overflow-x-auto">{{ issued.setupCommand }}</pre>

      <template #footer>
        <div class="flex gap-2">
          <UButton icon="i-lucide-clipboard" @click="copyCommand">
            {{ copied ? 'Skopiowane' : 'Kopiuj polecenie' }}
          </UButton>
          <UButton variant="ghost" color="neutral" @click="issued = null">
            Zapisałem, zamknij
          </UButton>
        </div>
      </template>
    </UCard>

    <UCard>
      <template #header>
        <h2 class="font-medium">Wystaw nowy token</h2>
      </template>

      <form class="space-y-4" @submit.prevent="issue">
        <UFormField
          label="Etykieta"
          name="label"
          help="Po czym poznasz ten token na liście — na przykład „laptop Artura”."
        >
          <UInput v-model="label" required class="w-full" />
        </UFormField>

        <UCheckbox v-model="scopeAll" label="Wszystkie moje przestrzenie" />

        <UFormField
          v-if="!scopeAll"
          label="Zawęź do przestrzeni"
          name="scope"
          help="Zakres może tylko zawężać. Pusta lista oznacza token, który nic nie widzi."
        >
          <div class="space-y-1">
            <UCheckbox
              v-for="space in auth.spaces"
              :key="space.slug"
              :model-value="chosenSpaces.includes(space.slug)"
              :label="space.name"
              @update:model-value="
                chosenSpaces = chosenSpaces.includes(space.slug)
                  ? chosenSpaces.filter((slug) => slug !== space.slug)
                  : [...chosenSpaces, space.slug]
              "
            />
          </div>
        </UFormField>

        <UButton type="submit" :loading="issuing" :disabled="label.trim() === ''">
          Wystaw token
        </UButton>
      </form>
    </UCard>

    <UCard>
      <template #header>
        <h2 class="font-medium">Twoje tokeny</h2>
      </template>

      <p v-if="loading" class="text-sm text-muted">Wczytuję…</p>
      <p v-else-if="tokens.length === 0" class="text-sm text-muted">
        Nie masz jeszcze żadnego tokena.
      </p>

      <ul v-else class="divide-y divide-default">
        <li v-for="token in tokens" :key="token.id" class="py-3 flex items-start gap-3">
          <div class="flex-1 min-w-0">
            <p class="font-medium truncate">
              {{ token.label }}
              <UBadge v-if="!token.usable" color="neutral" variant="subtle" size="sm">
                nieaktywny
              </UBadge>
            </p>
            <p class="text-xs text-muted">
              {{ token.spaceScope === null ? 'wszystkie moje przestrzenie' : token.spaceScope.join(', ') }}
              · ostatnio użyty: {{ whenUsed(token) }}
            </p>
          </div>

          <UButton
            v-if="token.usable && confirmingRevoke !== token.id"
            color="error"
            variant="ghost"
            size="sm"
            icon="i-lucide-ban"
            @click="confirmingRevoke = token.id"
          >
            Unieważnij
          </UButton>

          <div v-else-if="token.usable" class="flex shrink-0 items-center gap-2">
            <span class="text-xs text-muted">Odciąć natychmiast?</span>
            <UButton color="error" size="xs" :loading="revoking" @click="revoke(token)">
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
        </li>
      </ul>
    </UCard>
  </div>
</template>
