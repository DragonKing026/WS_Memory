import { describe, expect, it } from 'vitest'

import { isModuleLoadFailure } from '@/router'

/**
 * Telling "the page's code did not arrive" apart from every other navigation error.
 *
 * This predicate decides whether the application reloads itself. Both mistakes are
 * costly: too narrow and a person is left on a blank screen, too wide and a genuine
 * error — a failed guard, a rejected request — turns into a reload that hides it.
 *
 * The three wordings below are the real ones. There is no shared error type to match
 * on: every engine throws a plain `TypeError` and words it differently, which is why
 * the check is on text and why the text is pinned down by a test.
 */
describe('isModuleLoadFailure', () => {
  it('recognises the Chromium wording', () => {
    expect(
      isModuleLoadFailure(
        new TypeError(
          'Failed to fetch dynamically imported module: http://127.0.0.1:8080/src/pages/DocumentEditPage.vue',
        ),
      ),
    ).toBe(true)
  })

  it('recognises the Safari wording', () => {
    expect(isModuleLoadFailure(new TypeError('Importing a module script failed.'))).toBe(true)
  })

  it('recognises the Firefox wording', () => {
    expect(
      isModuleLoadFailure(new TypeError('error loading dynamically imported module')),
    ).toBe(true)
  })

  it('leaves an ordinary error alone', () => {
    // A reload here would hide the problem and change nothing about it.
    expect(isModuleLoadFailure(new Error('Nie udało się wczytać dokumentu.'))).toBe(false)
  })

  it('leaves something that is not an error alone', () => {
    expect(isModuleLoadFailure('Failed to fetch dynamically imported module')).toBe(false)
    expect(isModuleLoadFailure(null)).toBe(false)
  })
})
