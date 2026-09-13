<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

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
const route = useRoute()

/** The header box only navigates; the search screen owns the query, the filters and
 *  the results. Two places holding the same query would drift the moment one of them
 *  gained a filter. */
const headerQuery = ref('')

/** Only meaningful below the `md` breakpoint; above it the menu is always visible. */
const menuOpen = ref(false)

// Closing on navigation is the difference between a menu and a menu that stays in the
// way after you have used it.
watch(
  () => route.fullPath,
  () => {
    menuOpen.value = false
  },
)

function search(): void {
  const text = headerQuery.value.trim()
  if (text === '') {
    return
  }

  void router.push({ name: 'home', query: { q: text } })
  headerQuery.value = ''
}

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
    <header class="border-b border-default px-4 py-3">
      <div class="flex items-center gap-3">
        <!-- The space menu becomes a toggle on a phone: a 256px sidebar on a 390px
             screen leaves 134px for the content, which is not a narrow layout but a
             broken one. -->
        <UButton
          class="md:hidden"
          icon="i-lucide-menu"
          variant="ghost"
          color="neutral"
          aria-label="Przestrzenie"
          @click="menuOpen = !menuOpen"
        />

        <RouterLink :to="{ name: 'home' }" class="font-semibold shrink-0">
          WS_Memory
        </RouterLink>

        <!-- Search lives in the header on every screen: looking for something is the
             reason people open this application, and burying it behind a page would put
             a click in front of the one action that matters. On a phone it moves to its
             own row below, where it has room to be usable. -->
        <form class="hidden md:block flex-1 max-w-2xl" @submit.prevent="search">
          <UInput
            v-model="headerQuery"
            icon="i-lucide-search"
            placeholder="Szukaj w bazie wiedzy"
            class="w-full"
          />
        </form>

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
          <!-- Administracja to osobne miejsce, nie kolejna pozycja obok przestrzeni.
               Pasek boczny odpowiada na pytanie „gdzie jest wiedza"; utrzymanie
               instalacji odpowiada na zupełnie inne i miesza dwie role w jednym
               widoku. Stąd wejście z nagłówka i własny układ po drugiej stronie.

               Widoczne wyłącznie dla administratora globalnego: pozycja menu
               prowadząca do odmowy jest gorsza niż jej brak, bo przy każdym
               spojrzeniu przypomina, że część aplikacji jest zamknięta. -->
          <UButton
            v-if="auth.user?.isGlobalAdmin === true"
            :to="{ name: 'admin' }"
            icon="i-lucide-sliders-horizontal"
            variant="ghost"
            color="neutral"
            aria-label="Administracja"
          />
          <UAvatar :alt="auth.user?.displayName ?? ''" :text="initials" size="sm" />
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-log-out"
            aria-label="Wyloguj"
            @click="signOut"
          >
            <span class="hidden sm:inline">Wyloguj</span>
          </UButton>
        </div>
      </div>

      <form class="md:hidden mt-3" @submit.prevent="search">
        <UInput
          v-model="headerQuery"
          icon="i-lucide-search"
          placeholder="Szukaj w bazie wiedzy"
          class="w-full"
        />
      </form>
    </header>

    <div class="flex-1 flex min-h-0 relative">
      <nav
        class="border-r border-default p-3 shrink-0 overflow-y-auto w-64"
        :class="menuOpen ? 'block absolute inset-y-0 left-0 z-20 bg-default shadow-lg' : 'hidden md:block'"
      >
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

        <!-- Raw memory sits apart from the spaces, because it is not one: it cuts
             across all of them and answers a different question (what has been filed)
             than a space does (what is written down here). -->
        <p class="text-xs uppercase tracking-wide text-muted px-2 mt-4 mb-1">Pamięć</p>
        <RouterLink
          :to="{ name: 'memory' }"
          class="block px-2 py-1.5 rounded text-sm hover:bg-elevated"
          active-class="bg-elevated font-medium"
        >
          Surowa pamięć
        </RouterLink>

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

      <!-- Tapping outside the open menu closes it; without this the only way back is
           the toggle, which the menu is covering. -->
      <div
        v-if="menuOpen"
        class="md:hidden absolute inset-0 z-10 bg-black/30"
        @click="menuOpen = false"
      />

      <main class="flex-1 min-w-0 overflow-y-auto p-4 sm:p-6">
        <RouterView />
      </main>
    </div>
  </div>
</template>
