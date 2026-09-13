import type { DocumentListItem } from './schemas'

/**
 * Building a folder tree out of document slugs.
 *
 * A slug may carry a path — `umowy/najem`, `procedury/kadry/urlopy` — and that path
 * is the only structure the wiki has. Rendered as a flat list it is invisible, and a
 * space with two hundred documents becomes a wall of titles nobody scans.
 *
 * Kept out of the component and unit-tested, because the interesting cases are not
 * visual: a slug with no folder, two documents whose paths differ only deeper down,
 * and a folder that exists solely because something sits underneath it.
 */

export interface DocumentFolder {
  /** The last path segment — what the folder is called on screen. */
  name: string
  /** The full path, unique across the tree; used as a key and for expand state. */
  path: string
  folders: DocumentFolder[]
  documents: DocumentLeaf[]
  /** Everything below, folders included — the number worth showing next to a folder. */
  total: number
}

export interface DocumentLeaf {
  /** The last path segment; the folders above already say the rest. */
  label: string
  item: DocumentListItem
}

interface MutableFolder {
  name: string
  path: string
  folders: Map<string, MutableFolder>
  documents: DocumentLeaf[]
}

function emptyFolder(name: string, path: string): MutableFolder {
  return { name, path, folders: new Map(), documents: [] }
}

/**
 * The tree for one page of documents.
 *
 * Ordering: folders alphabetically, documents by last change, newest first. The two
 * differ on purpose — a folder is a place and places are easier to find in a fixed
 * order, while a document is an event and the useful question about events is which
 * one happened last.
 */
export function buildTree(documents: DocumentListItem[]): DocumentFolder {
  const root = emptyFolder('', '')

  for (const item of documents) {
    const segments = item.slug.split('/').filter((part) => part !== '')
    const fileName = segments.pop() ?? item.slug

    let cursor = root
    let path = ''

    for (const segment of segments) {
      path = path === '' ? segment : `${path}/${segment}`

      let next = cursor.folders.get(segment)
      if (next === undefined) {
        next = emptyFolder(segment, path)
        cursor.folders.set(segment, next)
      }

      cursor = next
    }

    cursor.documents.push({ label: fileName, item })
  }

  return freeze(root)
}

function freeze(folder: MutableFolder): DocumentFolder {
  const folders = [...folder.folders.values()]
    .map(freeze)
    .sort((a, b) => a.name.localeCompare(b.name, 'pl'))

  const documents = [...folder.documents].sort((a, b) =>
    b.item.updatedAt.localeCompare(a.item.updatedAt),
  )

  return {
    name: folder.name,
    path: folder.path,
    folders,
    documents,
    total: documents.length + folders.reduce((sum, child) => sum + child.total, 0),
  }
}
