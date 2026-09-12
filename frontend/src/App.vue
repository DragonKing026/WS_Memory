<script setup lang="ts">
/**
 * The shell. Nothing but the router outlet and Nuxt UI's providers.
 *
 * Layouts are routes (see the router), not a condition in here: an `App.vue` that
 * chooses a layout by inspecting the current path is where that decision gets
 * duplicated the first time somebody adds a page.
 */
</script>

<template>
  <UApp>
    <RouterView />
  </UApp>
</template>
