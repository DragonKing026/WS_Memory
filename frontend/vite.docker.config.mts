import { defineConfig, mergeConfig } from 'vitest/config'

import { baseConfig } from './vite.config.ts'

/**
 * The overlay used inside the container.
 *
 * A separate `.mts` file rather than a condition inside the base config: outside
 * `/app` there is no `package.json` with `"type": "module"`, so a `.ts` config gets
 * loaded as CommonJS and ESM-only dependencies break on load. The extension settles
 * it regardless of where the file is read from.
 */
export default defineConfig(
  mergeConfig(baseConfig, {
    server: {
      // 0.0.0.0, or the port is unreachable from outside the container.
      host: true,
      port: 5173,
      // nginx is the only thing that talks to Vite, and it does so under the
      // project's own host name. Without this, Vite refuses the request as a
      // possible DNS-rebinding attempt and the page never loads.
      allowedHosts: true,
      // The file lives on the host through a bind mount, and inotify events do not
      // always cross that boundary. Polling is the difference between HMR working
      // and a developer wondering why nothing happens.
      watch: { usePolling: true, interval: 300 },
      ws: {
        // The browser talks to nginx, not to Vite, so the websocket has to be
        // advertised on the port the browser actually reached. This lives under
        // `ws`, not `hmr`: Vite 8 deprecated the connection options on `hmr`.
        clientPort: Number(process.env.WS_DEV_PORT ?? 8080),
      },
      // Vite's own proxy is pointless here: nginx already puts /api and the app on
      // one origin, which is what keeps CORS out of the picture entirely.
      proxy: {},
    },
  }),
)
