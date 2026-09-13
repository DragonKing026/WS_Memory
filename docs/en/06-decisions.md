---
tags: [ws-memory, decisions, adr, architecture, rationale]
---

> Translated from [`docs/06-decyzje.md`](../06-decyzje.md) (synced 2026-09-13).
> **The Polish version is authoritative.** Decision numbers are shared between
> both versions and are the common point of reference.

# Technical decisions

Every decision carries a number, a date, a status and its reasoning together
with the **rejected alternatives**. Decisions are never edited — a superseded
one is marked `Superseded by D-00x` and a new one is added.

Status: `Accepted` · `Superseded` · `Rejected`

---

## D-001 — Symfony 8 as the application, MemPalace as a sidecar

**Date:** 2026-09-12 · **Status:** Accepted
· **Presentation layer changed by D-008**

Symfony 8 / PHP 8.4 handles sign-in, the wiki, permissions and the MCP gateway.
MemPalace runs in a separate container and is queried over HTTP MCP as a black
box.

> The decision originally covered a Twig interface as well. **D-008 changes
> that**: Symfony is a pure API and the interface is a separate Vue
> application. The rest of the decision (Symfony as the backend, MemPalace as a
> sidecar) still holds.

**Why:** the Web Systems team maintains code in Symfony (the team's main Symfony application: Symfony 8,
PHP 8.4, Doctrine, Twig). Code written in a technology the team does not use
daily rots faster than it grows. Treating MemPalace as a black box behind an
HTTP boundary means upgrading it never touches our code.

**Rejected:**
- *A Python gateway + a Symfony web app* — two languages and two repositories
  to maintain in exchange for access to MemPalace internals we do not need.
- *Everything in Python* — fastest to a first version, but maintenance would
  fall on a technology outside the team's competence.
- *Symfony API + a React SPA* — better editor UX, but a third deployment
  component; can be added later if the wiki editor demands it.

---

## D-002 — PostgreSQL 18 + pgvector instead of MariaDB

**Date:** 2026-09-12 · **Status:** Accepted

One PostgreSQL 18 database with the pgvector extension. Two schemas: `palace`
(MemPalace tables) and `ws` (application data).

**Why** — a deliberate departure from the company default of MariaDB, for four
concrete reasons:

1. **Polish full-text search.** Postgres has `tsvector` with Hunspell
   dictionaries, i.e. stemming: "umowy", "umowa", "umowie" all resolve to the
   same term. MariaDB `FULLTEXT` has no Polish stemming and amounts to prefix
   matching. In a knowledge base written in Polish that is the difference
   between a search that works and one that does not.
2. **pgvector.** MemPalace supports pgvector as a full backend, so the palace
   and the application data live in one database: a single `pg_dump` is a
   complete backup of the whole system rather than two independent restore
   mechanisms.
3. **`JSONB` with GIN indexes** — drawer metadata and ACLs queried in SQL
   instead of pulling everything into PHP.
4. **Transactional DDL.** A Doctrine migration that fails halfway rolls back
   entirely. In MariaDB you are left with a half-migrated schema.

For Symfony code this is a one-line change in `DATABASE_URL` — Doctrine
supports both engines equally.

**Cost:** the team's operational experience is with MariaDB; backup syntax and
diagnostic tooling differ. Judged acceptable.

**Rejected:** *a separate store for the palace (Chroma on a volume)* — two
systems to back up and no way to run SQL queries spanning the wiki and the
palace.

---

## D-003 — A central embedding server, model `BAAI/bge-m3`

**Date:** 2026-09-12 · **Status:** Accepted

A separate container exposes an OpenAI-compatible `/v1/embeddings`. MemPalace
uses it through `MEMPALACE_EMBEDDING_MODEL=openai-compat` +
`MEMPALACE_EMBEDDING_API_URL`. Model: `BAAI/bge-m3` (1024 dimensions).

**Why:**

- **The default `minilm` is trained on English only.** For a base written in
  Polish it would silently degrade relevance — the system would work, it would
  just find the wrong things.
- **One model for everyone.** If each machine computed vectors locally, a
  version difference between laptops would pollute the vector space with no
  error message whatsoever.
- **`bge-m3` rather than the `e5` family**: E5 models need `query:` /
  `passage:` prefixes in the text to reach their stated quality. MemPalace does
  not add them, so quality would silently drop. `bge-m3` has no such
  requirement and is strong in Polish.
- No fragment of company knowledge leaves our infrastructure.

**Operational consequence:** changing the model invalidates **every** vector in
the database and requires recomputing it from scratch. Hence the decision was
made before the first write, not after.

> **Amendment after D-010:** this decision applies to the **server only**.
> Developers' local palaces may use any model, because the hybrid shares
> **text, not vectors** — the server recomputes every published drawer with its
> own model. The requirement of one model across the team is gone.

> **Amendment after the measurements of 2026-09-12 (TODO-000):** the model
> requires **tuned buffers and a hard memory limit**. With TEI's defaults
> (`--max-batch-tokens 16384`, as many tokenisation workers as cores),
> `bge-m3` took **21 GB** and choked the development machine. With
> `--max-batch-tokens 2048`, `--tokenization-workers 2` and `--auto-truncate`
> it takes **2.2 GB** — those 19 GB were pure reservation. Every service now
> has a `mem_limit`: a container without one takes the machine's entire memory,
> so a configuration mistake turns into an outage of the whole computer.
>
> **Measured alternatives** (three Polish sentence pairs; margin = the gap
> between a matching pair and the control pair):
>
> | Model | Window | Memory | Response | Margin |
> |---|---|---|---|---|
> | `bge-m3` (chosen) | 8192 | 2174 MB | 291 ms | 0.130 |
> | `paraphrase-multilingual-MiniLM` | **128** | 1113 MB | 21 ms | 0.281 |
> | `multilingual-e5-base` | 512 | 1961 MB | 72 ms | 0.055 |
> | `multilingual-e5-small` | 512 | 1204 MB | 24 ms | 0.034 |
>
> The **E5 family scored worst** and confirmed this decision's original
> argument: since MemPalace does not distinguish a query from a document, both
> must receive the same prefix (`--default-prompt "query: "`), and then
> similarities compress into a band around 0.8 — the control pair scores 0.804,
> exactly as much as a matching one.
>
> **MiniLM has the best margin but a 128-token window** — every drawer would be
> truncated after ~90 words, silently and without error. That is the same class
> of fault the semantics test protects against, so it was rejected.
>
> The fallback, should 291 ms become a bottleneck: `multilingual-e5-base`.

**Rejected:**
- *`embeddinggemma` locally on every machine* — 300 MB of model per laptop and
  the risk of version drift.
- *A paid API (OpenAI / Voyage)* — the highest quality, but all company
  knowledge leaves for an external provider, and mining repositories means a
  large call volume.

---

## D-004 — Postgres as the source of truth for the wiki, the palace as the search layer

**Date:** 2026-09-12 · **Status:** Accepted

Wiki documents live in the `ws` schema with full revisions. After publication
the content is additionally pushed into the palace as a drawer so agents find it
semantically. Agent memories (transcripts, diary, knowledge graph) live natively
in the palace; `ws.memory_entries` holds their metadata for permission filtering
and auditing. The flow is **one-way**.

**Why:** versioning, diffs and rollback are a relational database's job. A
vector store has no notion of transactions or revision history. Metadata in `ws`
lets permissions be enforced **inside the SQL query** rather than by filtering
results after they come back from the palace.

**Rejected:**
- *The palace as the source of truth for everything* — "versions" as drawers
  marked superseded clutter semantic search with stale content, and permissions
  become an after-the-fact filter, which is a leak.
- *Two-way synchronisation through a worker* — functionally the richest, but it
  introduces edit-conflict resolution, synchronisation loops and dependence on
  event ordering. A class of bugs that is hard to diagnose and out of proportion
  to the benefit.

---

