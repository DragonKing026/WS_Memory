<script setup lang="ts">
import type { DocumentFolder } from '@/features/documents/tree'

/**
 * One level of the document tree, rendering itself for the levels below.
 *
 * Folders start open. A tree that arrives collapsed hides exactly what somebody came
 * to see and charges a click per level for it; collapsing is for getting a big folder
 * out of the way, which is a choice the reader makes, not a default.
 */
defineProps<{
  folder: DocumentFolder
  space: string
  /** Folder paths the reader has collapsed. Held by the page, so it survives paging. */
  collapsed: Set<string>
}>()

defineEmits<{ toggle: [path: string] }>()

function formatDate(value: string): string {
  const date = new Date(value)

  return Number.isNaN(date.getTime())
    ? value
    : date.toLocaleDateString('pl-PL', { day: 'numeric', month: 'short', year: 'numeric' })
}
</script>

<template>
  <ul class="space-y-0.5">
    <li v-for="child in folder.folders" :key="child.path">
      <button
        type="button"
        class="flex w-full items-center gap-1.5 rounded px-1 py-1 text-sm hover:bg-elevated"
        @click="$emit('toggle', child.path)"
      >
        <UIcon
          :name="collapsed.has(child.path) ? 'i-lucide-chevron-right' : 'i-lucide-chevron-down'"
          class="size-4 shrink-0 text-muted"
        />
        <UIcon name="i-lucide-folder" class="size-4 shrink-0 text-muted" />
        <span class="font-medium truncate">{{ child.name }}</span>
        <span class="text-xs text-muted">{{ child.total }}</span>
      </button>

      <!-- The indent is a left border rather than padding alone: at four levels deep,
           empty space stops telling you which parent a row belongs to. -->
      <div v-if="!collapsed.has(child.path)" class="ml-2 border-l border-default pl-3">
        <DocumentTree
          :folder="child"
          :space="space"
          :collapsed="collapsed"
          @toggle="$emit('toggle', $event)"
        />
      </div>
    </li>

    <li v-for="leaf in folder.documents" :key="leaf.item.slug" class="py-1">
      <div class="flex items-start justify-between gap-3 px-1">
        <div class="min-w-0 flex items-start gap-1.5">
          <UIcon name="i-lucide-file-text" class="mt-0.5 size-4 shrink-0 text-muted" />

          <div class="min-w-0">
            <RouterLink
              :to="{ name: 'document', params: { space, slug: leaf.item.slug } }"
              class="text-sm hover:underline break-words"
            >
              {{ leaf.item.title }}
            </RouterLink>

            <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs">
              <UBadge
                v-if="leaf.item.authoredByAi"
                color="warning"
                variant="subtle"
                size="sm"
                icon="i-lucide-bot"
              >
                AI
              </UBadge>
              <UBadge
                v-if="leaf.item.verified"
                color="success"
                variant="subtle"
                size="sm"
                icon="i-lucide-badge-check"
              >
                Zweryfikowane
              </UBadge>
              <UBadge v-if="leaf.item.archived" color="error" variant="subtle" size="sm">
                Archiwum
              </UBadge>
              <UBadge
                v-if="leaf.item.status === 'draft'"
                color="neutral"
                variant="outline"
                size="sm"
              >
                Szkic
              </UBadge>
            </div>
          </div>
        </div>

        <span class="shrink-0 text-xs text-muted">{{ formatDate(leaf.item.updatedAt) }}</span>
      </div>
    </li>
  </ul>
</template>
