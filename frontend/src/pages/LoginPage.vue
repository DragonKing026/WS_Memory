<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

/**
 * Signing in. There is no registration form, and there never will be: an account comes
 * into existence only from an invitation, because whoever holds one can read whatever
 * their spaces contain.
 */
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const email = ref('')
const password = ref('')

async function submit(): Promise<void> {
  try {
    await auth.signIn(email.value, password.value)
  } catch {
    // The store has the message; the form shows it. Nothing more to do here.
    return
  }

  // Back where the person was when the session ended, if we know. `powrot` comes from
  // the guard; the store's own copy covers a session lost mid-request.
  const wanted = typeof route.query.powrot === 'string' ? route.query.powrot : auth.takeReturnTo()

  await router.replace(wanted ?? { name: 'home' })
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center p-4 bg-muted">
    <UCard class="w-full max-w-sm">
      <template #header>
        <h1 class="font-semibold">WS_Memory</h1>
        <p class="text-sm text-muted">Wspólna baza wiedzy zespołu.</p>
      </template>

      <form class="space-y-4" @submit.prevent="submit">
        <UFormField label="Adres e-mail" name="email">
          <UInput
            v-model="email"
            type="email"
            autocomplete="username"
            required
            autofocus
            class="w-full"
          />
        </UFormField>

        <UFormField label="Hasło" name="password">
          <UInput
            v-model="password"
            type="password"
            autocomplete="current-password"
            required
            class="w-full"
          />
        </UFormField>

        <UAlert
          v-if="auth.problem !== null"
          color="error"
          variant="subtle"
          :description="auth.problem"
        />

        <UButton type="submit" :loading="auth.loading" block> Zaloguj </UButton>
      </form>

      <template #footer>
        <p class="text-xs text-muted">
          Konta zakłada się z linku zaproszenia. Jeśli go nie masz, poproś
          administratora.
        </p>
      </template>
    </UCard>
  </div>
</template>