## D-005 — Agents write without a gate; versioning is the safety net

**Date:** 2026-09-12 · **Status:** Accepted

An AI agent writes freely in all three classes of knowledge, canonical
documentation included. An AI-authored entry receives the `authored_by_ai`
status and a separate `verified_by` flag — **a trust marker, not a condition of
publication**. The proposal queue exists as an option enabled per space.

**Why:** AI will produce most of the writes — MemPalace hooks mine conversation
transcripts automatically and the miner processes repositories. An approval gate
before every write would turn the system into a bottleneck and invite people to
route around it. The protection is the ability to roll back (full revisions),
not blocking the write. The exception is sensitive spaces, where the queue is
enabled deliberately.

**The restriction that remains:** an agent does not verify its own entries. No
`ws_doc_verify` tool exists in the API — verification is a human act in the
interface.

---

## D-006 — A closed network; hooks send transcripts over HTTPS

**Date:** 2026-09-12 · **Status:** Accepted

`postgres`, `mempalace` and `embeddings` have no host ports. The only entrance
from outside is `nginx` (TLS): `/` for people, `/mcp` for agents. Hooks on
developer machines **do not write to the database** — they send session
transcripts incrementally over HTTPS, and mining happens server-side.

**Why:** the variant in which hooks write directly through
`MEMPALACE_PGVECTOR_DSN` would require exposing Postgres to the internet. A
database holding all company knowledge on a public port is a bad trade for
convenience.

**A significant side benefit:** a developer needs no MemPalace installation, no
Python and no embedding model. The plugin and a token suffice.

> **Amendment after D-010:** a developer with a local palace mines transcripts
> locally and publishes selected ones.
>
> **Changed by D-012:** raw transcripts are **never** sent to the server at all.
> Everyone has a local palace (the plugin requires it as a dependency), so
> conversations are mined locally only. The rest of the decision — a closed
> network with a single nginx entrance — stands unchanged.

**Rejected:** *Postgres access over VPN/WireGuard* — could be added later if a
need arises to mine repositories locally without sending them to the server. It
is not required for the system to work.

---

## D-007 — A curated set of MCP tools

**Date:** 2026-09-12 · **Status:** Accepted

The gateway exposes a dozen or so company tools (`ws_*`) to agents, not
MemPalace's 36 tools passed straight through.

**Why:** the tool boundary **is** the permission boundary. A MemPalace tool
accepting an arbitrary `wing` would let an agent read a space its owner has no
right to. On top of that, none of our tools has an "author" parameter — identity
follows from the token, so impersonation is inexpressible in the API rather than
merely forbidden by policy.

---

## D-008 — Separating backend and frontend

**Date:** 2026-09-12 15:58 · **Status:** Accepted
· **Changes the presentation layer from D-001**

The backend is a **pure API** in Symfony 8 (API Platform 4.3) under `/api`, with
no templates rendering an interface. The frontend is a **separate application**
in Vue 3 on Vite 7, built independently. The only contract between them is
OpenAPI. `nginx` routes `/` to the frontend and `/api` and `/mcp` to the
backend — the same origin, so the browser never touches CORS.

The pattern is carried over from **the newer Vue-frontend project**, where this split has
already proven itself: separate application directories, nginx as a same-origin
proxy, Vite with HMR behind nginx in development.

**Why:**

- **The backend must run independently.** The MCP gateway for agents and REST
  for people are two surfaces over the same domain logic. Were the backend to
  render an interface, that logic would start leaking into templates and agents
  and people would drift into different behaviour.
- **A knowledge base interface is inherently interactive** — live search,
  revision comparison, an editor with preview, a space tree. Rendering that
  server-side in Twig would mean writing the same thing twice: once in HTML,
  once in JS.
- **The team is competent on both sides** — Symfony (the team's main Symfony application) and Vue 3
  (the newer Vue-frontend project). We introduce no new technology, only use two already
  in use.
- **The frontend can be replaced without touching the backend**, and the backend
  tested without the frontend. The OpenAPI contract is documentation and a test
  anchor at once.

**Cost:** one more Compose service, token authentication (JWT) instead of a
simpler session, and two dependency sets to keep updated. Judged acceptable —
exactly the cost 2.0 already bears.

**Rejected:** *Twig in a monolith* — quicker to start, but the knowledge base
interface would grow into JS anyway, only without structure.

---

## D-009 — Wiki editor: CodeMirror 6, not WYSIWYG

**Date:** 2026-09-12 15:58 · **Status:** Accepted

Wiki documents are edited in **CodeMirror 6** as Markdown, with a preview beside
it. We do not use a WYSIWYG editor.

**Why:** the same content field is written by people and by AI agents. An agent
produces Markdown and nothing but Markdown. A WYSIWYG editor would have to
convert Markdown into its own document model on every open and back again on
every save — and each such round trip loses whatever the model does not support:
tables with unusual alignment, code blocks with a language name, footnotes, HTML
comments. For a document circulating between a person and an AI that loss
accumulates silently.

Markdown as the **only** representation removes this class of fault entirely:
what the agent wrote is exactly what the person sees and edits.

**Rejected:** *TipTap* — used in the newer Vue-frontend project and good in its role
(editorial content written by people only), but here its document model would
become a second representation of the truth.

---

## D-010 — The hybrid: a local palace plus publishing to the shared base

**Date:** 2026-09-12 16:38 · **Status:** Accepted
· **Default behaviour changed by D-014**

A developer may keep **their own local MemPalace** (their own `init`, `mine` and
hooks) and at the same time publish selected knowledge to the shared base
through the WS_Memory API. The agent has **two MCP servers**: `mempalace`
(local, private) and `ws_memory` (shared). Publishing works in two modes:
**selective** (`/ws-publish` with a filter and a preview) and **mirroring** — a
chosen wing of the local palace is published periodically into the matching
space.

**What was established in the MemPalace 3.7.0 source before deciding:**

- **There is no palace-to-palace replication.** `logstream sync` synchronises
  coordination events and artefacts (RFC 004) — `logsync.py` does not mention
  drawers once. `mempalace sync` is cleanup after deleted files, not
  replication. `replica.json` and `patch_submit` are groundwork for a future
  mesh and for handing over code patches.
- **The bridge can be built from what exists**: `mempalace_list_drawers`
  (pagination, wing/room filter, date range) plus `mempalace_get_drawer` (full
  content) on the local stdio server.
- **`replica.json` provides a stable machine identifier** — described in the
  source as naming "the seat", i.e. this copy of the palace. Exactly what is
  needed to recognise where a drawer came from.

**Why this rather than "everything on the server":**

1. **Code never leaves the laptop.** Mining happens locally; only the text of
   selected drawers travels to the server.
2. **Permissions, attribution and auditing stay intact**, because publishing
   goes through the API — we expose no Postgres and hand out no DSN. Rules 1–3
   hold without exception.
3. **Privacy is the default.** Conversations and working notes stay local until
   somebody deliberately directs them to the shared base.
4. **The requirement of one embedding model on laptops disappears** (amendment
   to D-003).
5. **The user runs `init` and `mine` themselves** — locally, with no application
   in between and no waiting for an administrator.

**What the hybrid does not give:** a single query spanning both indexes. The
local palace and the shared base are two stores, so the agent asks twice — the
`ws-memory-recall` skill imposes the order: shared first, local second. Merging
them server-side would require sending everything there, i.e. giving up the
privacy that is the main benefit here.

> **Change after D-014:** publishing is no longer something to remember —
> **by default everything that reaches the local palace is sent to the
> server**. Manual mode became a switch in the plugin settings. The mirror and
> batch mechanism described below still stands; what changes is what happens
> with no configuration at all.

**Mirroring safeguards** — a mirror, once set up, runs unattended, so the risk
of sending something unforeseen is handled explicitly:

- **The first run of every mirror is a preview**: it shows what would go and
  requires confirmation. A mirror never starts on its own.
- **Room exclusions** in the mirror definition (e.g. a project wing without the
  `diary` room).
- **A secret filter on both sides** — a drawer containing secret patterns
  (`.env`, private keys, passwords in URLs) is rejected with a report.
- **A publication batch log** with one-action undo for a whole batch.
- **A global and a per-mirror off switch**, plus pause.
- **Incrementality** — a mirror sends only drawers newer than the last
  watermark.

**Rejected:** *a local mempalace with `MEMPALACE_PGVECTOR_DSN` pointing at the
central database*. It would be the simplest to configure and would give a single
index, but writing straight to the database **bypasses the entire permission
layer** — agent tokens, space roles and the audit trail would stop meaning
anything. That would invalidate the reason WS_Memory exists.

---

## D-011 — Polish entity detection, `init` without an LLM

**Date:** 2026-09-12 16:38 · **Status:** Accepted

We set `MEMPALACE_ENTITY_LANGUAGES=pl,en`. On the server, `mempalace init` runs
with `--no-llm`.

**Why:** entity detection defaults to **English** (`--lang`, default `en`). This
is the same kind of silent fault as the default `minilm` from D-003, only it
affects the knowledge graph and the links between drawers — names, roles and
relations in Polish text would be recognised worse, with no error message.

`init` wants an LLM (ollama) by default to refine entities. We have no ollama in
the stack, and adding one means another container and several GB of RAM for a
feature that only **refines** heuristics. We start without it; if detection
quality proves too weak, that is a separate decision with its own number.

---

## D-012 — One route for knowledge: mining happens locally only

**Date:** 2026-09-12 17:08 · **Status:** Accepted
· **Changes D-006, cancels TODO-010**

**The server mines nothing.** The WS_Memory plugin declares the MemPalace
plugin as a **dependency**, so every user has a local palace. Mining — of
projects, documents and conversation transcripts — happens exclusively on the
user's machine. Only what somebody publishes reaches the shared base (D-010).

**What confirms this in Claude Code's mechanics** (checked in the
documentation, not assumed):

