<script setup lang="ts">
import { computed } from 'vue'

import { documentPath } from '@/features/documents/paths'
import { kindLabels, type SearchHit, type SearchMode } from '@/features/search/schemas'

/**
 * One search result.
 *
 * Everything on this card is here because a reader needs it to judge the hit before
 * opening it: which space it came from, how well it matched, who wrote it, and whether
 * a person has checked it. In a base half-written by agents, the last two are not
 * decoration — they decide how much of it to believe.
 *
 * The snippet is rendered as text nodes from the parts the API sent. Never `v-html`:
 * the content is Markdown written by people and agents, so it can contain anything,
 * and a marked-up string from the database rendered as HTML is stored XSS.
 */
// The mode belongs to the whole answer, not to a single hit — it comes in as a prop
// rather than being guessed from the score, which would be guessing.
const props = defineProps<{ hit: SearchHit; mode: SearchMode }>()

/** A document has a page; raw memory does not, and a link that goes nowhere is worse
 *  than no link. */
const target = computed(() =>
  props.hit.documentSlug === null ? null : documentPath(props.hit.space, props.hit.documentSlug),
)

/**
 * Relevance as a percentage — for semantic results only.
 *
 * Cosine similarity runs 0..1 and reads naturally as a percentage. `ts_rank` does not:
 * an exact match on a short document scores around 0.14, and rendering that as "14%"
 * tells the reader their perfect hit is a poor one. Lexical results are exact by
 * construction — the score only orders them — so no number is shown at all, which is
 * the honest amount of information rather than a misleading one.
 */
const relevance = computed(() =>
  props.mode === 'semantic' && props.hit.score !== null
    ? `${Math.round(props.hit.score * 100)}%`
    : null,
)

const filedAt = computed(() => {
  if (props.hit.at === null) {
    return null
  }

  const date = new Date(props.hit.at)

  return Number.isNaN(date.getTime())
    ? null
    : date.toLocaleDateString('pl-PL', { day: 'numeric', month: 'long', year: 'numeric' })
})
</script>

<template>
  <article class="py-4 border-b border-default last:border-b-0">
    <div class="flex items-start gap-3">
      <div class="min-w-0 flex-1">
        <h3 class="font-medium truncate">
          <RouterLink v-if="target" :to="target" class="hover:underline">
            {{ hit.title }}
          </RouterLink>
          <span v-else>{{ hit.title }}</span>
        </h3>

        <p class="mt-1 text-sm text-muted break-words">
          <template v-for="(part, index) in hit.snippet" :key="index">
            <mark v-if="part.match" class="bg-primary/20 text-default rounded px-0.5">{{
              part.text
            }}</mark>
            <template v-else>{{ part.text }}</template>
          </template>
        </p>

        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
          <UBadge color="neutral" variant="subtle">{{ hit.space }}</UBadge>
          <UBadge color="neutral" variant="outline">{{ kindLabels[hit.kind] }}</UBadge>

          <!-- Who wrote it. Stated for both cases rather than only flagging the AI:
               "written by a person" is information too, and a badge that appears only
               sometimes gets read as "unknown" the rest of the time. -->
          <UBadge v-if="hit.byAi" color="warning" variant="subtle" icon="i-lucide-bot">
            Napisane przez AI
          </UBadge>
          <UBadge v-else color="neutral" variant="subtle" icon="i-lucide-user">
            Napisane przez człowieka
          </UBadge>

          <UBadge
            v-if="hit.verified"
            color="success"
            variant="subtle"
            icon="i-lucide-badge-check"
          >
            Zweryfikowane
          </UBadge>

          <span v-if="filedAt" class="text-muted">{{ filedAt }}</span>
        </div>
      </div>

      <div v-if="relevance" class="shrink-0 text-right">
        <div class="text-sm tabular-nums" :class="hit.weak ? 'text-muted' : 'font-medium'">
          {{ relevance }}
        </div>
        <div class="text-xs text-muted">trafność</div>
      </div>
    </div>
  </article>
</template>
