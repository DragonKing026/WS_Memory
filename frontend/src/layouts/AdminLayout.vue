<script setup lang="ts">
import { computed } from 'vue'

import { useAuthStore } from '@/stores/auth'

/**
 * The frame for administration screens — deliberately **not** the wiki frame.
 *
 * The two answer different questions. `AppLayout` answers "where is the knowledge":
 * spaces, raw memory, the private space. This one answers "is this installation
 * healthy and who may use it": dependencies, and in time accounts, invitations and the
 * audit log. Hanging an "Administration" group under the space list mixed the two, so
 * that a person reading a document had the machinery of the installation in the corner
 * of their eye, and an administrator looking after the installation had to navigate
 * past a list of spaces to reach it.
 *
 * Separating them also settles a question that would otherwise return with every new
 * administration screen: where does it go. Here.
 *
 * The refusal for a non-administrator is rendered rather than redirected. A redirect
 * silently teleports somebody who typed an address and leaves them guessing; a
 * sentence tells them what happened.
 */
const auth = useAuthStore()

const isAdmin = computed(() => auth.user?.isGlobalAdmin === true)

/** One place to add the next administration screen; there will be several (TODO-008). */
const sections = [{ label: 'Zależności', to: { name: 'admin-dependencies' } }] as const
</script>

<template>
  <div class="min-h-dvh flex flex-col">
    <header class="border-b border-default px-4 py-3 flex items-center gap-3">
      <!-- The way back is a first-class element, not a browser button. Administration
           is somewhere you visit and leave, not somewhere you live. -->
      <UButton
        :to="{ name: 'home' }"
        icon="i-lucide-arrow-left"
        variant="ghost"
        color="neutral"
        size="sm"
      >
        <span class="hidden sm:inline">Baza wiedzy</span>
      </UButton>

      <div class="min-w-0">
        <p class="font-semibold leading-tight">Administracja</p>
        <p class="text-xs text-muted leading-tight">Utrzymanie tej instalacji</p>
      </div>
    </header>

    <div v-if="!isAdmin" class="p-4">
      <UAlert
        color="warning"
        variant="subtle"
        icon="i-lucide-lock"
        title="Ta część jest tylko dla administratorów"
        description="Twoje konto nie ma roli administratora globalnej instalacji. Jeśli
          uważasz, że powinno ją mieć, poproś kogoś, kto ją ma."
      />
    </div>

    <div v-else class="flex-1 flex min-h-0">
      <nav class="border-r border-default p-3 shrink-0 w-56 hidden md:block overflow-y-auto">
        <ul class="space-y-0.5">
          <li v-for="section in sections" :key="section.label">
            <RouterLink
              :to="section.to"
              class="block px-2 py-1.5 rounded text-sm hover:bg-elevated"
              active-class="bg-elevated font-medium"
            >
              {{ section.label }}
            </RouterLink>
          </li>
        </ul>
      </nav>

      <!-- Below `md` the sidebar would eat most of a phone screen for a list of one or
           two items, so the sections become a row above the content instead. -->
      <div class="flex-1 min-w-0 flex flex-col">
        <nav class="md:hidden border-b border-default px-3 py-2 flex gap-2 overflow-x-auto">
          <RouterLink
            v-for="section in sections"
            :key="section.label"
            :to="section.to"
            class="px-2 py-1 rounded text-sm whitespace-nowrap hover:bg-elevated"
            active-class="bg-elevated font-medium"
          >
            {{ section.label }}
          </RouterLink>
        </nav>

        <main class="flex-1 overflow-y-auto p-4">
          <RouterView />
        </main>
      </div>
    </div>
  </div>
</template>