- `plugin.json` has a **`dependencies`** field: `["mempalace"]`, optionally with
  a semver constraint. A marketplace entry has the equivalent `requires`.
- The marketplace supports **`source: {"type": "command"}`** — a command run
  before installation, i.e. the place to install the `mempalace` package and run
  the first `mempalace init`.
- **`userConfig`** lets us ask the user for the URL and token when the plugin is
  enabled (`sensitive: true`), with the values available as `${user_config.KEY}`
  in the MCP configuration and `CLAUDE_PLUGIN_OPTION_*` in hooks. It replaces
  manually set environment variables.

**Why one route instead of two:**

1. **Two routes mean twice the code and twice the places to get it wrong** — for
   an identical end result.
2. **Code and conversations never leave the laptop.** There is no longer any
   path by which raw sources reach the server — nothing to secure, because it
   does not exist.
3. **The server no longer needs access to repositories.** Git keys, source
   configuration and scheduling all disappear, together with the class of bugs
   called "the nightly mining job froze search".
4. **Mining loads the machine of whoever requested it**, so there is no need for
   quotas, queues or protection against overloading a shared server.
5. The question "who may request mining" **ceases to exist** — everyone, on their
   own machine.

**What leaves the project:** `TODO-010` in its entirety, the `mining_jobs` and
`session_uploads` tables, the transcript volume, the `extract` variant of the
server image, the transcript-receiving endpoint and the hook that sent them.

**What stays on the server:** the `mempalace` container (semantic search and
writing published drawers) and `embeddings` (vectors for queries and for
published content). Both remain indispensable — they do not mine, they serve the
shared base.

**Known limitation:** someone without Claude Code has no way to bring a PDF or
DOCX into the base — writing in the wiki is all they have. Workaround: a person
with a local palace mines a documents directory (`mempalace mine ~/documents
--mode extract`, requires the `mempalace[extract]` variant) and publishes the
result. Judged acceptable for the first version; restoring the server-side route
would be a separate decision.

---

## D-013 — Portability across AI clients: value in the server, thin shells

**Date:** 2026-09-12 17:30 · **Status:** Accepted

We build **for Claude Code first** (the office is moving to Claude), but in such
a way that porting to Codex, Cursor or another MCP client is a matter of
rewriting manifests rather than rewriting the system. Three rules:

1. **All the value lives on the server.** The `ws_*` tools, permissions, tokens,
   audit, wiki — none of it is in the plugin.
2. **Instruction content has one source** (`plugin/shared/`) and is additionally
   exposed as **MCP resources** and in tool descriptions.
3. **Non-portable parts are kept minimal**: one hook script taking the event as
   an argument instead of a script per event.

**What is already portable with no work:** the gateway is an MCP server over
HTTP. `codex mcp add --transport http`, Cursor's `mcp.json`, Zed, Antigravity,
Copilot in VS Code — all of them will connect. A user of another client gets
**identical security guarantees**, because the permission model is not in the
plugin.

**What is not portable:** hooks, skills, subagents, commands — the packaging.

**What was established by looking at how MemPalace did it** (four packagings in
one repository):

- `.claude-plugin/` — commands, hooks (`hooks.json` + scripts), skills;
- `.codex-plugin/` — **the same `hooks.json` shape** (SessionStart / Stop /
  PreCompact), only with the `${CODEX_PLUGIN_ROOT}` variable, and **one** script
  taking the event name as an argument;
- `.cursor-plugin/` — just `mcp.json`, no hooks (Cursor uses rules);
- `.antigravity-plugin/` — `hooks.json.tmpl`, `mcp_config.json`, rules, skills;
- `integrations/shared/` — shared protocol content, independent of the client.

In other words: the differences come down to manifests and variable names, while
the content is shared. We copy that layout.

**Why MCP resources rather than skills alone:** resources are read by every MCP
client (this harness has `ListMcpResources` / `ReadMcpResource` for exactly
that), and tool descriptions arrive by definition. An instruction moved from a
plugin file into a server resource gains one more thing: **changing an
instruction becomes a server deployment rather than a plugin update on every
individual machine.**

> How individual clients surface MCP **prompts** to the user was not verified
> (the Claude Code documentation is silent on it), so nothing here relies on
> them. Resources and tool descriptions are enough.

**What we are not doing now:** writing packagings for Codex or Cursor while
nobody uses them. Rules 1–3 make that a matter of hours rather than weeks — which
is the whole point.

**A note on the dependency:** the `dependencies` field in `plugin.json` exists
only in Claude Code. For other clients the same effect comes from an install
command plus registering the local MemPalace server (`codex mcp add mempalace`);
MemPalace ships ready packagings for Codex, Cursor and Antigravity.

---

## D-014 — Transfer to the server is the default, manual mode is a switch

**Date:** 2026-09-12 17:52 · **Status:** Accepted
· **Changes the default behaviour from D-010**

**Everything that reaches the local palace reaches the server too** — no
clicking, nothing to remember, no command. Mining still happens locally; what
changes is that its result travels onward automatically. Anyone who wants
otherwise flips `auto_publish` in the plugin settings and returns to
`/ws-publish`.

**The landing rule** — without it, "everything to the server" by default would
be a leak:

| Local palace wing | Lands in |
|---|---|
| **mapped** to a team space | that space — visible to the team |
| **unmapped** | **the user's private space on the server** |

So everything is always on the server (backup, search, access from a second
machine), yet **nothing becomes visible to the team without a mapping**. The
human confirmation moves from publication to the **mapping** — because that is
what decides visibility, and it is done once.

**Why "send" by default:** a knowledge base that requires you to remember to
contribute fills up with whatever somebody happened to consider worth a click —
which is to say, almost nothing. Value comes from completeness. Manual
publication remains as a switch for those who deliberately want to keep
everything to themselves.

