<script setup lang="ts">
import MarkdownIt from 'markdown-it'
import { computed } from 'vue'

/**
 * Renders a document's Markdown.
 *
 * `html: false` is the security boundary and the reason this component exists rather
 * than a one-liner in the page. Content is written by people and by agents, and an
 * agent will eventually write something containing a script tag — deliberately or by
 * quoting a bug report. With HTML disabled, markdown-it escapes raw tags instead of
 * passing them through, so `v-html` here renders only markup markdown-it generated.
 *
 * Turning `html` on would need a sanitiser in front of it, and a sanitiser is a thing
 * to keep patched. Markdown is what D-009 says the content is; there is no second
 * representation to support.
 */
const props = defineProps<{ content: string }>()

const renderer = new MarkdownIt({
  html: false,
  linkify: true,
  breaks: false,
})

const rendered = computed(() => renderer.render(props.content))
</script>

<template>
  <!-- eslint-disable-next-line vue/no-v-html -- markdown-it output with html:false -->
  <div class="prose-ws" v-html="rendered" />
</template>

<style scoped>
/* Nuxt UI ships no typography defaults, and unstyled Markdown is unreadable at
   document length: headings the same size as body text, lists without indent. */
.prose-ws :deep(h1),
.prose-ws :deep(h2),
.prose-ws :deep(h3) {
  font-weight: 600;
  line-height: 1.3;
  margin: 1.5em 0 0.5em;
}
.prose-ws :deep(h1) {
  font-size: 1.5rem;
}
.prose-ws :deep(h2) {
  font-size: 1.25rem;
}
.prose-ws :deep(h3) {
  font-size: 1.1rem;
}
.prose-ws :deep(p) {
  margin: 0.75em 0;
  line-height: 1.7;
}
.prose-ws :deep(ul),
.prose-ws :deep(ol) {
  margin: 0.75em 0;
  padding-left: 1.5rem;
}
.prose-ws :deep(ul) {
  list-style: disc;
}
.prose-ws :deep(ol) {
  list-style: decimal;
}
.prose-ws :deep(li) {
  margin: 0.25em 0;
}
.prose-ws :deep(a) {
  text-decoration: underline;
}
.prose-ws :deep(code) {
  font-size: 0.875em;
  padding: 0.1em 0.3em;
  border-radius: 0.25rem;
  background: var(--ui-bg-elevated);
}
.prose-ws :deep(pre) {
  margin: 1em 0;
  padding: 0.75rem;
  border-radius: 0.375rem;
  background: var(--ui-bg-elevated);
  overflow-x: auto;
}
.prose-ws :deep(pre code) {
  padding: 0;
  background: transparent;
}
.prose-ws :deep(blockquote) {
  margin: 1em 0;
  padding-left: 1rem;
  border-left: 3px solid var(--ui-border);
  color: var(--ui-text-muted);
}
.prose-ws :deep(table) {
  margin: 1em 0;
  border-collapse: collapse;
  display: block;
  overflow-x: auto;
}
.prose-ws :deep(th),
.prose-ws :deep(td) {
  border: 1px solid var(--ui-border);
  padding: 0.4rem 0.6rem;
  text-align: left;
}
</style>
