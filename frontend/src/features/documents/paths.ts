/**
 * Addresses of document screens, built as strings.
 *
 * Not `{ name, params }`, and that is the whole reason this file exists. A slug may
 * contain slashes (`procedury/pierwsza`), and vue-router treats a non-repeatable
 * parameter as one value: passing it through `params` produces
 * `/s/wiedza/procedury%2Fpierwsza`. The page still loads, so nothing looks broken —
 * but the address is wrong, it does not match what the tree and the search results
 * link to, and it is what somebody copies into a message.
 *
 * Found by the end-to-end test, on the first document whose slug had a folder in it.
 *
 * Each segment is encoded on its own: a space or a Polish character in a slug has to
 * survive, while the slashes have to stay slashes.
 */

function segments(slug: string): string {
  return slug
    .split('/')
    .filter((part) => part !== '')
    .map(encodeURIComponent)
    .join('/')
}

export function documentPath(space: string, slug: string): string {
  return `/s/${encodeURIComponent(space)}/${segments(slug)}`
}

export function documentEditPath(space: string, slug: string): string {
  return `${documentPath(space, slug)}/edytuj`
}

export function documentHistoryPath(space: string, slug: string): string {
  return `${documentPath(space, slug)}/historia`
}