**A consequence that must be named plainly:** the text of mined code **does
reach the server** (as drawers, not as a repository). The earlier property "code
never leaves the laptop" becomes **"code never leaves the company server"**.
Mining stays local, so the server still needs no access to repositories or git
keys (D-012 unchanged).

**Deduplication:** when three people mine the same repository, identical content
is sent three times. Therefore:

- `memory_entries` stores a **content digest**; publishing into a space that
  already holds a drawer with the same digest is skipped;
- deduplication works **within the target space**, so three private spaces will
  still hold three copies — which is precisely why the plugin **proposes a
  mapping** when a local wing's name matches an existing team space. One
  confirmation and there is a single copy.

**Operational effects to account for:**

- The server computes embeddings for the **entire** stream from every machine,
  not for selected fragments. This is the main factor when sizing the
  `embeddings` service — see `docs/en/05-deployment.md`.
- The secret filter now sits on **every** path, not only the one somebody
  triggered deliberately. Its tests become critical.
- The user must be able to see at any moment **what went where**: a batch log
  filterable by space, and one-action batch undo.

---

## D-015 — The local palace is primary, the server holds a copy

**Date:** 2026-09-12 18:10 · **Status:** Accepted · **Refines D-014**

Work happens in the **local palace**; the server receives a **copy**. The
direction is one-way: local → server. There is no downstream pull — an agent
reads team knowledge live through `ws_search`, not from a mirrored copy of its
own.

**The WS_Memory plugin exists precisely so that this copy is made.** Anyone who
wants to work purely locally **installs MemPalace alone** and creates no
account. That is the proper way to opt out — a choice of tool, not a setting.
The `auto_publish` switch remains an **emergency brake** (for instance while
working on something one deliberately does not want copied), not the main way of
using the system.

**A consequence that follows and is a requirement, not a wish:**

> **A local write never waits for the server and never fails because of it.**

No network, a dead server, working on a train — mining and writing to the local
palace work fully. Unsent drawers wait in a **local outbox** with a timestamp
and catch up at the next opportunity. Re-sending is safe, because deduplication
by `(source_replica, source_drawer_id)` and by `content_hash` already handles it
(D-010, D-014).

**What this buys beyond convenience:**

- **A server outage blocks nobody.** The team keeps working and copies catch up
  afterwards. The knowledge base stops being a single point of failure for daily
  work.
- **Everyone holds a full copy of their own knowledge**, whatever happens to the
  server. The server is a meeting place, not the only store.
- **Upgrading the server needs no maintenance window** announced to the team.

**What we are not doing:** synchronising in the other direction. Pulling team
knowledge down into local palaces would mean two-way synchronisation with all
its conflicts — already rejected in D-004, and that still stands.

---

## D-016 — A global administrator does not silently read other people's spaces

**Date:** 2026-09-12 21:05 · **Status:** Accepted

`ROLE_ADMIN` allows managing accounts, spaces and roles. It **grants no access
to the content** of a space the administrator is not a member of — private user
spaces included.

**Why:** an administrator who needs access can grant it to themselves. The
difference is that **granting a role is recorded in the audit log** while a
silent read leaves no trace. The first is an act somebody can be held to
account for; the second is invisible to the owner of the content.

This matters practically, not just as policy: private spaces are where
conversation transcripts land by default (D-014). If an administrator could
read them without a trace, the promise "your working conversations are yours"
would be empty, people would start switching the transfer off — and the base
would lose the very thing it exists for.

**Consequence in the code:** `SpaceAccessResolver` does not consult
`isGlobalAdmin` when computing roles. The flag serves the administrative layer
alone. Covered by the negative test
`testGlobalAdminDoesNotSilentlyReadSpacesTheyAreNotMemberOf`.

**Rejected:** *the administrator sees everything* — more convenient for user
support ("I cannot see my document, please check"), but bought at the price of
trust in the whole private-space mechanism. Support can work differently: the
administrator grants themselves a role for the duration of the diagnosis, and
that shows up in the audit trail.

---

## D-017 — Token refresh deferred rather than hand-rolled

**Date:** 2026-09-12 21:45 · **Status:** Accepted

There is no token refresh endpoint. A token expires and one signs in again. We
will return to this once `gesdinet/jwt-refresh-token-bundle` supports Symfony 8
— today it requires `symfony/console ^7`.

**Why not write our own:** refresh token rotation is security code with
non-obvious traps — detecting reuse of a stolen token, invalidating the whole
token family once reuse is detected, races when two browser tabs refresh at
once. Writing that ourselves to save users one sign-in a day is a bad trade. A
maintained bundle has solved those cases and will keep solving them; our
implementation would stay with us forever.

**What we do instead:** the access token's lifetime is set to **8 hours**, i.e.
a working day. One sign-in in the morning and that is that.

**Why this weakens security less than it might seem:** the worst scenario with
a long-lived token is "a dismissed person still has access". That scenario is
closed separately and more firmly — `ActiveAccountChecker` verifies the account
is active **on every request**, not only at sign-in, and permissions are
computed from the database every time anyway (no cache in
`SpaceAccessResolver`). Deactivating an account and revoking a role both take
effect immediately, however long the token would otherwise live.

---

## D-018 — CodeQL with AI findings plus PHPStan, because they look for different things

**Date:** 2026-09-12 23:10 · **Status:** Accepted

Code scanning rests on three legs:

1. **CodeQL in default setup** — configured in the repository settings rather
   than by a workflow file. It covers Python (our scripts) and the Actions
   workflows.
2. **AI findings** — GitHub's preview feature generating security findings for
   languages CodeQL does not support. This is what covers our PHP backend.
3. **PHPStan at level 8** — in the fast CI run.

**Why three rather than one:** CodeQL **does not support PHP** — it covers
C/C++, C#, Go, Java, JS/TS, Python, Ruby, Swift, Rust and Actions. Without AI
findings the Symfony backend would be invisible to scanning.

**Why PHPStan despite AI findings:** they look for different things. CodeQL and
AI findings hunt **vulnerabilities** — injections, leaks, misused
cryptography. PHPStan catches **correctness bugs**: a method that does not
exist, a wrong type, a condition that never holds. In permission code the
latter is often the more dangerous: a rule that mistakenly always returns true
is not a vulnerability — it is a quietly opened door, and no vulnerability
scanner will report it.

Running PHPStan over the existing code confirmed this immediately: at level 8
it found **four real defects**, among them a space description accepting any
type from JSON instead of text, and a user identifier that could be an empty
string — the very value the entire security layer keys on.

**Why CodeQL's default setup rather than our own `codeql.yml`:** AI findings
**requires the default setup**, and the default setup and a custom workflow are
mutually exclusive. So we give up custom CodeQL queries — which we would not
have written for this project anyway.

**Caveat:** AI findings is a preview feature and non-deterministic. It can
report things that are not there and miss things that are. We treat its output
as a prompt to review, not as a gate blocking a merge. The gate is PHPStan and
the tests — they give the same answer every time they run.

---

## D-019 — Two filtering layers: the wing before the question, the registry after the answer

**Date:** 2026-09-12 21:10 · **Status:** Accepted

A read of memory passes through **two** independent filters. The first narrows
the question: every `mempalace_search` call carries the wing of one permitted
space. The second checks the answer: a drawer that `ws.memory_entries` does not
place in a permitted space **does not leave the building** — not even when it
came back from a wing we asked about ourselves.

**Why two, when the first is enough:** the palace is a separate process with a
history of its own. It can be upgraded, restored from a backup older than our
table, edited by hand. Its answer is not evidence that content belongs
somewhere — it is only an answer. The first layer guards against a mistake in
our query, the second against drift between two stores.

The second layer does **not** replace the first, and the two must never be
swapped. Filtering the answer alone is exactly what inviolable rule 3 forbids:
content would be fetched out of a forbidden space, and application code would
decide whether it travels further.

**What happens to content the registry does not know:** it is **dropped**, not
reported as an error. A drawer with no registry row signals drift (integrity
rule 5 in `docs/en/02-data-model.md`) and a periodic task reports it. On the
read path, dropping is the safe direction to fail in: unknown content becomes
invisible instead of visible-but-unchecked.

