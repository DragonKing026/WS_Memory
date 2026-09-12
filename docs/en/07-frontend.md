---
tags: [ws-memory, documentation, frontend, vue, vite, ui]
---

> Translated from [`docs/07-frontend.md`](../07-frontend.md) (synced 2026-09-12).
> **The Polish version is authoritative.**

# Frontend

Status: **the foundation works** (2026-09-12, `TODO-006`). Signing in, accepting an
invitation, the layout with spaces, agent tokens. Missing: search (`TODO-007`) and
document browsing and the editor (`TODO-008`).

A separate Vue 3 application, independent of the backend (D-008). Stack and
structure carried over from the newer Vue-frontend project so the team does not have to
learn a second set of conventions.

## Stack

Vue 3 (`<script setup>` + TypeScript) · Vite 7 · Nuxt UI 4 · Tailwind 4 ·
Pinia · vue-router 5 with file-based routing (`unplugin-vue-router`) · Zod 4 ·
Axios · Vitest 4 · pnpm 10 · Node 22 (`.nvmrc`).

Specific to WS_Memory: **CodeMirror 6** as the Markdown editor (D-009) and
`markdown-it` for the preview.

## Structure of `frontend/src/`

| Directory | Purpose |
|---|---|
| `pages/` | page components; addresses are assigned by `router/index.ts` (D-027) |
| `features/<domain>/` | domain logic: services, types, stores of that domain |
| `components/<domain>/` | presentational components |
| `composables/` | reusable composables (`useXxx.ts`) |
| `stores/` | Pinia stores shared across domains |
| `api/client.ts` | HTTP client (Axios) — the only place that knows about `/api` |
| `utils/` | pure helper functions |
| `layouts/` | layouts |

WS_Memory domains: `auth`, `spaces`, `documents`, `search`, `memory`, `admin`.

## Screens

| Path | What it does |
|---|---|
| `/login` | ✅ sign-in; registration only from an invitation link |
| `/zaproszenie/:token` | ✅ setting a password from an invitation link |
| `/` | search across all reachable knowledge + recent changes |
| `/s/:space` | a space: document tree, recent agent writes |
| `/s/:space/:slug` | a document: content, author, verification mark, history |
| `/s/:space/:slug/edit` | the editor: CodeMirror with a preview beside it |
| `/s/:space/:slug/history` | revision list, comparison of any two, rollback |
| `/s/:space/proposals` | the proposal queue (only where a space requires it) |
| `/memory` | raw memory: drawers, diary, knowledge graph — with filters |
| `/settings/tokens` | ✅ agent tokens: issuing, scope, revocation |
| `/admin/*` | users, invitations, spaces, roles, audit |

## Rules

**One HTTP client.** `api/client.ts` is the only place that knows the `/api` path and
attaches the token. A component never calls Axios directly.

**There is no token refresh** (D-017) — and that changes how expiry is handled. A 401
ends the session: the client clears the token, remembers **where** the user was, and
redirects to sign-in, returning there afterwards. A test asserts outright that a 401 is
**not retried** — without a refresh that would be a second identical 401, and somebody
will otherwise add the retry "just in case".

The token lives in `localStorage`, with the risk named plainly in D-028 and in
`SECURITY.md`. Every access to storage is wrapped in `try/catch`, because in a private
window and after site data is cleared the read either throws or returns nothing — and
the application is then meant to work rather than break.

**Zod at the boundary.** Every API response passes through a Zod schema before
entering a store. When the backend changes the contract, a frontend test fails
immediately instead of producing `undefined` three screens later.

**Document content is Markdown and only Markdown.** There is no second
representation (D-009). The preview renders exactly the text that will be sent
to the API.

**The author is always visible.** Every piece of content — document, revision,
drawer — shows whether a person or an AI wrote it, and whether a person
verified it. This is not decoration: it determines how the reader should treat
the content.

**Search shows source and relevance.** A result that does not say which space
it came from and how strongly it matches is useless once the base holds
hundreds of thousands of drawers.

## Dev and build

Development: a Node 22 + pnpm + Vite container, with nginx proxying `/` to
`frontend:5173` including the HMR websocket, and `/api` and `/mcp` to the backend.
Editing files on the host shows up in the browser immediately, and the browser never
touches CORS because everything sits on one origin.

Three things without which HMR does not work through Docker and nginx — each documented
in `vite.docker.config.mts`:

- `host: true` — without it the port is unreachable from outside the container;
- `allowedHosts: true` — Vite refuses a request under the project's host name as a
  possible DNS-rebinding attempt;
- `watch.usePolling` — inotify events do not always cross a host bind mount, and
  `hmr.clientPort` must name **nginx's** port, because the browser talks to nginx and
  not to Vite.

The overlay is a separate `.mts` file, and that is not incidental either: outside `/app`
there is no `package.json` with `"type": "module"`, so a `.ts` config loads as CommonJS
and breaks ESM-only packages.

Production: `pnpm build` → static `dist/` served by nginx. The production image
**contains no node** — verified, not assumed. Both modes listen on **the same port
5173**, so the main nginx has one upstream regardless of mode.

`pnpm-lock.yaml` is committed and the build uses `--frozen-lockfile` with no fallback to
a plain `install`: a build that quietly resolves dependencies differently from the
lockfile is no longer reproducible — which is the only reason to keep the file.

## Dependency overrides

`package.json` carries a single `pnpm.overrides` entry: **`esbuild: ^0.28.2`**.

Why: esbuild below 0.28.1 allows arbitrary file reads when the development server runs
on Windows (GHSA-g7r4-m6w7-qqqr). Vite 8 accepts `^0.27.0 || ^0.28.0`, but
`fontless@0.2.1` — pulled in by `@nuxt/ui` → `@nuxt/fonts` — declares `^0.27.0` and held
the whole tree at 0.27.7.

**Overriding a range a dependency declared is a risk and needs proof, not an
assumption.** This one was checked four ways: typecheck, tests, `pnpm build`, and a
from-scratch production image build with `--frozen-lockfile`. Any bump to this entry
means repeating all four.

The entry is **meant to disappear** once `@nuxt/fonts` raises `fontless` above `^0.2.1`:
`fontless` 0.3+ no longer depends on esbuild at all. An override that is no longer needed
is a frozen version nobody remembers.
