import type { PageMeta } from './listing'

/**
 * Words and dates, in one place because four screens say the same things.
 *
 * The counters are the reason this file exists rather than an inline ternary. Polish
 * has three plural forms and the boundary is not "one versus the rest": 2 spaces are
 * "przestrzenie", 5 are "przestrzeni", and 22 go back to "przestrzenie". A screen that
 * reports "22 przestrzeni" is not merely ugly — it reads as machine output, and an
 * administration panel is the last place that should look unattended.
 */

/** Singular, the 2–4 form, and the genitive plural — in that order. */
export type PluralForms = readonly [one: string, few: string, many: string]

/**
 * Picks the form Polish grammar asks for.
 *
 * The rules are the ones every Slavic pluraliser implements: the last digit decides,
 * except in the teens, where it does not.
 */
export function pluralPl(count: number, forms: PluralForms): string {
  const absolute = Math.abs(Math.trunc(count))
  const last = absolute % 10
  const lastTwo = absolute % 100

  if (absolute === 1) {
    return forms[0]
  }

  if (last >= 2 && last <= 4 && (lastTwo < 12 || lastTwo > 14)) {
    return forms[1]
  }

  return forms[2]
}

/**
 * "132 wpisy" or "25 z 132 wpisów" — how much of the answer is on screen.
 *
 * Two shapes rather than one, because with "pokaż kolejne" paging the reader's question
 * changes. While everything is loaded the count is just a count; once it is not, the
 * only useful thing to say is how much is missing. The partial form always takes the
 * genitive (`forms[2]`), which is what "z" requires regardless of the number after it.
 */
export function describePage(loaded: number, meta: PageMeta, forms: PluralForms): string {
  if (loaded >= meta.count) {
    return `${meta.count} ${pluralPl(meta.count, forms)}`
  }

  return `${loaded} z ${meta.count} ${forms[2]}`
}

/**
 * A timestamp as a person reads it, or the raw value when it is not a timestamp.
 *
 * Returning the raw string on an unparseable date is deliberate: these values come
 * from the API, and "Invalid Date" on an administration screen hides the one clue
 * (what the backend actually sent) that would explain it.
 */
export function formatDateTime(value: string | null): string | null {
  if (value === null) {
    return null
  }

  const date = new Date(value)

  return Number.isNaN(date.getTime())
    ? value
    : date.toLocaleString('pl-PL', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      })
}

/** The same, with a word instead of a null — for places where a blank cell would read
 *  as a rendering bug rather than as "this never happened". */
export function formatDateTimeOr(value: string | null, fallback: string): string {
  return formatDateTime(value) ?? fallback
}