**In the code:** `MemoryService::keepOnlyRegistered()`. Covered by
`testDrawerThePalaceReturnsFromAnUnregisteredWingIsDropped`,
`testDrawerUnknownToTheRegistryIsDropped` and — against a live palace —
`testDrawerFiledStraightIntoOurWingIsNotReturned`, which files a drawer directly
into our own wing, bypassing the registry.

**Rejected:** *trust the wing and skip checking results* — one SQL query less
per read. Rejected because the cost of being wrong is asymmetric: we would save
milliseconds and risk showing somebody content from a space they have no right
to. That kind of failure does not report itself.

---

## D-020 — An orphan in the palace is acceptable, an orphan in the registry is not

**Date:** 2026-09-12 21:15 · **Status:** Accepted

A write touches two stores: the palace (HTTP, no transactions) and our database
(transactions, yes). There is no distributed transaction between them and there
will not be, so a **direction of failure has to be chosen**. We choose this one:
the palace may end up holding a drawer no row points at; **never the reverse**.

The order is therefore: open the transaction → write to the palace → book the
row → commit. A failing palace rolls back a transaction that held nothing yet.
A failing booking rolls back the row and leaves the drawer in the palace.

**Why this way round:** a drawer with no row is **invisible** — the second
filtering layer (D-019) drops everything the registry does not know. A row with
no drawer would be a search result that cannot be opened: the title shows, the
click fails. The first is wasted space; the second is a bug a user reports.

**Consequences:** `MemoryRegistry::transactional()` draws the boundary, and the
timeout on a palace call (`MEMPALACE_TIMEOUT`, 15 s by default) is short
precisely because a transaction stays open for its duration. A generous timeout
would not buy a more reliable write, only a longer-held row.

Writes are **never retried**. A repeated `mempalace_add_drawer` files a second
drawer, and nothing afterwards can distinguish it from content saved twice on
purpose — MemPalace's duplicate check compares content, not intent. When a write
fails we do not know whether it landed, so we report a failure. Reads are
retried, because a repeated search costs one query.

**Rejected:** *write to the palace before the transaction, book after* — simpler,
since the transaction would not span an HTTP call. Rejected because "in the same
transaction" then means nothing, and a failed booking still leaves a drawer: we
would gain a shorter transaction and lose the only guarantee we have.

**Rejected:** *compensation — delete the drawer when booking fails* — correct in
theory. Rejected for now, because deleting can fail too and then a compensation
queue is needed; and since an orphan in the palace is invisible, we would be
solving a problem that does not hurt. A periodic task reports them.

---

## D-021 — The knowledge graph is scoped by qualified entity names, not by a filter

**Date:** 2026-09-12 21:20 · **Status:** Accepted

`mempalace_kg_query` takes **only** an entity — there is no wing parameter, no
room, no other axis. The knowledge graph in MemPalace 3.7.0 is single and shared
across the whole palace. Inviolable rule 3, meanwhile, forbids asking without a
space filter and forbids filtering results after fetching them.

The answer: **the scope goes into the key**. A fact is written under a
wing-qualified name — `wing_alfa::WS_Memory` — and asked for under the same one.
A query about another space's facts does not return them to be filtered; it does
not **match** them. The prefix is stripped before the result leaves us, so to an
agent the entity is named the way it wrote it.

Both **subject and object** are qualified, because `kg_query` matches an entity
in either position — qualifying only the subject would leave incoming facts
reachable from every space. The predicate stays bare: it is a relationship type,
not an entity, and nobody queries by it. The separator is `::`, because entity
names come from prose and a single colon occurs in them ("Note: deadline").

**A side effect, named plainly:** MemPalace will not connect
`wing_alfa::Symfony` to `wing_beta::Symfony`. Graph traversal and entity
detection work within a space, not across spaces. **This is intended** — a
relationship crossing a space boundary would be a leak, not a feature.

**In the code:** `PalaceWing::qualify()` / `unqualify()` and
`KnowledgeFact::scopedTo()` / `unscopedFrom()`. The registry books a fact under
its **unqualified** fingerprint plus the space — encoding the wing twice would
make the row unreachable from a query that only knows the bare entity name.

**Rejected:** *fetch the facts and filter them against the registry* — it works
and it is simpler. Rejected because it is rule 3 verbatim: a fact from somebody
else's space would reach process memory and an `if` would decide its fate. With
the graph this is worse than with drawers, because a fact is short and explicit
("X earns Y") — a mistake leaks not a paragraph but a sentence people remember.

**Rejected:** *one palace per space* — full graph isolation. Rejected: a dozen
palaces means a dozen processes and a dozen copies of the model in memory, and
we have one machine and one model (D-003). For genuinely sensitive spaces a
separate pgvector namespace remains (`docs/en/02-data-model.md`).

**To revisit when MemPalace is upgraded:** if `kg_add` and `kg_query` ever gain a
wing parameter, this decision should be superseded — a filter on the palace's
side is cleaner than qualifying names. Migrating would require rewriting the
facts that already exist.

---

## D-022 — The rate limit lives in the database, in the same row as "last used"

**Date:** 2026-09-12 22:05 · **Status:** Accepted

The rate limit for agent tokens is counted in two columns of `ws.agent_tokens`
(`calls_in_window`, `window_started_at`), updated by the **same statement** that
records `last_used_at` and `last_used_ip`. A fixed one-minute window.

**Why not `symfony/rate-limiter`:** it would mean a new dependency (a decision in
itself — see AGENTS.md) and a storage backend. A filesystem cache is local to one
container, so with two backend containers the limit stops applying — which is the
only situation where it is needed at all. Redis would mean another service in the
stack for one counter.

**Why it costs nothing:** the row has to be written anyway. "When was this token
last used" is the field without which nobody dares retire any token, so the write
happens on every call regardless of the limit. Adding the counter to the same
`UPDATE ... RETURNING` is free, and the result comes straight back.

**Why a fixed window rather than a sliding one:** at one minute, the difference is
an edge case — an agent can make twice the limit across a window boundary. A
sliding window would need a list of timestamps instead of a counter. The limit
exists so a runaway loop cannot swamp the palace, not to bill anybody; double
speed for one second does not defeat that.

**Consequence:** the limit is **per token**, not per account. A runaway loop in one
agent does not stop everything else that person has running — covered by
`testTheLimitIsPerTokenAndNotPerAccount`.

**Rejected:** *a limit in nginx* (`limit_req`) — it works on IP addresses, and a
team's agents may all sit behind one. It would punish the innocent and could not
tell tokens apart. It stays as a second line of defence against a flood of
requests, not as a per-token limit.

---

## D-023 — A failing MCP tool is a JSON-RPC error, not a successful result with an error inside

**Date:** 2026-09-12 22:10 · **Status:** Accepted

When a tool fails, the gateway answers with a **JSON-RPC error** carrying a code
(`-32003`, `-32010`, …). It does not answer with a success whose payload contains
the failure.

**This deliberately departs from the MCP specification**, which recommends
`isError: true` inside the result. The reason is empirical rather than aesthetic:
MemPalace does exactly what the specification recommends, and **it cost us hours**
(TODO-000). With the embedding service stopped, its answer was indistinguishable
from "I found nothing": HTTP 200, no error in the envelope, an empty result list
and the cause tucked in beside it. A client that has to inspect a payload to learn
whether a call worked will eventually not bother.

The difference between "there is nothing" and "I could not look" is decisive for
an agent: the first leads to writing the knowledge down, the second to retrying.
Confusing them produces duplicates next to content the agent never saw.

**The exception that proves the rule:** lacking permission to read is **not an
error** — it is an empty result (inviolable rule 7). There the cost runs the other
way: the message "you have no access to the space HR" itself reveals that such a
space exists.

