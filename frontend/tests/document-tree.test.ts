import { describe, expect, it } from 'vitest'

import type { DocumentListItem } from '@/features/documents/schemas'
import { buildTree } from '@/features/documents/tree'

/**
 * Turning slugs into folders.
 *
 * The cases worth guarding are the ones that are invisible on screen until they are
 * wrong: a document with no folder at all, two documents sharing only part of a path,
 * and a folder that exists solely because something sits underneath it.
 */

function doc(slug: string, overrides: Partial<DocumentListItem> = {}): DocumentListItem {
  return {
    slug,
    title: slug,
    space: 'wiedza',
    status: 'published',
    currentRevision: 1,
    authoredByAi: false,
    verified: false,
    verifiedBy: null,
    verifiedAt: null,
    archived: false,
    updatedAt: '2026-09-01T10:00:00+00:00',
    ...overrides,
  }
}

describe('buildTree', () => {
  it('kładzie dokument bez ukośnika prosto w korzeniu', () => {
    const tree = buildTree([doc('urlopy')])

    expect(tree.folders).toHaveLength(0)
    expect(tree.documents.map((leaf) => leaf.label)).toEqual(['urlopy'])
  })

  it('zakłada folder dla ścieżki w adresie', () => {
    const tree = buildTree([doc('umowy/najem')])

    expect(tree.documents).toHaveLength(0)
    expect(tree.folders.map((f) => f.name)).toEqual(['umowy'])
    expect(tree.folders[0]?.documents.map((leaf) => leaf.label)).toEqual(['najem'])
  })

  it('scala dokumenty o wspólnym początku ścieżki w jeden folder', () => {
    const tree = buildTree([doc('umowy/najem'), doc('umowy/serwis')])

    expect(tree.folders).toHaveLength(1)
    expect(tree.folders[0]?.documents).toHaveLength(2)
  })

  it('rozdziela ścieżki, które różnią się dopiero głębiej', () => {
    const tree = buildTree([doc('procedury/kadry/urlopy'), doc('procedury/it/dostepy')])

    const procedury = tree.folders[0]
    expect(procedury?.name).toBe('procedury')
    expect(procedury?.folders.map((f) => f.name)).toEqual(['it', 'kadry'])
  })

  /** The number next to a folder must count everything below it, not just its own row. */
  it('liczy w folderze wszystko, co pod nim leży', () => {
    const tree = buildTree([
      doc('procedury/kadry/urlopy'),
      doc('procedury/kadry/delegacje'),
      doc('procedury/it/dostepy'),
      doc('procedury/wstep'),
    ])

    expect(tree.folders[0]?.total).toBe(4)
    expect(tree.total).toBe(4)
  })

  it('porządkuje foldery alfabetycznie, a dokumenty od najnowszej zmiany', () => {
    const tree = buildTree([
      doc('zzz/a'),
      doc('aaa/b'),
      doc('stary', { updatedAt: '2026-01-01T10:00:00+00:00' }),
      doc('nowy', { updatedAt: '2026-09-10T10:00:00+00:00' }),
    ])

    expect(tree.folders.map((f) => f.name)).toEqual(['aaa', 'zzz'])
    expect(tree.documents.map((leaf) => leaf.label)).toEqual(['nowy', 'stary'])
  })

  it('nie wywraca się na pustej liście', () => {
    const tree = buildTree([])

    expect(tree.total).toBe(0)
    expect(tree.folders).toHaveLength(0)
    expect(tree.documents).toHaveLength(0)
  })

  /** A trailing or doubled slash is a typo, not a nameless folder. */
  it('pomija puste odcinki ścieżki', () => {
    const tree = buildTree([doc('umowy//najem')])

    expect(tree.folders.map((f) => f.name)).toEqual(['umowy'])
    expect(tree.folders[0]?.documents.map((leaf) => leaf.label)).toEqual(['najem'])
  })

  it('zachowuje pełny adres dokumentu, żeby odsyłacz prowadził we właściwe miejsce', () => {
    const tree = buildTree([doc('procedury/kadry/urlopy')])

    const leaf = tree.folders[0]?.folders[0]?.documents[0]
    expect(leaf?.label).toBe('urlopy')
    expect(leaf?.item.slug).toBe('procedury/kadry/urlopy')
  })
})
