import { defineConfig, devices } from '@playwright/test'

/**
 * End-to-end tests, run against a **running stack**, not a mock.
 *
 * That is the point of them: the unit tests already cover the pieces, and the API
 * tests already cover the backend. What nothing else covers is whether logging in,
 * writing a document, comparing revisions and rolling back actually work together
 * through nginx, Vite, the API and the database — which is the only sequence a person
 * ever performs.
 *
 * No `webServer` here on purpose: the stack is brought up by `docker compose` (in the
 * nightly workflow, or by hand), and having Playwright start its own would test a
 * different arrangement than the one that ships.
 */
export default defineConfig({
  testDir: './e2e',
  // The suite is a handful of long journeys, not hundreds of small cases; running
  // them one at a time keeps the shared database predictable.
  workers: 1,
  fullyParallel: false,
  // A retry hides a flaky test, and a flaky end-to-end test is usually reporting a
  // real race. Locally: none. In CI: one, because a cold container can genuinely be
  // slow on the first request and that is not a defect worth a red nightly.
  retries: process.env.CI === 'true' ? 1 : 0,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: process.env.CI === 'true' ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: process.env.WS_E2E_URL ?? 'http://127.0.0.1:8080',
    // Kept only for a failing run: a trace for every green test is gigabytes nobody
    // opens, and the one from the failure is the only one worth having.
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    locale: 'pl-PL',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