**Consequence for clients:** an MCP client that assumes a result is always a
success will see a protocol error. That is intended — it is meant to see one.

---

## D-024 — An audit entry is written immediately, not when somebody else flushes

**Date:** 2026-09-12 22:15 · **Status:** Accepted

`DoctrineAuditTrail` performs an `INSERT` through DBAL as it is called. It used to
`persist()` an entity and leave the flush to its caller.

**Why it changed:** that worked for as long as every caller happened to flush. An
MCP tool call changes no entities, so **nothing flushed and every agent action went
unrecorded** — with no error to say so. A test in TODO-004 found it by asking for
the entry and getting none. "The audit will be saved if somebody else flushes
later" is exactly the kind of silent dependency a code review does not show.

**The trade-off, named:** an entry written inside a transaction that later rolls
back is rolled back with it; and an entry written just before an unrelated failure
can report an attempt that never completed. The direction is chosen deliberately: an
append-only log that occasionally records an attempt is useful, whereas a log whose
entries silently disappear is **worse than no log**, because it reads as proof that
nothing happened.

For a failing MCP call the trace survives regardless of the transaction: the
`AuditedTool` decorator records the exception class **after** the rollback.

**Rejected:** *`flush()` inside `record()`* — a Doctrine flush is global, so
auditing in the middle of a use case would also commit half-built entities. That is
a worse bug than the one being fixed.

**Rejected:** *a second connection for auditing only* — an entry would then survive
a rolled-back transaction, which is more correct. Rejected for now: a second
connection means its own configuration, its own connection limit and its own
failure mode, and the gain applies to a case where the decorator already leaves a
second entry. Worth revisiting when the audit trail is used for accountability
rather than diagnosis.

---

## D-025 — One drawer per document, updated in place; stale jobs are dropped

**Date:** 2026-09-12 23:05 · **Status:** Accepted

Publishing a document to the palace **updates the existing drawer**
(`mempalace_update_drawer`) rather than filing a new one. The publish job carries a
revision number and is **dropped** when the document already has a newer one.

**Why not a new drawer per revision:** the palace has no notion of versions, so every
revision would leave a searchable copy. An agent asking "what is the rent" would get
three answers from three months and **no way to tell which is current** — a search
result carries content, not a revision number. The wiki would then be worse than
nothing: it would look like a source of truth while giving untruths.

**Why not "add the new one, delete the old":** two network operations instead of one,
with a state between them in which both or neither exist. Updating in place is a
single call and keeps the identifier, so the row in `memory_entries` stays valid.

**Verified empirically, not assumed:** `mempalace_update_drawer` recomputes the
vector. The integration test writes a revision using different words and asserts the
**old content stops being findable** — had the update changed only the text, search
would still match the previous version.

**The ordering guard.** Three quick saves queue three jobs, and the queue promises no
order. A job whose revision number is behind the current one is dropped, with a log
line — without that, a late older job would overwrite the newest text with a
withdrawn version, and the wiki and the search results would diverge for no visible
reason. The test drains the queue **newest job first**, because that is the only order
in which the guard is exercised.

**When the drawer is gone** (an older backup restored, a manual deletion): the adapter
files a fresh one and returns its identifier, and the service repoints the registry
row. The alternative — failing the publication — would leave the document invisible to
search for a reason nobody can act on.

**Rejected:** *keeping history in the palace and filtering by revision number* — it
would require search results to carry a revision number and every caller to remember
it. Postgres is the source of truth for versions (D-004); the palace holds a copy of
the current text and nothing else.

---

## D-026 — A reader may propose; the reviewer authors the accepted revision

**Date:** 2026-09-12 23:10 · **Status:** Accepted

Submitting a proposal (`ws_propose`) requires the **reader** role, not the writer
one. Accepting is a write and requires the writer role; the resulting revision is
authored by the **reviewer**, and the fact that an agent wrote the text stays in the
change note.

**Why reader is enough:** the queue exists so that something can be offered **where
writing directly is not allowed**. Requiring the writer role would leave it reachable
only by those who do not need it — they could write directly. The exposure is small: a
proposal is not in the wiki, is not searchable, and `ws_propose` answers
`in_wiki: false` so an agent does not report a publication that did not happen.

**Why the reviewer is the author:** somebody has to be answerable for what was
accepted. Recording the agent as the author would mean a document nobody knowingly
approved even though it passed review — a queue with no effect. Hiding where the text
came from would equally be misleading, which is why the change note says outright
that the content came from an AI agent.

**Why a person writes directly in such a space:** somebody writing in a space with a
queue **is** the reviewer. Putting them in their own queue would leave nobody to empty
it.

**There is no MCP tool to accept or reject.** Review is a human act in the interface
(D-005); an agent approving its own proposal would make the queue decorative.

**Rejected:** *automatic acceptance after a delay* — a queue that empties itself is
not a review, only a delay.

---

## D-027 — Routes written out explicitly, no file-based routing

**Date:** 2026-09-12 23:20 · **Status:** Accepted
· **Amends** D-008 and `docs/en/07-frontend.md` in this respect

The frontend's routes are written out in one file (`src/router/index.ts`) rather than
derived from a directory tree. `unplugin-vue-router` is **not** a dependency.

**Why:** `unplugin-vue-router` 0.19.2 — the only release there is — requires
`vue-router ^4.6`, while the project's stack says **vue-router 5**, which is the
current major. That left three options:

1. take the router back a major to keep a build-time convention,
2. wait for the plugin to catch up,
3. write the routes out.

The first is a migration debt taken on day one: a new application would start on a
version it has to leave, and leaving will require changing the router and the plugin
together. The second blocks the task on somebody else's release.

**What we lose:** adding a page means adding an entry to the table. For an application
of a dozen screens (`docs/en/07-frontend.md`) that is one line per screen. **What we
gain:** every address in the application is visible in one readable file, together with
which ones are public and what they are named — information a directory tree does not
carry, and which matters here because the route guard keys on `meta.public`.

**To revisit:** once `unplugin-vue-router` supports vue-router 5 this decision can be
superseded. Migrating means moving files into a structure matching the addresses, with
no change in logic.

**Rejected:** *our own plugin scanning `pages/`* — writing a build tool to avoid
writing twelve lines of configuration is a poor trade.

---

## D-028 — The JWT lives in `localStorage`, with the risk named

**Date:** 2026-09-12 23:25 · **Status:** Accepted

The frontend's access token lives in `localStorage`. Not in an `httpOnly` cookie, not
in `sessionStorage`, and not in memory alone.

**The risk, stated plainly:** given a successful XSS, an attacker reads the token and
has access for the rest of its lifetime (8 hours, D-017). An `httpOnly` cookie would be
immune to that.

**Why we do it anyway:**

- **`httpOnly` is a backend change**, not a frontend one: the server would have to set
  the cookie and defend against CSRF, while the API is deliberately stateless (D-008)
  and serves two surfaces, one of which — `/mcp` — does not use cookies at all. That is
  a separate task, not a frontend decision.
- **`sessionStorage` breaks when a link is opened in a new tab** — the user is signed
  out mid-work, for a reason nobody can explain to them.
- **Memory only** means signing out on every page refresh, including an `F5` in the
  middle of reading a document.

**What reduces the exposure:** the token lives 8 hours rather than indefinitely;
deactivating an account cuts access at the next request (`ActiveAccountChecker`); and
every access to storage is wrapped in `try/catch`, so a private window or cleared site
data does not break the application.

**Where this is written down besides here:** `SECURITY.md`, under risks accepted
deliberately. A risk only the author of the code knows about is not accepted — it is
overlooked.

**Rejected:** *a token in memory plus refresh through a cookie* — that is the correct
architecture and it needs a refresh endpoint, which does not exist (D-017). To be
considered together with it.

---

## D-029 — Lexical mode runs on our data, not in the palace

**Date:** 2026-09-13 00:12 · **Status:** Accepted

