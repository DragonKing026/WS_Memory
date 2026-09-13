---
noteId: "3a7ed980aec811f1835b4b4fc1577c80"
tags:
  - "ws-memory"
  - "overview"
  - "onboarding"

---

> Translated from [`README.md`](README.md) (synced 2026-09-12).
> **The Polish version is authoritative** — it is where decisions are made.

# WS_Memory

A shared knowledge base and documentation for Web Systems — **one for people
and for AI models alike**. A person signs in through a browser and writes
documentation; an AI agent reads the same knowledge over MCP and writes back to
it. One source of truth, no exports, no synchronisation.

The memory engine is [MemPalace](https://github.com/MemPalace/mempalace), used
as a dependency. WS_Memory adds identity, permissions, a human interface and a
team deployment.

**The backend (Symfony 8, pure API) and the frontend (Vue 3 + Vite) are
separate** — the backend runs independently and exposes two surfaces over the
same logic: REST `/api` for people and MCP `/mcp` for agents.

## Status

**The backend reads and writes memory.** The design was approved on 2026-09-12;
complete: `TODO-000` (database, embeddings, memory + proof of Polish semantics),
`TODO-001` (backend as a pure API), `TODO-002` (accounts, invitations, spaces and
roles), `TODO-003` (memory access with a hard space filter), `TODO-004` (the MCP gateway
and agent tokens), `TODO-005` (the wiki with revisions and the review queue),
`TODO-006` (the frontend foundation), `TODO-013` (bilingual documentation) and
`TODO-014` (CI).

**An AI agent can already connect**: `ws:agent:token` prints a ready
`claude mcp add`, and **eleven** `ws_*` tools read and write the shared base behind
a hard space filter — including the wiki, with its full revision history and
rollback.

**The application for people is up too**: signing in, spaces and agent-token
management at `http://127.0.0.1:8080`. It is missing search (`TODO-007`) and
document browsing and the editor (`TODO-008`) — until then the wiki is reachable
through the API and through agents. Remaining tasks live in `TODO/`.

## What it gives you

- **For the team** — a wiki with versioning, diffs and rollback; sign-in with a
  company account; knowledge split into spaces (project / client / department)
  with roles.
- **For AI agents** — an MCP server with company tools: search the base, record
  decisions, write documentation, query the knowledge graph. Plus a Claude Code
  plugin with hooks, skills and subagents.
- **The base fills itself** — the plugin pulls MemPalace in as a dependency, so
  everyone has a local palace and mines locally (`mempalace init`,
  `mempalace mine`). The result travels to the server by default: mapped wings
  land in team spaces, everything else in your private one. Nothing becomes
  visible to the team without a mapping, and the whole transfer can be switched
  off.
- **For the administrator** — one `docker compose up`, one `pg_dump` as a
  complete backup, an audit trail of every read and write.

## Quick start

```bash
./scripts/instaluj.sh
```

The installer asks for what cannot be guessed (address, port, administrator
account, mail), **generates the secrets itself**, writes `.env`, brings the
stack up, runs the migrations, creates the account and **checks that it all
actually works** — semantic search included, because its failure is silent. The
administrator's password lands in a file with `600` permissions and the
installer says which one.

To check without changing anything:

```bash
./scripts/instaluj.sh --tylko-sprawdzenie   # is this machine suitable
./scripts/instaluj.sh --na-sucho            # the whole path, no changes
```

Uninstalling: `./scripts/odinstaluj.sh` — confirmed by **typing the instance
name**, takes a backup before deleting, and leaves the backups and the embedding
model alone until you ask for those separately.

<details>
<summary>By hand, step by step (the same thing the installer does)</summary>

```bash
cp .env.example .env
./docker/wygeneruj-sekrety.sh   # random passwords and tokens
make start                      # first start ~3 min: downloads the embedding model
make migracje
docker compose exec backend php bin/console lexik:jwt:generate-keypair
docker compose exec backend php bin/console ws:user:create you@company.com --admin
```

</details>

Checking that the foundation works:

```bash
make test-semantyka   # a Polish query must find Polish content
make test             # backend tests
curl http://127.0.0.1:8080/api/health
```

Full description: [README.docker.md](README.docker.md).

## Documentation

| File | Contents |
|---|---|
| [AGENTS.en.md](AGENTS.en.md) | **Start here.** The contract for people and agents: rules, stack, ways of working |
| [docs/en/01-architecture.md](docs/en/01-architecture.md) | Services, data flows, security boundary |
| [docs/en/02-data-model.md](docs/en/02-data-model.md) | The `ws` schema, mapping spaces onto the palace |
| [docs/en/03-mcp-gateway.md](docs/en/03-mcp-gateway.md) | MCP tools and how permissions are enforced |
| [docs/en/04-plugin.md](docs/en/04-plugin.md) | The WS_Memory plugin: hooks, skills, subagents |
| [docs/en/05-deployment.md](docs/en/05-deployment.md) | Docker, TLS, backups, upgrades |
| [docs/en/06-decisions.md](docs/en/06-decisions.md) | Technical decisions with their reasoning |
| [docs/en/07-frontend.md](docs/en/07-frontend.md) | Vue frontend conventions, screens, rules |
| [docs/en/08-backend.md](docs/en/08-backend.md) | **The backend: what each part is for** — file map, flows, how to add things |
| [docs/en/09-ci.md](docs/en/09-ci.md) | Continuous integration: what runs when, what to do when it is red |
| [SECURITY.md](SECURITY.md) | How to report a vulnerability, and what counts as one here |
| [CHANGELOG.md](CHANGELOG.md) | Change history with dates and times (Polish only) |
| [TODO/](TODO/) | Tasks; completed ones in `TODO/DONE/` (Polish only) |

## Licence

Property of Web Systems. Internal use only.
