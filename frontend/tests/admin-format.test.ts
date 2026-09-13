import { describe, expect, it } from 'vitest'

import { describePage, formatDateTime, formatDateTimeOr, pluralPl } from '@/features/admin/format'
import { PAGE_SIZE, pageMetaSchema, withPaging } from '@/features/admin/listing'

/**
 * The words and numbers every administration listing puts on screen.
 *
 * Small functions, but they are on every row of four screens, and two of them are easy to
 * get wrong in a way that only a Polish reader notices. The counters are the reason this
 * file exists: `22 przestrzeni` is wrong where `22 przestrzenie` is right, and an
 * administration panel that gets that wrong reads as unattended.
 */
describe('pluralPl', () => {
  it('wybiera mianownik pojedynczy tylko dla dokładnie jednego', () => {
    expect(pluralPl(1, ['wpis', 'wpisy', 'wpisów'])).toBe('wpis')
    // 21 kończy się jedynką, ale liczebnik jest mnogi — stąd nie „ostatnia cyfra to 1".
    expect(pluralPl(21, ['wpis', 'wpisy', 'wpisów'])).toBe('wpisów')
  })

  it('wybiera formę 2–4 tam, gdzie jej miejsce, i pomija nastolatki', () => {
    expect(pluralPl(2, ['wpis', 'wpisy', 'wpisów'])).toBe('wpisy')
    expect(pluralPl(4, ['wpis', 'wpisy', 'wpisów'])).toBe('wpisy')
    expect(pluralPl(22, ['wpis', 'wpisy', 'wpisów'])).toBe('wpisy')
    // Nastolatki są wyjątkiem: 12–14 idą do dopełniacza, mimo końcówki 2–4.
    expect(pluralPl(12, ['wpis', 'wpisy', 'wpisów'])).toBe('wpisów')
    expect(pluralPl(14, ['wpis', 'wpisy', 'wpisów'])).toBe('wpisów')
    expect(pluralPl(114, ['wpis', 'wpisy', 'wpisów'])).toBe('wpisów')
  })

  it('zero jest dopełniaczem', () => {
    expect(pluralPl(0, ['token', 'tokeny', 'tokenów'])).toBe('tokenów')
  })
})

describe('describePage', () => {
  const forms = ['wpis', 'wpisy', 'wpisów'] as const

  it('przy wczytanej całości mówi po prostu ile', () => {
    const meta = pageMetaSchema.parse({ count: 3, limit: 25, offset: 0, hasMore: false })

    expect(describePage(3, meta, forms)).toBe('3 wpisy')
  })

  it('przy niepełnej liście mówi ile z ilu, w dopełniaczu', () => {
    const meta = pageMetaSchema.parse({ count: 32, limit: 25, offset: 0, hasMore: true })

    // Nie „25 z 32 wpisy": po „z" idzie dopełniacz niezależnie od liczby.
    expect(describePage(25, meta, forms)).toBe('25 z 32 wpisów')
  })
})

describe('withPaging', () => {
  it('zawsze wysyła limit, a offset dopiero od drugiej strony', () => {
    expect(withPaging(new URLSearchParams(), 0).toString()).toBe(`limit=${PAGE_SIZE}`)
    expect(withPaging(new URLSearchParams(), 25).toString()).toBe(`limit=${PAGE_SIZE}&offset=25`)
  })

  it('nie gubi filtrów, które wpisał wywołujący', () => {
    const params = new URLSearchParams({ q: 'artur' })

    expect(withPaging(params, 0).get('q')).toBe('artur')
  })
})

describe('formatDateTime', () => {
  it('null zostaje nullem — „nigdy" to nie to samo co „dawno"', () => {
    expect(formatDateTime(null)).toBeNull()
    expect(formatDateTimeOr(null, 'nigdy')).toBe('nigdy')
  })

  it('nie-datę oddaje bez zmian, zamiast pisać „Invalid Date"', () => {
    // Surowa wartość jest jedyną wskazówką, co właściwie przysłał backend.
    expect(formatDateTime('wczoraj')).toBe('wczoraj')
  })

  it('datę z API zamienia na coś czytelnego', () => {
    const shown = formatDateTime('2026-09-13T11:00:00+00:00')

    expect(shown).not.toBeNull()
    expect(shown).toMatch(/2026/)
  })
})
