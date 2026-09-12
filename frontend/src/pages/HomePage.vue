<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'

/**
 * The landing screen. Search and recent changes arrive in TODO-007.
 *
 * Until then it does the one useful thing it can: say plainly what works and what does
 * not. A skeleton that pretends to be finished wastes the time of whoever opens it.
 */
const auth = useAuthStore()
</script>

<template>
  <div class="max-w-3xl space-y-6">
    <div>
      <h1 class="text-xl font-semibold">Cześć, {{ auth.user?.displayName }}.</h1>
      <p class="text-muted">
        Masz dostęp do {{ auth.spaces.length }}
        {{ auth.spaces.length === 1 ? 'przestrzeni' : 'przestrzeni' }}, z czego do
        {{ auth.writableSpaces.length }} z prawem zapisu.
      </p>
    </div>

    <UAlert
      color="info"
      variant="subtle"
      title="Frontend jest w budowie"
      description="Fundament działa: logowanie, przestrzenie, tokeny agentów. Wyszukiwanie
        dochodzi w TODO-007, przeglądanie i edytor dokumentów w TODO-008. Backend ma już
        wszystko — do tego czasu wiki jest dostępna przez API i przez agentów AI."
    />

    <UCard>
      <template #header>
        <h2 class="font-medium">Jak podłączyć agenta AI</h2>
      </template>

      <p class="text-sm text-muted mb-3">
        Wystaw token w
        <RouterLink :to="{ name: 'tokens' }" class="underline">ustawieniach</RouterLink>, a
        potem wklej wypisane polecenie do terminala. Agent zobaczy dokładnie te
        przestrzenie, które widzisz Ty — nigdy więcej.
      </p>

      <pre class="text-xs p-3 rounded bg-elevated overflow-x-auto">claude mcp add --transport http ws_memory &lt;adres&gt;/mcp \
  --header "Authorization: Bearer &lt;token&gt;"</pre>
    </UCard>
  </div>
</template>
