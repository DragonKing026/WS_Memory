<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { authService } from '@/features/auth/service'
import { useAuthStore } from '@/stores/auth'

/**
 * Turning an invitation into an account.
 *
 * The password rules are shown **before** the field is filled in, not as an error
 * afterwards: a person told "at least twelve characters" after their third attempt has
 * already been annoyed twice for nothing. The backend enforces the same rules — this
 * is a courtesy, not the check.
 */
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const token = computed(() => (typeof route.params.token === 'string' ? route.params.token : ''))

const displayName = ref('')
const password = ref('')
const repeated = ref('')
const problem = ref<string | null>(null)
const working = ref(false)

// `false`, nie `undefined`, w atrybutach :error poniżej — pole Nuxt UI przyjmuje
// `string | boolean`, a przy exactOptionalPropertyTypes przekazanie undefined jest
// błędem typu. To nie formalność: `undefined` w propie oznacza „nie przekazano",
// a tutaj przekazujemy świadomie „brak błędu".
const tooShort = computed(() => password.value.length > 0 && password.value.length < 12)
const mismatched = computed(() => repeated.value.length > 0 && repeated.value !== password.value)
const ready = computed(
  () =>
    displayName.value.trim() !== '' &&
    password.value.length >= 12 &&
    repeated.value === password.value,
)

async function submit(): Promise<void> {
  problem.value = null
  working.value = true

  try {
    const email = await authService.acceptInvitation(token.value, displayName.value, password.value)

    // Signed in straight away with the password just chosen: it is the only moment the
    // person can find out whether they typed what they meant.
    await auth.signIn(email, password.value)
    await router.replace({ name: 'home' })
  } catch (cause) {
    problem.value = cause instanceof Error ? cause.message : 'Nie udało się utworzyć konta.'
  } finally {
    working.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center p-4 bg-muted">
    <UCard class="w-full max-w-sm">
      <template #header>
        <h1 class="font-semibold">Zaproszenie do WS_Memory</h1>
        <p class="text-sm text-muted">Ustaw hasło, żeby dokończyć zakładanie konta.</p>
      </template>

      <form class="space-y-4" @submit.prevent="submit">
        <UFormField label="Imię i nazwisko" name="displayName">
          <UInput v-model="displayName" required autofocus class="w-full" />
        </UFormField>

        <UFormField
          label="Hasło"
          name="password"
          help="Co najmniej 12 znaków."
          :error="tooShort ? 'Hasło jest za krótkie.' : false"
        >
          <UInput v-model="password" type="password" autocomplete="new-password" required class="w-full" />
        </UFormField>

        <UFormField
          label="Powtórz hasło"
          name="repeated"
          :error="mismatched ? 'Hasła się różnią.' : false"
        >
          <UInput v-model="repeated" type="password" autocomplete="new-password" required class="w-full" />
        </UFormField>

        <UAlert v-if="problem !== null" color="error" variant="subtle" :description="problem" />

        <UButton type="submit" :loading="working" :disabled="!ready" block>
          Utwórz konto
        </UButton>
      </form>
    </UCard>
  </div>
</template>
