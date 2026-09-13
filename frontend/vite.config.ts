import { fileURLToPath, URL } from 'node:url'

import ui from '@nuxt/ui/vite'
import vue from '@vitejs/plugin-vue'
// From vitest/config, not vite: it is the same defineConfig with the `test` key typed.
// Importing it from 'vite' compiles until somebody adds a test option, and then fails
// with a message about an unknown property rather than about the real cause.
import { defineConfig, type ViteUserConfig } from 'vitest/config'

/**
 * Base Vite configuration — the one used when running on a developer's own machine.
 *
 * The Docker overlay lives in `vite.docker.config.mts` and extends this. It is a
 * separate file, and a `.mts` one, for a reason worth knowing: outside `/app` there
 * is no `package.json` declaring `"type": "module"`, so a plain `.ts` config is
 * loaded as CommonJS and every ESM-only dependency then fails at load.
 */
/**
 * Exported as a plain object as well, so the Docker overlay can merge into it.
 * `mergeConfig` needs a value, and `defineConfig`'s return type is a union of every
 * shape a config may take — which merges into `never`.
 */
export const baseConfig: ViteUserConfig = {
  plugins: [
    vue(),
    // Nuxt UI brings Tailwind 4 with it, so there is no separate Tailwind plugin
    // here. Two of them would fight over the same CSS layer.
    ui(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  // Dependencies reachable only from a lazily loaded page have to be named here.
  //
  // Vite pre-bundles dependencies once at start-up and stamps the result with a hash
  // that goes into every import URL it serves. A dependency it did not include in
  // that pass is discovered the moment somebody first opens the page importing it —
  // and re-optimising changes the hash, so the requests already in flight for the old
  // one are answered `504 Outdated Optimize Dep`. The dynamic import rejects and the
  // page renders **nothing**.
  //
  // Found in CI, where the nightly end-to-end run went red on a blank editor screen.
  // The trace named the casualties exactly: five `@codemirror/*` packages and
  // `markdown-it` — precisely the set no screen but the editor imports. It does not
  // reproduce on a developer's machine, where the start-up scan wins the race, which
  // is what makes it worth pinning down here rather than leaving to chance.
  //
  // `router.onError` recovers if this happens anyway; see `src/router/index.ts`.
  optimizeDeps: {
    include: [
      '@codemirror/commands',
      '@codemirror/lang-markdown',
      '@codemirror/language-data',
      '@codemirror/state',
      '@codemirror/view',
      'markdown-it',
    ],
  },
  server: {
    port: 5173,
    // Proxy for running Vite directly, without nginx in front. In Docker nginx
    // serves both from one origin and this is never used — but a developer who
    // runs `pnpm dev` alone should not meet CORS as their first experience.
    proxy: {
      '/api': { target: 'http://127.0.0.1:8080', changeOrigin: true },
      '/mcp': { target: 'http://127.0.0.1:8080', changeOrigin: true },
    },
  },
  test: {
    environment: 'happy-dom',
    // Wąsko i celowo: `e2e/` to testy Playwrighta, uruchamiane przez `test:e2e`
    // przeciwko działającemu stosowi. Domyślny wzorzec Vitesta złapałby je jako
    // testy jednostkowe i wywrócił się na braku przeglądarki.
    include: ['tests/**/*.test.ts'],
    globals: false,
  },
}

export default defineConfig(baseConfig)
