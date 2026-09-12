<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

/**
 * A space: its documents and what agents have been writing. Both arrive in TODO-007.
 *
 * What it already does is the part that has to be right from the start — refusing to
 * show a space the signed-in person has no membership in. The check is against the
 * store's list, which came from the server; nothing here decides permissions.
 */
const route = useRoute()
const auth = useAuthStore()

const slug = computed(() => (typeof route.params.space === 'string' ? route.params.space : ''))
const space = computed(() => auth.spaceBySlug(slug.value))
</script>

<template>
  <div class="max-w-3xl space-y-4">
    <template v-if="space !== undefined">
      <div>
        <h1 class="text-xl font-semibold">{{ space.name }}</h1>
        <p class="text-sm text-muted">
          Twoja rola: {{ space.role ?? 'brak' }}.
          <template v-if="space.requiresProposal">
            Ta przestrzeń wymaga przeglądu propozycji przed publikacją.
          </template>
        </p>
      </div>

      <UAlert
        color="info"
        variant="subtle"
        title="Lista dokumentów dochodzi w TODO-007"
        description="Backend już je zwraca pod /api/spaces/{slug}/documents — brakuje tylko
          ekranu."
      />
    </template>

    <!-- Not "you have no access": the same message as for a space that does not exist,
         because saying which it is would confirm that the space is there. -->
    <UAlert
      v-else
      color="warning"
      variant="subtle"
      title="Nie ma takiej przestrzeni"
      description="Sprawdź adres albo poproś o dostęp administratora."
    />
  </div>
</template>
