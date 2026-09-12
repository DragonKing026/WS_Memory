---
tags: [ws-memory, documentation, architecture, docker, security, mempalace]
---

> Translated from [`docs/01-architektura.md`](../01-architektura.md) (synced 2026-09-12).
> **The Polish version is authoritative.**

# Architecture

Status: **the foundation works** (2026-09-12). Running: `postgres`,
`embeddings`, `mempalace`, `backend`, `worker`, `nginx`. Missing: `frontend`
(TODO-006). This document describes the target state; any divergence from the
code is a bug — in the documentation or in the code — to be fixed within the
same task.

## Separating backend and frontend

Backend and frontend are **two independent applications** (D-008, a pattern
carried over from nowszy projekt z frontendem Vue). The backend renders no page; the
frontend knows nothing about the database. The only contract is the OpenAPI
document published by API Platform.

The backend exposes **two surfaces over the same domain logic**:

| Surface | For whom | Authentication |
|---|---|---|
| REST `/api` | the Vue frontend (people) | JWT: access + refresh token |
| MCP `/mcp` | AI agents | agent token (`Authorization: Bearer`) |

Splitting the surfaces while sharing the logic is deliberate: one change to a
permission rule applies to people and agents at once, because both surfaces
call the same domain services.

## Services

Seven containers in a single `docker-compose.yml`:

| Service | Image | Role | Host port |
|---|---|---|---|
| `nginx` | `nginx:alpine` | TLS; `/` → frontend, `/api` and `/mcp` → backend | **443, 80** |
| `frontend` | prod: static `dist/` in nginx · dev: Node 22 + pnpm + Vite (HMR) | the Vue 3 interface | none |
| `backend` | custom (php-fpm 8.4) | API, wiki, accounts, permissions, MCP gateway | none |
| `worker` | same image as `backend` | Messenger: publishing to the palace, batch work | none |
| `mempalace` | custom, based on the upstream `Dockerfile` | `mempalace serve` — semantic search and writing published drawers; **does not mine** | none |
| `embeddings` | HF TEI or Infinity | `/v1/embeddings`, model `BAAI/bge-m3` | none |
| `postgres` | `pgvector/pgvector:pg18` | the palace + application data | none |

Only `nginx` is reachable from outside. The remaining services exist solely on
the Compose network — see `docs/en/06-decisions.md`, D-006.

**`/` and `/api` on one origin** is not cosmetic. The browser never issues a
cross-origin request, so there are no preflights, no CORS configuration to
maintain and none of the "works in dev, breaks in production" class of bugs. In
development nginx proxies `/` to Vite together with the HMR websocket, exactly
as in 2.0.

## Connection map

```
  browser                           AI agent (Claude Code)
       │ HTTPS                            │ HTTPS + agent token
       └──────────────┬───────────────────┘
                      ▼
                  [ nginx ]  ← TLS, the only entrance, one origin
                   /      \
              /  │           \ /api  ·  /mcp
             ▼                 ▼
      [ frontend ]          [ backend ]  Symfony 8 — API + MCP gateway
      Vue 3 + Vite              │
      (dist or HMR)             │
                    ┌───────────┼───────────┬─────────────┐
                    ▼           ▼           ▼             │
             [ postgres ]  [ mempalace ]  [ worker ]       │
              schemas:          │            │            │
              ws · palace       ▼            └────────────┘
                          [ embeddings ]   (Messenger: publishing,
                                ▲            processing batches)
                                └── mempalace computes vectors here
```

`mempalace` and `worker` reach `postgres` directly: the first as the palace's
pgvector backend, the second through Doctrine. Same database, different
schemas. `frontend` connects to nothing but nginx — it knows the backend only
as the `/api` path on its own origin.

## Who is responsible for what

**`backend` (Symfony 8)** — the only component holding business logic. Owns
identity, permissions and the wiki. Exposes REST `/api` and the MCP gateway
`/mcp` over shared domain services. No interface templates whatsoever.

**`frontend` (Vue 3 + Vite)** — the entire human interface: search, browsing
spaces, a Markdown editor with preview, revision comparison, administration.
Talks only to `/api`. Conventions: `docs/en/07-frontend.md`.

**`mempalace`** — the only component that can search semantically and write
drawers in palace format. **It does not mine** — mining happens exclusively on
users' machines (D-012). It has no notion of users or permissions; it receives
queries with the `wing` filter already applied. Treated as a black box behind
the HTTP MCP boundary so that upgrading it never touches our code.

**`worker`** — everything that must not block an HTTP response: pushing a
published document into the palace, processing publication batches from local
palaces, consistency jobs.

**`embeddings`** — computes vectors. Separated so the whole system shares one
model (D-003) and laptops never need to download it.

**`postgres`** — the only stateful component. A `pg_dump` of the whole database
is a complete system backup: wiki, revisions, accounts, permissions, audit
**and the palace**.

## Flows

### A. A person reads the knowledge base