TODO-007 calls for two search modes: semantic ("does anybody know anything about
this") and lexical ("where exactly does this name appear"). It turned out that
**the palace cannot do the second one**: `mempalace_search` accepts `query`, `wing`,
`room`, `since`, `before` and `max_distance` — and nothing else. There is no mode
parameter.

**Decision:** lexical mode is implemented on our side, in PostgreSQL, over data we
already hold:

| Source | What it covers | Extent |
|---|---|---|
| `ws.document_revisions.content` | full content of the current revision | the whole text |
| `ws.memory_entries.title` + `tags` | notes, diary, transcripts | title and tags |

**The consequence, stated plainly:** lexical search **does not search the content of
drawers other than documents**. The body of a note or a diary entry lives only in the
palace, and the palace offers semantic access to it and nothing else. The interface has
to say so rather than imply full coverage — a search result that quietly skips half the
base is worse than not offering the mode.

**Rejected:** *querying the `palace.*` tables directly* — it breaks an inviolable rule
(the palace is a dependency, not our database; `schema_filter` excludes it deliberately)
and couples us to a schema that an upgrade may change without warning. The entire value
of D-001 is that upgrading the palace touches one file.

**Rejected:** *duplicating drawer content into `ws.memory_entries`* — it doubles storage
and creates a synchronisation problem between two copies of the same content. A copy
that can drift from the original will drift.

**To revisit:** should the palace gain a lexical mode, this decision is superseded and
the adapter is the only place to change.

---

## D-030 — Lexical search uses `simple`, without stemming

**Date:** 2026-09-13 00:12 · **Status:** Accepted

PostgreSQL **ships no Polish text-search configuration** — verified with `\dF` on our
image: English, German, Hungarian and twenty others are there, Polish is not.

**Decision:** lexical mode uses the `simple` configuration (tokenisation without
stemming) with **prefix matching** (`to_tsquery('simple', 'palace:*')`), over GIN
indexes. No extension required.

**Why this is the right choice rather than a workaround:** lexical mode answers the
question "where exactly does this name appear". For that question stemming **hurts** —
searching for `Version20260912000003` or `PalaceWing`, we do not want hits that merely
share a stem. Polish inflection is a problem for meaning-based search, and that job
already has its own mode: the semantic one, which works on vectors and does not notice
inflection at all.

**Rejected:** *the `english` configuration over Polish text* — it stems by another
language's rules, so it adds wrong hits without adding right ones. Worse than no
stemming, because it looks like it works.

**Rejected:** *an `ispell` dictionary with Polish `hunspell`* — it needs dictionary files
inside the database image, meaning our own Postgres image instead of `pgvector/pgvector`,
and maintaining it at every upgrade. The cost is out of proportion to the gain, given
that the semantic mode already handles inflection.

**Rejected:** *`pg_trgm` for infix matches* — creating the extension requires `CREATE`
privilege on the database, which the `ws_app` role **deliberately does not have** (the
init script creates extensions as `postgres`). A migration running as `ws_app` could not
add it, and the way around that would be either widening the application role's
privileges or a manual administrator step on every existing database. Both are a bad
price for matching inside a word, given that prefixes cover the real use ("I type
`Palace`, I want `PalaceWing`"). To be added if somebody genuinely needs it — then
deliberately, with an administrative step.

---

## D-031 — A branch per task; checks narrowed by path; the full run on main

**Date:** 2026-09-13 12:55 · **Status:** Accepted

Changes land through a **branch per task** (`todo-NNN-short-name`) and a pull
request, not straight onto `main`. The quick check is **narrowed by path**, and the
one required check is a **single gate job** ("Wynik sprawdzenia", the check result).
The full check — the whole stack, the embedding model, the E2E tests — runs **after
the merge into `main`**, not on every pull request.

**What it was until now:** everything went straight onto `main`. The "Ochrona gałęzi
głównej" ruleset required a pull request and green checks, but the push script
bypassed it with the administrator role. Its output said so outright:
`Bypassed rule violations for refs/heads/main: Changes must be made through
a pull request.` The rule existed and was broken by every single commit. A rule
bypassed at every use is not a safeguard — it is an entry in the settings that looks
like one.

**Rejected:** *permanent layer branches* (`frontend`, `backend`, `docs`) — tempting,
because the checks could then be pinned to a branch once and for all. Three reasons
against, all of them from this repository:

1. **Changes do not split along layers, because the project's own rules force them
   not to.** Every change must have an entry in `CHANGELOG.md`, and documentation
   goes in the same commit in two languages. The model cache fix of 13 September
   touched `docker-compose.yml`, `.env.example`, the workflow, `docs/09-ci.md`,
   `docs/en/09-ci.md` and `CHANGELOG.md`. TODO-007 touched the backend, the frontend
   and the documentation at once. A layer branch would require cutting such a change
   into three, none of which is complete on its own.
2. **`CHANGELOG.md` is appended to AT THE TOP of the file.** That is the worst
   possible file for branches living in parallel: the conflict is there on every
   merge, always, and in the same place.
3. **Permanent branches delay integration.** The slash-encoding bug in a document
   address (`procedury%2Fpierwsza`) was found by an E2E test at exactly the moment
   the frontend met the backend. The longer a layer branch lives, the later that
   moment arrives — and by then it costs more.

**Why a branch per task:** the `TODO-NNN` structure already exists, so the mapping is
natural and introduces no new convention to remember —
`todo-015-aktualizacja-mempalace`. The branch lives for hours, not weeks, so a
conflict in `CHANGELOG.md` is a minor rebase rather than a permanent condition.

**Why one gate job rather than a list of required jobs** — this is a trap that would
cost half a day of searching, so it is written down explicitly. A frontend-only change
must not run PHPUnit or PHPStan, which means the backend jobs are to be skipped. But
**a skipped job does not report as green** — to the protection rule it is forever
pending, so requiring it outright would block every pull request it does not apply to.
That is why the ruleset requires a single job ("Wynik sprawdzenia") that always runs,
collects the results of the others, and treats **a skip as fine and a failure as an
error**.

**The cost, stated plainly:** an integration fault reaches `main` and we learn about
it **a few minutes after the merge rather than before it**. This is a deliberate
trade-off — the full stack answers the question "does all of this come up together",
and that question is meaningful for the state that actually holds, not for each task
branch separately. Anybody who wants the answer sooner triggers the run by hand:
`gh workflow run pelne.yml --ref <branch>`.

**Why the daily schedule stays** despite the full run after every merge: it catches
what a push cannot — an external dependency breaking without a commit of ours. An
image disappears, a model stops being available, PyPI changes. Such a failure is
better known in the morning than at the next change.

---

## D-032 — Updating MemPalace through a host agent, not a Docker socket

**Date:** 2026-09-13 14:05 · **Status:** Accepted

The administration panel lets someone **request** a MemPalace update. The request
lands in a database table; a script running periodically **on the host** (a
systemd timer) carries it out. No container is given access to Docker.

### Why this is needed at all

MemPalace is pinned hard (`MEMPALACE_VERSION`, `pip install mempalace==...`), and
rightly so — updating the palace touches the vectors, so it must not happen by
accident. The side effect, though, is that **nobody knows when something new came
out**. While writing this task it turned out 3.7.0 was running while PyPI had
3.9.0 — two minor versions behind, and we only learned it because somebody asked
by hand. That is the kind of debt that grows quietly until updating stops being a
step and becomes a project.

### Rejected: a Docker socket in the backend container

The easiest to build and the worst available. A container holding
`/var/run/docker.sock` can start any image with any mount, which is authority
**equivalent to root on the host**. The backend serves traffic from the network
and holds user accounts, so any remote code execution in Symfony, or one
compromised administrator account, would end in a compromised machine. The
convenience is not worth it.

### Rejected: a separate updater service with the Docker socket

A smaller surface — a service with no host port, reachable only from the compose
network, with a narrow API. But the backend can still call it, so breaking into
the backend still yields Docker. That moves the boundary by one step rather than
drawing it.

### Chosen: a host agent, talking through the database

The backend writes a **request**; the agent picks it up. Compromising the web
application allows, at most, requesting an update to a version that exists on
PyPI — not running arbitrary code on the host.

The agent talks to the application through `docker compose exec backend php
bin/console`, not over HTTP. That way there is no need to invent authentication
for the agent, nor to expose an endpoint that would have to be protected by
something other than a user session.

**The target version is validated by pattern on both sides** — when the request
is stored and again in the agent. Not out of distrust of the backend, but because
one layer of validation is zero layers on the day that layer has a bug. This is
the only place where data from the application feeds a command executed on the
host.

### What the agent always does

A backup of the `palace` schema **before** the rebuild and `test/semantyka.sh`
**after** it, rolling back on failure. The reason is in D-003: broken search
relevance is **silent** — search still answers, it just stops hitting. An update
without that test would be an update after which nobody knows whether something
broke.

### The cost we accept knowingly

Installation is no longer just `docker compose up`: a systemd unit has to be
installed as well. Until it is, the panel **says plainly that the updater is
unavailable** and shows no button that would do nothing. A button without an
effect is worse than no button, because it teaches people to distrust the
interface.

The second cost: a click gives no immediate result, only a request picked up
within a minute. The panel shows the state and the log, so the wait is visible
rather than mysterious.

---

## D-033 — The plugin lives in this repository, the marketplace points at a subdirectory

**Date:** 2026-09-13 17:12 · **Status:** Accepted

`plugin/` is a subdirectory of WS_Memory, and `.claude-plugin/marketplace.json`
in the repository root points at it with a `"source": "./plugin"` entry. **No
submodule and no second repository.**

**What was checked before this was settled** (in the installed plugin
directory, not in the documentation): most entries in Anthropic's official
catalogue are subdirectories of a single repository
(`"./plugins/agent-sdk-dev"`). The supported source forms are otherwise
`git-subdir` (a subdirectory of **somebody else's** repository), `url`,
`github`, `npm`, `archive` and `command`. On top of that, `claude plugin
marketplace add` has a `--sparse <paths>` flag, described outright as "for
monorepos" — it limits the download to the directories given.

So both of the worries that motivated a separate repository are moot: the
plugin **can** be kept in a subdirectory, and whoever installs it **does not
have to** download the whole application.

**Why not a submodule:**

1. **This repository's rules turn it into double work.** Every change has an
   entry in `CHANGELOG.md` and documentation in two languages in the same
   commit. A change in the plugin would therefore always be a commit in the
   plugin **plus** a commit in the main repository (the changelog,
   `docs/04-plugin.md`, `docs/en/04-plugin.md`, bumping the pointer). Two pull
   requests for one thought — exactly the cost for which we rejected permanent
   layer branches in D-031.
2. **The backend reads `plugin/shared/` while building the image**, because it
   exposes that content as MCP resources (D-013). With a submodule, CI would
   have to fetch it recursively, and **a pointer left unmoved would mean a
   server serving out-of-date instructions** — a silent failure, visible only
   after an agent has behaved according to the old protocol.
3. The symbolic links from `plugin/skills/` and `plugin/agents/` into
   `plugin/shared/` (D-013: the content exists once) have to point **inside the
   downloaded tree**. With `plugin/` as a whole, that works with a sparse
   download as well.

**What was rejected:**

- **A separate repository from the start** — today the plugin is a thin shell
  over the gateway and changes together with it. A separate repository makes
  sense once the two start living at different speeds; today it would add
  synchronisation for no benefit whatsoever.
- **A submodule** — the reasons are above.

**The way out, should the plugin ever have to go outside:** `git subtree split
--prefix=plugin` preserves the directory's history, and the marketplace entry
swaps `"./plugin"` for `git-subdir` or a separate repository. That is a manifest
change, not a move — and that is precisely why this decision can be deferred.

---

## D-034 — The plugin mechanics are checked with the tool, not by reading

**Date:** 2026-09-13 17:12 · **Status:** Accepted · **Corrects D-012**

D-012 listed **three** Claude Code mechanisms as "checked in the documentation",
and `TODO-009` added a **fourth** assumption on top of them: a `pre-compact`
hook. While implementing, we checked all four with the tooling — `claude plugin
validate`, the installed plugin directory, the event documentation.

**Two were confirmed. Two were not.**

| Assumption | From | How it actually is |
|---|---|---|
| `dependencies: ["mempalace"]` | D-012 | **The field is confirmed**, and it also accepts `{"name": …, "version": "~2.1.0"}`. The bare name turned out not to be enough, though — see item 2 below |
| `userConfig` with `sensitive: true`, available as `${user_config.KEY}` in MCP and `CLAUDE_PLUGIN_OPTION_*` in hooks | D-012 | **Confirmed**, including substitution inside the `headers` object |
| `source: {"type": "command"}` — a command run **before installation**, the place for `pip install mempalace[extract]` and `mempalace init` | D-012 | **Wrong twice over.** The key is called `source`, not `type`, and the source itself **is not an installation hook**: it is a command that **prints the path to the plugin directory**. There is no room there for installing somebody else's package |
| a `pre-compact` hook writing a summary | TODO-009 | **The `PreCompact` event does not appear in the documented list of events** (which includes `SessionStart`, `SessionEnd`, `UserPromptSubmit`, `Stop`). `PreCompact` hooks do in fact run — the MemPalace plugin uses one — but there is no documented way for such a hook to **add anything to the context** |

**What follows from this for the plugin:**

1. **Installing the `mempalace` package stays on the human's side**, described
   in the `ws-memory-setup` skill and in `docs/04-plugin.md`. The MemPalace
   plugin does not do it either — its marketplace entry carries no command. We
   do not pretend to have an installation step that cannot be expressed.
2. **There is no `pre-compact` hook.** And even if the event were documented,
   our hook could send the server nothing but the **raw conversation** — a shell
   script does not summarise. That is outright forbidden (D-012: not a single
   byte of the raw conversation goes to the server). The summary is for the
   model to write, and the recall protocol tells it so. Mining the transcript
   **into the local palace** is handled by the MemPalace hook, which comes with
   the dependency.
3. **There is no `session-end` hook.** Mirror publication is `TODO-012` and does
   not exist yet. A hook that does nothing is worse than no hook: it looks like
   a working feature.
4. **There is no `auto_publish` switch in `userConfig`.** It would control
   something that does not exist. It will arrive together with publication.

**Why this is a separate decision rather than a fix in D-012:** we do not edit
old decisions. But above all, the discrepancy is itself a finding.

D-012 described the mechanisms as "checked in the documentation, not assumed" —
and **the confirmation consisted of reading their description, not of running
anything**. Half of them did not survive first contact with the validator, and
the very point that was meant to take work off the user's hands (installing the
package automatically) turned out to be a mechanism for an entirely different
purpose.

A rule for the future: **an external tool's mechanism goes into a decision only
after we have run it.** "Checked in the documentation" now means "read", and
that is how it is to be written down.

**The rule proved itself in the very hour it was written.** The plugin passed
`claude plugin validate` without a single complaint. The real installation
(`claude plugin install`) caught three things the validator does not see:

1. `"hooks": "./hooks/hooks.json"` in the manifest is a **loading error** —
   `hooks/hooks.json` loads on its own, and the field is there for additional
   files;
2. `dependencies: ["mempalace"]` looks for the dependency in **its own**
   marketplace; it has to be `["mempalace@mempalace"]`;
3. symbolic links to **files** in `agents/` **do not load at all** — no error,
   no warning, simply `Agents (0)`. A symlink to the **directory** works. In
   `skills/`, symlinks to files work normally.

The third is of the worst kind: nothing breaks, half the plugin simply does not
exist. That is why the final check is **counting the components** in `claude
plugin details`, not a green validator.
