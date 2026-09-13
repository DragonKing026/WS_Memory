import { expect, test, type APIRequestContext, type Page } from '@playwright/test'

/**
 * The journey a person actually performs: sign in, write, edit, compare, roll back.
 *
 * Everything here goes through the browser and the running stack. The one exception is
 * the fixture — the account and the space are created through the API, because
 * creating them by clicking would test the invitation screen inside a test about
 * documents, and a failure there would point at the wrong place.
 *
 * The rollback assertion is the one that matters most: it checks that the revision
 * count **grew**. A rollback that quietly shortened history would still leave the
 * document looking right, and nobody would notice until they needed the history.
 */

const HASLO = 'DlugieHaslo123!x'
const PRZESTRZEN = `e2e-${Date.now()}`
const EMAIL = `e2e-${Date.now()}@web-systems.pl`
const SLUG = 'procedury/pierwsza'

let token = ''

/** Creates the account and the space, and returns a signed-in API context. */
async function przygotujKonto(request: APIRequestContext): Promise<void> {
  // The invitation is issued by a console command, so the test asks the backend
  // container for one. Everything after that is the public API.
  const { execSync } = await import('node:child_process')
  const wyjscie = execSync(
    `docker compose exec -T backend php bin/console ws:user:invite ${EMAIL} --admin`,
    { encoding: 'utf-8', cwd: '..' },
  )

  const link = /zaproszenie\/([a-f0-9]{64})/.exec(wyjscie)
  expect(link, 'polecenie nie wypisało linku zaproszenia').not.toBeNull()

  const przyjete = await request.post('/api/invitations/accept', {
    data: { token: link?.[1], displayName: 'Tester E2E', password: HASLO },
  })
  expect(przyjete.ok(), await przyjete.text()).toBeTruthy()

  const zalogowano = await request.post('/api/login', {
    data: { email: EMAIL, password: HASLO },
  })
  expect(zalogowano.ok(), await zalogowano.text()).toBeTruthy()
  token = ((await zalogowano.json()) as { token: string }).token

  const przestrzen = await request.post('/api/spaces', {
    headers: { Authorization: `Bearer ${token}` },
    data: { slug: PRZESTRZEN, name: 'Przestrzeń E2E', description: 'Tworzona przez test' },
  })
  expect(przestrzen.ok(), await przestrzen.text()).toBeTruthy()
}

async function zaloguj(page: Page): Promise<void> {
  await page.goto('/login')
  await page.getByLabel('Adres e-mail').fill(EMAIL)
  await page.getByLabel('Hasło').fill(HASLO)
  await page.getByRole('button', { name: 'Zaloguj' }).click()
  await expect(page).toHaveURL(/\/$|\/\?/)
}

test.beforeAll(async ({ playwright }) => {
  const request = await playwright.request.newContext({
    baseURL: process.env.WS_E2E_URL ?? 'http://127.0.0.1:8080',
  })
  await przygotujKonto(request)
  await request.dispose()
})

test('logowanie, utworzenie, edycja, porównanie i cofnięcie', async ({ page }) => {
  await zaloguj(page)

  await test.step('utworzenie dokumentu', async () => {
    await page.goto(`/s/${PRZESTRZEN}/${SLUG}/edytuj`)

    await page.getByLabel('Tytuł').fill('Procedura pierwsza')
    await page.locator('.cm-content').fill('Wersja pierwsza. Zgłoszenie idzie na dyżur.')
    await page.getByLabel('Opis zmiany').fill('pierwsza wersja procedury')

    await page.getByRole('button', { name: 'Zapisz' }).click()

    await expect(page).toHaveURL(new RegExp(`/s/${PRZESTRZEN}/${SLUG}$`))
    await expect(page.getByRole('heading', { name: 'Procedura pierwsza' })).toBeVisible()
  })

  await test.step('opis zmiany jest wymagany', async () => {
    await page.goto(`/s/${PRZESTRZEN}/${SLUG}/edytuj`)
    await page.locator('.cm-content').fill('Wersja druga, ale bez opisu zmiany.')

    // Content changed, note empty: saving must be impossible, not merely discouraged.
    await expect(page.getByRole('button', { name: 'Zapisz' })).toBeDisabled()
  })

  await test.step('edycja tworzy drugą rewizję', async () => {
    await page.getByLabel('Opis zmiany').fill('doprecyzowanie ścieżki zgłoszenia')
    await page.getByRole('button', { name: 'Zapisz' }).click()

    await expect(page).toHaveURL(new RegExp(`/s/${PRZESTRZEN}/${SLUG}$`))
    await expect(page.getByText('rewizja 2')).toBeVisible()
  })

  await test.step('porównanie rewizji 1 i 2 pokazuje zmianę', async () => {
    await page.goto(`/s/${PRZESTRZEN}/${SLUG}/historia?od=1&do=2`)

    await expect(page.getByRole('heading', { name: 'Rewizja 1 → 2' })).toBeVisible()
    await expect(page.getByText('Wersja pierwsza. Zgłoszenie idzie na dyżur.')).toBeVisible()
    await expect(page.getByText('Wersja druga, ale bez opisu zmiany.')).toBeVisible()
  })

  await test.step('cofnięcie dopisuje rewizję, nie usuwa żadnej', async () => {
    await page.goto(`/s/${PRZESTRZEN}/${SLUG}/historia`)

    // `count()` nie czeka na nic — bez tego liczylibyśmy pustą listę, zanim
    // historia się wczyta, i test przechodziłby przez porównanie zera z zerem.
    const wiersze = page.locator('[data-test="rewizja"]')
    await expect(wiersze.first()).toBeVisible()
    const przedCofnieciem = await wiersze.count()

    await page.getByRole('button', { name: 'Cofnij do tej wersji' }).first().click()
    await page.getByRole('button', { name: 'Tak, cofnij' }).click()

    await expect(page).toHaveURL(new RegExp(`/s/${PRZESTRZEN}/${SLUG}$`))
    await expect(page.getByText('rewizja 3')).toBeVisible()

    await page.goto(`/s/${PRZESTRZEN}/${SLUG}/historia`)
    await expect(wiersze.first()).toBeVisible()
    const poCofnieciu = await wiersze.count()

    // The assertion that matters: history grew. A rollback that shortened it would
    // leave the document looking correct and the history quietly wrong.
    expect(poCofnieciu).toBe(przedCofnieciem + 1)
  })
})

test('wyszukiwanie znajduje świeżo zapisany dokument', async ({ page }) => {
  await zaloguj(page)

  // Lexical mode, not semantic: a document is searchable the moment it is saved, while
  // the palace copy is filed by a worker a moment later. Asserting on the semantic
  // mode here would be asserting on a race.
  await page.goto('/?q=dyżur&tryb=lexical')

  await expect(page.getByRole('link', { name: 'Procedura pierwsza' })).toBeVisible()
})
