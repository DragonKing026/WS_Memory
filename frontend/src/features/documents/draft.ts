/**
 * Unsent edits, kept in the browser.
 *
 * The case this exists for: somebody writes for twenty minutes, the token expires or
 * the tab closes, and the text is gone. Nothing on the server knows about it — a draft
 * is by definition what has not been sent — so the browser is the only place it can
 * survive.
 *
 * Every access is wrapped, because `localStorage` throws in a private window and comes
 * back empty after site data is cleared. A draft is a convenience; losing it must not
 * take the editor down with it.
 *
 * Restoring is never silent. The draft may be older than what is on the server, so the
 * screen asks — quietly overwriting somebody's newer text with a forgotten draft is a
 * worse failure than losing the draft.
 */

const PREFIX = 'ws-memory:szkic:'

export interface Draft {
  content: string
  changeNote: string
  /** The revision the draft was started from — an older one means somebody else wrote. */
  baseRevision: number | null
  savedAt: string
}

function key(space: string, slug: string): string {
  return `${PREFIX}${space}:${slug}`
}

export function saveDraft(space: string, slug: string, draft: Draft): void {
  try {
    localStorage.setItem(key(space, slug), JSON.stringify(draft))
  } catch {
    // Storage full, or blocked. The editor keeps working; only the safety net is gone.
  }
}

export function readDraft(space: string, slug: string): Draft | null {
  try {
    const raw = localStorage.getItem(key(space, slug))
    if (raw === null) {
      return null
    }

    const parsed: unknown = JSON.parse(raw)
    if (typeof parsed !== 'object' || parsed === null) {
      return null
    }

    const draft = parsed as Partial<Draft>
    if (typeof draft.content !== 'string' || typeof draft.savedAt !== 'string') {
      return null
    }

    return {
      content: draft.content,
      changeNote: typeof draft.changeNote === 'string' ? draft.changeNote : '',
      baseRevision: typeof draft.baseRevision === 'number' ? draft.baseRevision : null,
      savedAt: draft.savedAt,
    }
  } catch {
    // Corrupt JSON from an older version of the app. Treated as no draft rather than
    // as an error: there is nothing the reader could do about it either way.
    return null
  }
}

export function clearDraft(space: string, slug: string): void {
  try {
    localStorage.removeItem(key(space, slug))
  } catch {
    // Nothing to do; a stale draft will simply be offered once more.
  }
}
