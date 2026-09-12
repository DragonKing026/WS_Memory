---
tags: [ws-memory, decisions, adr, architecture, rationale]
---

> Translated from [`docs/06-decyzje.md`](../06-decyzje.md) (synced 2026-09-12).
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

**Why:** the Web Systems team maintains code in Symfony (główna aplikacja Symfony zespołu: Symfony 8,
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

The pattern is carried over from **nowszy projekt z frontendem Vue**, where this split has
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
- **The team is competent on both sides** — Symfony (główna aplikacja Symfony zespołu) and Vue 3
  (nowszy projekt z frontendem Vue). We introduce no new technology, only use two already
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

**Rejected:** *TipTap* — used in nowszy projekt z frontendem Vue and good in its role
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
