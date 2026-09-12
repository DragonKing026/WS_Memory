<script setup lang="ts">
/**
 * A page that is not there. Deliberately says nothing about what might be.
 */
</script>

<template>
  <div class="min-h-screen flex items-center justify-center p-4">
    <div class="text-center space-y-3">
      <h1 class="text-2xl font-semibold">Nie ma takiej strony</h1>
      <p class="text-muted">Sprawdź adres albo wróć do bazy wiedzy.</p>
      <UButton :to="{ name: 'home' }">Wróć</UButton>
    </div>
  </div>
</template>