1. The browser loads the frontend from `/`, then calls `/api/...` with a JWT.
2. `backend` determines which spaces the user may reach.
3. Search: `backend` → `mempalace` (`POST /mcp`, `mempalace_search`) with a
   **hard `wing` filter** restricted to those spaces.
4. Listing and metadata: `backend` reads SQL from the `ws` and `palace` schemas
   — without MemPalace, because no vectors need computing.

### B. A person writes a document

1. The frontend posts content to `/api/documents/{id}/revisions`; `backend`
   stores a new revision in `ws.document_revisions` inside a transaction.
2. After publication `backend` dispatches a job to `worker`.
3. `worker` pushes the content into the palace (`mempalace_add_drawer`) in the
   document's space and records the link in `ws.memory_entries`.
4. From that moment agents find the document semantically.

### C. An agent reads and writes

1. Claude Code → `nginx` → `backend` `/mcp`, header `Authorization: Bearer`.
2. `backend` resolves the token to its owner and their permissions; a token
   **never** grants more than its owner has.
3. `tools/call` reaches one of the `ws_*` tools (`docs/en/03-mcp-gateway.md`),
   which calls **the same domain services as REST** — one set of permission
   rules, not two.
4. Writing: `backend` calls `mempalace` and records an entry in
   `ws.memory_entries` with the author taken from the token. Every call lands in
   `ws.audit_log`.

### D. Automatic session transcript mining — **local**

1. MemPalace hooks on the user's machine mine the transcript **into the local
   palace** after every session. The conversation never leaves the laptop.
2. Knowledge worth sharing reaches the common base only through publication
   (flow E) — manually or by a mirror.

The server accepts no transcripts and mines nothing (D-012). There is no
endpoint to secure here, because there is no such path.

### E. Transfer from a local palace to the server — **the default** (D-010 + D-014)

The only route by which knowledge enters the shared base apart from writing in
the wiki:

1. The user has a local MemPalace — the WS_Memory plugin requires the MemPalace
   plugin as a dependency, so **everyone** has one. Their own `mempalace init`
   and `mempalace mine` on their own projects. **Code never leaves the laptop.**
2. The agent has **two MCP servers**: `mempalace` (local, private) and
   `ws_memory` (shared). A skill imposes the search order: shared base first,
   local second.
3. Publishing to the shared base goes **through the API**, not through the
   database: the plugin reads local drawers (`mempalace_list_drawers` +
   `mempalace_get_drawer`) and sends their content to `POST /api/publish`.
4. `backend` checks permissions, runs the content through the secret filter,
   recomputes embeddings with **its own** model and stores it with an author and
   the pair `(source_replica, source_drawer_id)` — a repeat publication updates
   rather than duplicates.
5. **This happens unasked.** By default (`auto_publish = true`) every new drawer
   in the local palace travels to the server after a session ends or after local
   mining. The landing rule:
   - a **mapped** wing (`ws.mirrors`) → the team space, visible to others;
     mapping requires a one-off human confirmation;
   - an **unmapped** wing → the user's **private space** on the server.
6. Anyone who prefers to decide for themselves switches `auto_publish` off in
   the plugin settings and returns to manual `/ws-publish`.

**The direction is one-way and the local palace is primary** (D-015): a local
write never waits for the server. When the server is unreachable, drawers wait
in a local outbox and catch up at the next opportunity — working offline is
fully supported, and a server outage blocks nobody. Re-sending is safe thanks to
deduplication by the source pair and by `content_hash`.

So everything is always on the server — backup, search, access from a second
machine — yet nothing becomes visible to the team without a mapping. The
consequence named plainly in D-014: **the text of mined code does reach the
server** (as drawers). Mining stays local, so the server still needs no access
to repositories.

Why through the API rather than straight into the database: writing to Postgres
would bypass the token, the roles and the audit trail — the entire layer
WS_Memory exists for. That variant was deliberately rejected in D-010.

## Security boundary

Four layers, from the outside in:

1. **`nginx`** — TLS, rate limits, request size.
2. **Authentication** — JWT (people) or an agent token (machines).
3. **Authorisation** — role within a space; the `wing` filter injected by
   `backend`, never taken from call parameters. Shared by both surfaces, so it
   cannot be dodged by choosing an entry point.
4. **Storage isolation** — sensitive spaces may get their own **pgvector
   namespace**, meaning separate tables rather than a filter in a query.

What an agent does not have and never will: the MemPalace token, the Postgres
DSN, access to `/v1/embeddings`, or any way to name someone else's space.

## Scale and limits

The design targets a team of **a dozen or so people** and a base of **hundreds
of thousands of drawers** (the author's local palace holds 97k for a single
user). At that scale the bottleneck is vector computation, not Postgres —
which is why `embeddings` is a separate service that can move to a GPU machine
without changing anything else.

Mining is asynchronous and idempotent by nature (MemPalace coordinates it by
timestamps), so `worker` can be scaled out.
