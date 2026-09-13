---
noteId: "664e67f0aec911f1835b4b4fc1577c80"
tags:
  - "ws-memory"
  - "project-contract"
  - "architecture"
  - "conventions"
  - "ai-agents"

---

> Translated from [`AGENTS.md`](AGENTS.md) (synced 2026-09-12).
> **The Polish version is authoritative** — it is where decisions are made and
> what settles disputes.

# AGENTS.en.md — WS_Memory

This file is the contract for every AI agent and every person working on
**WS_Memory**. Read it before your first change to the repository.

Last updated: 2026-09-12 20:15 CEST

---

## 1. What WS_Memory is

A shared knowledge base and documentation for Web Systems — **one for people
and for AI models alike**. A person signs in to the web application and writes
documentation; an AI agent reads the same knowledge over MCP and writes back to
it. There are no two sources of truth and no exports between them.

WS_Memory is **not** another vector-memory implementation. The memory engine is
[MemPalace](https://github.com/MemPalace/mempalace) 3.7.0, used as a dependency.
WS_Memory adds exactly what MemPalace lacks and the team needs:

| MemPalace provides | WS_Memory adds |
|---|---|
| A vector store, semantic + lexical search | **Identity** — who wrote it, who reads it, who approved it |
| 36 MCP tools, HTTP MCP via `mempalace serve` | **Permissions** — spaces and roles, a curated tool set |
| A miner (code, PDF/DOCX, conversation transcripts) — running **on the user's machine** | **A human interface** — a wiki with revisions, diffs and rollback |
| Hooks `session-start`, `stop`, `session-end`, `precompact` | **A company plugin** — instructions, skills, subagents, hooks with no Python |
| Knowledge graph, diary, artefacts | **Team deployment** — Docker, backups, auditing |

## 2. Inviolable rules

Breaking any of these is a critical error, not a matter of taste.

1. **An agent never receives the MemPalace token or the database DSN.** It
   receives its own token for `/mcp` in Symfony. The gateway is the only route.
2. **No MCP tool has an "author" parameter.** Identity follows from the token.
   Impersonation must be inexpressible in the API, not merely forbidden.
3. **We never query the palace without a space filter.** Every query carries
   `wing IN (spaces this token may reach)`. Filtering results *after* fetching
   is a leak, not a permission.
4. **An agent token never carries more permissions than its owner.** Fewer is
   allowed. More, never.
5. **An agent does not verify its own entries.** No `ws_doc_verify` tool exists
   or will — verification is a human act in the interface.
6. **A write with no space named lands in the token owner's private space.** An
   agent's mistake does not pollute the shared base.
7. **The embedding model is one for the whole system and does not change
   without a migration.** Changing it invalidates every vector in the database.
   Details: `docs/en/06-decisions.md`, decision D-003.
8. **Postgres, mempalace and embeddings have no host ports.** The only entrance
   from outside is nginx.
9. **The backend renders no interface, the frontend knows no database.** The
   only contract is OpenAPI. Any breach of that boundary (a template in the
   backend, an SQL query from the frontend) is an architectural error.
10. **A local palace never writes straight to the central database.** Publishing
    goes through the API, because only there do the token, roles and audit
    apply. Handing out `MEMPALACE_PGVECTOR_DSN` would void the entire permission
    layer.
11. **A local write never waits for the server.** An unreachable server must not
    interrupt or delay work — drawers wait in an outbox. The knowledge base is
    not a single point of failure for daily work.
12. **Nothing valuable lives in the plugin.** Tools, permissions and
    instructions live on the server; the plugin merely connects to them. This is
    not an aesthetic rule — it decides whether porting to another AI client
    takes hours or weeks (D-013).

## 3. Architecture in one paragraph

**Backend and frontend are separate** — a pattern carried over from Precision
Telemed 2.0. The backend is a pure Symfony API that runs independently of the
frontend and has its own contract; the frontend is a Vue 3 application on Vite,
built separately. Neither renders HTML for the other.

Seven Docker services (six already running; `frontend` is missing — TODO-006).
`nginx` terminates TLS and is the only entrance; it routes `/` to the frontend
and `/api` and `/mcp` to the backend — **the same origin, so the browser never
touches CORS**. `backend` (Symfony 8 / PHP 8.4) exposes two surfaces over the
same domain logic: REST `/api` for the frontend and the **MCP gateway** `/mcp`
for agents. `frontend` (Vue 3 + Vite) is static files in production and a
container with HMR in development. `worker` (Symfony Messenger) publishes
documents into the palace and processes publication batches. `mempalace`
(`mempalace serve`) is the only component that can search semantically and write
drawers — it **does not mine** (D-012). `embeddings` exposes `/v1/embeddings`
with a multilingual model. `postgres` (18 + pgvector) holds **everything** in two
schemas: `palace` (MemPalace tables) and `ws` (application data). A single
`pg_dump` is a complete system backup.

Full description: `docs/en/01-architecture.md`.

### How knowledge enters the system (D-010 + D-012)

**The server mines nothing.** The WS_Memory plugin requires the MemPalace plugin
as a dependency, so **every user has a local palace**. Mining of projects,
documents and conversation transcripts happens exclusively on their machine; the
server receives finished drawers, never raw sources.

An agent has two MCP servers at once: `mempalace` (local, private) and
`ws_memory` (shared). Knowledge reaches the shared base by two routes:

1. **Writing in the wiki** — a person or an agent, through `/api` or
   `ws_doc_write`.
2. **Transfer from the local palace — the default, with no user involvement**
   (D-014). Everything that reaches the local palace travels to the server. The
   landing rule: a **mapped** wing → the team space (the mapping is confirmed by
   a human once), an **unmapped** wing → **the owner's private space**. Manual
   mode (`/ws-publish`) is a switch in the plugin settings.

So everything is always on the server, yet nothing becomes visible to the team
without a mapping. The transfer **always goes through the API**, never straight
into the database — otherwise it would bypass the token, the roles and the audit
trail.

A consequence named plainly: **the text of mined code does reach the server** (as
drawers). Mining stays local, so the server needs no access to repositories or
git keys.

**The local palace is primary, the server holds a copy** (D-015). A local write
never waits for the server and never fails because of it — unsent drawers wait
in a local queue. The plugin exists so that this copy is made; anyone who wants
to work purely locally installs MemPalace alone.

## 4. Three classes of knowledge

A crucial distinction — confusing them leads to bad design decisions.

1. **Raw memory** (the bulk, automatic): session transcripts, the diary, the
   knowledge graph, repository mining. Created **in the local palace** and sent
   to the server by default — to a team space if the wing is mapped, otherwise
   to the private one. We do not version this — it is raw material.
2. **Findings and notes**: an agent writes them directly through `ws_remember`.
   Visible in the application, marked as written by AI. No gate.
3. **Canonical documentation (the wiki)**: documents with full revisions. **The
   agent writes here directly too**, through `ws_doc_write`. It gets the
   "author: AI" status and a separate "verified by a human" flag — a **trust
   marker, not a gate**. The document is immediately visible and searchable; a
   person may confirm it or roll it back to any revision.

Versioning is a safety net, **not an approval queue**. The proposal queue exists
but is an option enabled per space — only where the content genuinely warrants
it.

## 5. Stack

### Backend — runs independently of the frontend

| Layer | Technology | Note |
|---|---|---|
| Framework | Symfony 8.0, PHP 8.4 | as in the team's main Symfony application |
| API | API Platform 4.3 | `/api`, OpenAPI contract |
| Authentication | JWT (access + refresh) | stateless API; agents use a separate token |
| Database | **PostgreSQL 18 + pgvector** | a departure from the company MariaDB — D-002 |
| ORM | Doctrine ORM 3.x + Migrations | |
| Queues | Symfony Messenger | transport: Doctrine |
| Memory | MemPalace 3.7.0, pgvector backend | a dependency, a black box |
| Embeddings | `BAAI/bge-m3` via `/v1/embeddings` | 1024 dimensions, no prefixes — D-003 |
| Tests | PHPUnit | |

> Limitation: `doctrine:schema:validate` and `migrations:diff` require DBAL
> `^4.5`, while the stable release is 4.4.4. Migrations are written by hand and
> mapping is checked with `--skip-sync` (`docs/en/05-deployment.md`).

### Frontend — a separate application, the pattern from the newer Vue-frontend project

| Layer | Technology |
|---|---|
| Framework | Vue 3 (`<script setup>` + TypeScript) |
| Build | Vite 7, pnpm 10, Node 22 |
| UI | Nuxt UI 4 + Tailwind 4 |
| State | Pinia |
| Routing | vue-router 5, file-based (`unplugin-vue-router`) |
| Validation | Zod 4 |
| HTTP | Axios |
| Wiki editor | **CodeMirror 6** + Markdown preview — see D-009 |
| Tests | Vitest 4, Playwright (E2E) |

The structure of `frontend/src/` follows 2.0: `pages/` (file-based routing),
`features/<domain>/`, `components/<domain>/`, `composables/`, `stores/`,
`api/client.ts`, `utils/`, `layouts/`.

## 6. How we work in this repository

### Documentation is part of the task, not an extra

- **`docs/`** — a description of how the system works, kept current with the
  code. You change behaviour → you update the relevant file in the same task.
- **`docs/06-decyzje.md`** — every technical decision with its reasoning and the
  rejected alternatives. A new decision = a new `D-00x` number, never an edit to
  an old one (the old one is marked superseded).
- **`TODO/`** — numbered tasks. One file = one task, with the sections
  **Powód** (Reason), **Analiza** (Analysis), **Rozwiązanie** (Solution),
  **Kryteria ukończenia** (Completion criteria).
- **`TODO/DONE/`** — once finished you **move** the task file there (`git mv`)
  and add a **Co zostało zrobione** (What was done) section with the date, time
  and facts: what was built, what was tested, what was deferred and why.
- **`TODO/zrzuty/`** — screenshots from verifying tasks, named
  `NNN-short-description.png`. **Never in the repository root**: the root is the
  project's table of contents, and a file that lands there "for a moment" stays
  there for good. **Look at** a screenshot before adding it — an image containing a
  token or a password cannot be removed from a public repository's history later.
- **`CHANGELOG.md`** — an entry for every change, with date and time.
- **`docs/en/`** — an English counterpart of every file in `docs/`, updated in
  the same commit as the Polish original.

#### Definition of done — a task is NOT finished until

The rule "update the documentation" is useless until it can be checked. Hence an
explicit list, walked through **before committing**:

1. **Did system behaviour change?** → the relevant file in `docs/` **and** its
   English counterpart in `docs/en/` are updated.
2. **Did the data structure change?** → `docs/02-model-danych.md` matches the
   state of the migrations.
3. **Was a technical decision taken?** → a new `D-0xx` number in
   `docs/06-decyzje.md` with reasoning **and rejected alternatives**; old
   decisions are not edited, only marked superseded.
4. **Did configuration or deployment change?** → `docs/05-deployment.md` and
   `.env.example`.
5. **Did the API contract or the MCP tool set change?** →
   `docs/03-mcp-gateway.md`.
6. **Always** → an entry in `CHANGELOG.md` with date and time.
7. **Task finished?** → the **Co zostało zrobione** section and `git mv` into
   `TODO/DONE/`.

Mechanical check: `make sprawdz-dokumentacje` catches missing English
counterparts and mismatched decision numbers. It does not replace points 1–7,
because no script knows whether a description matches reality — but it catches
what can be caught.

**Why this is a hard rule rather than good practice:** documentation that lies
once stops being read. And this particular documentation is input for AI agents
— an out-of-date description does not merely mislead a person, it is taken as
fact by a model and propagated into further decisions.

### A branch per task — nothing goes straight to `main`

One task = one branch = one pull request = one merge. The branch is named after
the task: `todo-015-aktualizacja-mempalace`. For a change with no task, a short
descriptive slug.

`main` is guarded by a ruleset and **that protection is not bypassed**. For a
while it was: the push script pushed straight to `main` with an administrator
role, and its output said so on every commit — `Bypassed rule violations for
refs/heads/main`. A rule bypassed on every use protects nothing.

**Why a branch per task rather than long-lived layer branches** (`frontend`,
`backend`, `docs`) — because changes in this repository do not divide by layer,
and its own rules are what force that. Every change carries an entry in
`CHANGELOG.md`, and documentation ships in the same commit in two languages, so
almost every change cuts across. On top of that, `CHANGELOG.md` is appended at
the top of the file — exactly where branches living in parallel conflict, every
time. Full reasoning: D-031.

A branch should live for hours, not weeks. The longer it lives, the closer it
gets to the option we just rejected.

### Pushing — `./scripts/wypchnij.sh`, not a bare `git push`

The script walks the **whole road from branch to merge**: it runs locally the same
checks that "Szybkie sprawdzenie" runs in CI (PHP syntax, PHPUnit, PHPStan,
`lint:yaml`, frontend types and tests, the build, documentation consistency, task
write-ups, workflow and compose syntax), pushes the branch only once all of them are
green, opens a pull request, waits for **all** of its checks and merges on green. On
failure it does not merge, and prints the tail of the step that broke.

```bash
./scripts/wypchnij.sh                       # check, push, PR, wait, merge
./scripts/wypchnij.sh todo-015-aktualizacja # the same, with an explicit branch name
./scripts/wypchnij.sh --tylko-lokalnie      # check only
./scripts/wypchnij.sh --bez-czekania        # check, push, open the PR and stop
```

Standing on `main` with local commits, the script **moves them onto a task branch**
and resets `main` to `origin/main`, saying loudly what it did. That is safe, because
it only ever concerns commits that were never pushed.

Merging uses a **merge commit**, not a squash — commits made after every closed step
are meant to stay in the history, which is the whole point of making them. The script
**never uses `--admin`**: bypassing the ruleset is exactly what D-031 put an end to.

**Why this is a rule rather than a convenience:** pushing and moving on means somebody
else finds out about the red run, several commits later — by which point it is no
longer obvious which commit broke it. It happened three times in one session on
2026-09-13: once on a typo in a task's state (`zrobione` instead of `UKOŃCZONE`), twice
on transient GitHub outages. Every time it was a human who noticed, not the author of
the change.

The script also checks the **compose file**, which CI never touches — a broken compose
once reached `main` because the commands around it had their errors silenced.

### Git — we commit every closed step

A change that is not committed does not exist. The rules:

- **One commit = one closed thought.** A task from `TODO/` may produce several
  commits, but no commit joins two unrelated things.
- **A commit covers the code together with the documentation and the
  `CHANGELOG.md` entry.** We never leave documentation "for later" in a separate
  commit.
- **Message format** — Polish, imperative mood, prefixed by area:

  ```
  <area>: <what was done>

  <why, if not obvious>
  <what was tested>
  ```

  Areas: `docs`, `infra`, `backend`, `frontend`, `mcp`, `wiki`, `plugin`, `db`,
  `test`, `todo`. Example: `mcp: dodaj ws_search z twardym filtrem przestrzeni`.
- **Moving a task into `DONE/` is done with `git mv`**, so the file's history is
  preserved.
- **We commit immediately after a change is closed, not at the end of a task.**
  A finished file, a corrected configuration, a translated document, a fixed bug
  — commit right away, including small things. A task from `TODO/` usually
  yields a dozen or so commits, not one.

  The reason is practical, not aesthetic: git history should show the **course
  of the work**, not only its outcome. A single "did everything" commit cannot
  be reviewed, cannot be partially reverted and cannot be understood a month
  later. On top of that, uncommitted work is lost in any crash and collides with
  parallel sessions in the same repository.
- We do not commit: secrets, tokens, a `.env` with real data, database dumps,
  the `vendor/` directory, embedding model files.
- **We never rewrite history that has reached `origin`.** No `--amend`, `rebase`
  or `push --force` on a commit that has been pushed — not even for a typo in
  the message. Before any history change, check `git status -sb`; if `origin`
  appears there, the correction goes in a **new** commit. It has already gone
  otherwise once (2026-09-12) and the result was two versions of the same commit
  diverging between the machine and GitHub.
- **This repository has a remote and is sometimes pushed from outside this
  session.** Before changing history and before committing, glance at
  `git log --oneline -3` so as not to overwrite someone else's work.

### Before you start a task

1. Read `TODO/` — tasks have an order and dependencies.
2. Read `docs/06-decyzje.md` — do not reopen settled decisions without a new
   argument.
3. If you use MemPalace as your own session memory: search **before** answering
   about past findings. Do not guess.

### Code structure and design patterns

We build for extension, not for delivering a first version. What follows is not
decoration — each choice answers a specific change that is certainly coming.

**Layers in `backend/src/`:**

```
Domain/          business rules — NO Symfony, NO Doctrine, NO MemPalace
Application/     use cases: commands, handlers, queries
Infrastructure/  Doctrine, HTTP, MemPalace, Messenger — port implementations
Presentation/    entry points: Api/ (REST), Mcp/ (gateway), Console/
```

Dependencies point **inwards only**: `Presentation` → `Application` → `Domain`.
`Infrastructure` implements interfaces from `Domain`, never the other way round.
The test is simple: if Doctrine or MemPalace had to be replaced tomorrow, how
many files in `Domain/` would need touching? The answer must be "zero".

**Patterns we use deliberately, and why:**

| Pattern | Where | Which future change justifies it |
|---|---|---|
| **Port and adapter** | `Domain\Memory\MemoryStore` ← `Infrastructure\MemPalace\McpMemoryStore` | MemPalace is an external dependency (D-001); upgrading or replacing it must not touch the logic |
| **Decorator** | a chain around MCP tools: permissions → audit → rate limit → tool | each of these layers applies to **all** tools; written into each one separately they would drift with the first new tool |
| **Registry of tagged services** | MCP and REST tools | adding a tool must mean adding a class, not editing five places |
| **Command and handler** (Messenger) | every state-changing write | publishing and batch work must move to the background without a rewrite |
| **Strategy** | the landing rule (D-014), the secret filter, knowledge sources | rules will keep accumulating; `if`s in one method do not scale |
| **Value objects** | `SpaceId`, `Actor`, `DrawerId` | identity must not be a bare string that can be confused with another string |
| **Repository behind an interface** | `Domain\...\Repository` ← Doctrine | unit-testing permissions without a database |

**What we do not do:** build abstractions "just in case". A pattern enters when
we can name the change it serves — and the changes above are in `TODO/`, not in
our imagination.

### The plugin — instruction content exists once in the repository

Everything the plugin tells agents (the recall protocol, how to document
things, the subagent briefs) lives **only** in `plugin/shared/`. The packaging
— `plugin/skills/`, `plugin/agents/` — consists of **symlinks** to those files,
not copies. The gateway serves the same content as MCP resources.

Do not copy those files "to adapt them to a client". Diverged instructions are
worse than none: a Claude agent and a Codex agent would then say different
things about the same company rule, and nobody would know which one holds.
Details: D-013, `docs/en/04-plugin.md`.

### Language

**Everything in the code is in English** — class, method, variable, table and
column names, JSON keys, **and comments and PHPDoc**. That is the the team's main Symfony application
convention (`SendContractEndRemindersCommand`, `AuthEndpoint`) and we do not
introduce a second one. It applies to comments in code configuration files
(`services.yaml`, `doctrine.yaml`) and to test names as well.

**Documentation is bilingual.** The Polish version is **authoritative** — that
is where decisions are made and disputes settled. English counterparts live in
`docs/en/` and are updated **in the same commit** as the Polish original, like
the rest of the documentation (see the rule above).

Why Polish leads rather than the other way round: two versions always drift, and
drift must be resolvable in one sentence rather than by discussion. The team
works in Polish, so that is where the thought forms — the English version is a
translation, not a parallel source.

Outside the code, Polish remains for: `CHANGELOG.md`, tasks in `TODO/`,
user-facing messages in the interface, and commit messages.

**CI files are Polish too** — `.github/workflows/`, `.github/dependabot.yml`.
They are read like documentation, and job names appear in the GitHub interface
where the team looks at them. This is not application code but a description of
what should happen and when.

Technical exception: documentation and task files have Polish names, because
they are read, not imported.

### Order of implementation

Tests before code wherever permissions, revisions and the MCP protocol are
concerned. In those three areas a bug is silent — it shows up as a data leak or
lost content, not as an exception. A negative test ("an agent does *not* see
another space") matters more here than a positive one.

### What we do not do

- We do not pass MemPalace's 36 tools straight through to agents.
- We do not implement our own vector store or our own miner.
- We do not build two-way synchronisation between the wiki and the palace
  (rejected — D-004).
- We do not add dependencies absent from the stack table without a `D-00x`
  decision.

## 7. Repository structure (target)

```
AGENTS.md              ← the Polish original of this file
AGENTS.en.md           ← this file
CHANGELOG.md           ← changes with date and time
README.md              ← quick start
docker-compose.yml     ← seven services
docker/                ← Dockerfiles for backend, frontend, nginx + configuration
docs/                  ← how the system works
  01-architektura.md
  02-model-danych.md
  03-mcp-gateway.md
  04-plugin.md
  05-deployment.md
  06-decyzje.md
  07-frontend.md
  08-backend.md
  09-ci.md
  en/                  ← English counterparts (Polish is authoritative)
  superpowers/specs/   ← the design spec from the design phase
TODO/                  ← numbered tasks
  DONE/                ← completed tasks
backend/               ← Symfony 8: API + MCP gateway (a self-contained repo-in-repo)
  src/Domain/          ← business rules, no framework
  src/Application/     ← use cases
  src/Infrastructure/  ← Doctrine, MemPalace, HTTP
  src/Presentation/    ← Api/, Mcp/, Console/
frontend/              ← Vue 3 + Vite (a self-contained application)
plugin/                ← the WS_Memory plugin
  shared/              ← ONE source of content: protocols, agent descriptions, instructions
  .claude-plugin/      ← a thin shell: plugin.json, hooks.json, skills, agents
  .codex-plugin/       ← (only once somebody uses Codex) hooks.json + skills
```

**The plugin is a shell, not the system** (D-013). We build for Claude Code
first, but the gateway is an ordinary MCP server over HTTP — Codex, Cursor, Zed
or Antigravity will connect to it with no changes on our side. That is why
instruction content has one source in `plugin/shared/`, is also exposed as **MCP
resources**, and the hooks are one script taking the event name.

**The separation is hard.** `backend/` contains not a single template rendering
an interface, and `frontend/` knows nothing of Doctrine or MemPalace. The only
contract between them is the OpenAPI document published by API Platform. The
practical consequence: the backend can be deployed and tested without the
frontend, and the frontend replaced without touching the backend.
