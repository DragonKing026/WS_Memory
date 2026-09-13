<script setup lang="ts">
import { markdown } from '@codemirror/lang-markdown'
import { languages } from '@codemirror/language-data'
import { EditorState } from '@codemirror/state'
import { EditorView, keymap, placeholder as cmPlaceholder } from '@codemirror/view'
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

/**
 * The Markdown editor: CodeMirror 6, not a WYSIWYG (D-009).
 *
 * The reason is in the decision, and it is not aesthetic: the same field is written by
 * people and by agents, and every round trip through a WYSIWYG document model loses
 * whatever that model does not understand. Here the text a person types is byte for
 * byte the text that goes to the API — which is also why the preview beside it is
 * trustworthy.
 *
 * The editor is created once and fed changes through a transaction. Recreating it on
 * every keystroke would throw away the undo history, the cursor and the scroll
 * position — the three things a writer notices immediately.
 */
const props = defineProps<{
  modelValue: string
  placeholder?: string
  disabled?: boolean
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const host = ref<HTMLElement | null>(null)
let view: EditorView | null = null

onMounted(() => {
  if (host.value === null) {
    return
  }

  view = new EditorView({
    parent: host.value,
    state: EditorState.create({
      doc: props.modelValue,
      extensions: [
        history(),
        keymap.of([...defaultKeymap, ...historyKeymap]),
        // `codeLanguages` lets a fenced block be highlighted as its own language;
        // without it a code sample in a document is one grey lump.
        markdown({ codeLanguages: languages }),
        EditorView.lineWrapping,
        cmPlaceholder(props.placeholder ?? ''),
        EditorState.readOnly.of(props.disabled === true),
        EditorView.updateListener.of((update) => {
          if (update.docChanged) {
            emit('update:modelValue', update.state.doc.toString())
          }
        }),
        EditorView.theme({
          '&': { fontSize: '0.9rem', height: '100%' },
          '.cm-content': { fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace' },
          '.cm-focused': { outline: 'none' },
          '.cm-scroller': { overflow: 'auto' },
        }),
      ],
    }),
  })
})

// Only for changes that came from elsewhere — restoring a draft, loading a document.
// Without the guard, echoing our own emit back would move the cursor to the end on
// every keystroke.
watch(
  () => props.modelValue,
  (value) => {
    if (view !== null && value !== view.state.doc.toString()) {
      view.dispatch({
        changes: { from: 0, to: view.state.doc.length, insert: value },
      })
    }
  },
)

onBeforeUnmount(() => {
  view?.destroy()
  view = null
})
</script>

<template>
  <div ref="host" class="h-full overflow-hidden rounded border border-default bg-default" />
</template>
