<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

/**
 * The frame every signed-in screen sits in: header, space navigation, content.
 *
 * The space list comes from the auth store, which got it from `/api/me`. Nothing here
 * decides what somebody may see — a menu assembled locally would eventually offer a
 * space the server refuses, and a link that answers 404 is worse than no link.
 */
const auth = useAuthStore()
const router = useRouter()

const initials = computed(() => {
  const name = auth.user?.displayName ?? ''

  return (
    name
      .split(/\s+/)
      .filter((part) => part !== '')
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase() ?? '')
      .join('') || '?'
  )
})

/** Team spaces and the private one are separated: they answer different questions —
 *  "what is the team working on" and "what did I put aside". */
const teamSpaces = computed(() => auth.spaces.filter((space) => !space.isPrivate))
const privateSpace = computed(() => auth.spaces.find((space) => space.isPrivate) ?? null)

function signOut(): void {
  auth.signOut()
  void router.push({ name: 'login' })
}
</script>

<template>
  <div class="min-h-screen flex flex-col bg-default text-default">
    <header class="border-b border-default px-4 py-3 flex items-center gap-4">
      <RouterLink :to="{ name: 'home' }" class="font-semibold shrink-0">
        WS_Memory
      </RouterLink>

      <!-- Search lives in the header on every screen: looking for something is the
           reason people open this application, and burying it behind a page would put
           a click in front of the one action that matters. Wired up in TODO-007. -->
      <div class="flex-1 max-w-2xl">
        <UInput
          disabled
          icon="i-lucide-search"
          placeholder="Szukaj w bazie wiedzy — dochodzi w TODO-007"
          class="w-full"
        />
      </div>

      <!-- ml-auto, a nie samo flex-1 na wyszukiwarce: pole ma ograniczoną szerokość
           (max-w-2xl), więc na szerokim ekranie zostaje wolne miejsce, którego nikt
           nie zagospodarowuje — i cały nagłówek zbija się do lewej. -->
      <div class="ml-auto flex items-center gap-2 shrink-0">
        <UButton
          :to="{ name: 'tokens' }"
          icon="i-lucide-key-round"
          variant="ghost"
          color="neutral"
          aria-label="Tokeny agentów"
        />
        <UAvatar :alt="auth.user?.displayName ?? ''" :text="initials" size="sm" />
        <UButton variant="ghost" color="neutral" icon="i-lucide-log-out" @click="signOut">
          Wyloguj
        </UButton>
      </div>
    </header>

    <div class="flex-1 flex min-h-0">
      <nav class="w-64 border-r border-default p-3 shrink-0 overflow-y-auto">
        <p class="text-xs uppercase tracking-wide text-muted px-2 mb-1">Przestrzenie</p>

        <ul class="space-y-0.5">
          <li v-for="space in teamSpaces" :key="space.slug">
            <RouterLink
              :to="{ name: 'space', params: { space: space.slug } }"
              class="block px-2 py-1.5 rounded text-sm hover:bg-elevated"
              active-class="bg-elevated font-medium"
            >
              {{ space.name }}
              <!-- The role is shown because it changes what the screen will let you
                   do, and finding that out by being refused is a worse way to learn. -->
              <span v-if="space.role === 'reader'" class="text-xs text-muted">(czytanie)</span>
            </RouterLink>
          </li>
        </ul>

        <p v-if="teamSpaces.length === 0" class="px-2 text-sm text-muted">
          Nie należysz jeszcze do żadnej przestrzeni zespołowej.
        </p>

        <template v-if="privateSpace !== null">
          <p class="text-xs uppercase tracking-wide text-muted px-2 mt-4 mb-1">Prywatne</p>
          <RouterLink
            :to="{ name: 'space', params: { space: privateSpace.slug } }"
            class="block px-2 py-1.5 rounded text-sm hover:bg-elevated"
            active-class="bg-elevated font-medium"
          >
            Moja przestrzeń
          </RouterLink>
        </template>
      </nav>

      <main class="flex-1 min-w-0 overflow-y-auto p-6">
        <RouterView />
      </main>
    </div>
  </div>
</template>
