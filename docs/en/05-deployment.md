---
tags: [ws-memory, documentation, deployment, docker, backup, operations]
---

> Translated from [`docs/05-deployment.md`](../05-deployment.md) (synced 2026-09-12).
> **The Polish version is authoritative.**

# Deployment

Status: **working** (2026-09-12). `docker-compose.yml` covers six services;
missing are `frontend` (TODO-006) and the production TLS configuration.

## Server requirements

| Resource | Minimum | Recommended | Why |
|---|---|---|---|
| RAM | 6 GB | 12 GB | the `bge-m3` model takes ~2–3 GB **once loaded** (measured); Postgres with HNSW likes cache |
| CPU | 4 cores | 8 cores | vector computation while processing published batches |
| Disk | 40 GB | 100 GB SSD | the database grows with the knowledge volume; 1024-dimensional vectors |
| Docker | Engine 24+ with Compose v2 | | |

No GPU is required. The `embeddings` service can move to a GPU machine without
changing anything else — it is the only component computing vectors.

**Every service has a hard `mem_limit`**, and this is protection rather than
performance tuning. On the first run the embedding server with TEI's default
buffers took 21 GB and choked the machine. The buffers are now tuned
(`--max-batch-tokens 2048`, `--tokenization-workers 2`, `--auto-truncate`), but
the limit stays as a second line of defence: a container should die on its own
rather than take the server with it.

**What decides how much power it needs:** since D-014 transfer from local
palaces is the default, so the server computes vectors for the **entire**
knowledge stream of every machine, not for selected fragments. With a dozen or
so people mining projects and conversations this is steady background traffic,
not occasional spikes. If the publication queue starts growing, add cores to
`embeddings` first and only then consider a GPU.

## Getting it running

```bash
git clone <repo> ws-memory && cd ws-memory
cp .env.example .env
./docker/wygeneruj-sekrety.sh    # random passwords and tokens
make start                       # first start ~3 min (model download)
make migracje
```

Verifying everything is alive:

```bash
make test-semantyka              # a Polish query finds Polish content
curl http://127.0.0.1:8080/api/health
```

The administrator account arrives together with user management (TODO-002).

## Environment variables

| Variable | Role |
|---|---|
| `WS_DOMAIN` | public domain (certificate, links in e-mails) |
| `POSTGRES_PASSWORD` | password of the database superuser role |
| `MEMPALACE_DB_PASSWORD` | password of the `mempalace` role (schema `palace`) |
| `WS_DB_PASSWORD` | password of the `ws_app` role (schema `ws`) |
| `APP_SECRET` | Symfony application secret |
| `EMBEDDING_MEM_LIMIT`, `EMBEDDING_MAX_BATCH_TOKENS` | limits and buffers of the embedding server — see above |
| `JWT_SECRET_KEY` / `JWT_PUBLIC_KEY` | signing of human tokens |
| `MEMPALACE_MCP_HTTP_TOKEN` | token between the backend and mempalace; **never leaves the network** |
| `MEMPALACE_BACKEND` | `pgvector` |
| `MEMPALACE_PGVECTOR_DSN` | mempalace's connection to Postgres |
| `MEMPALACE_EMBEDDING_MODEL` | `openai-compat` |
| `MEMPALACE_EMBEDDING_API_URL` | `http://embeddings/v1/embeddings` |
| `MEMPALACE_EMBEDDING_API_MODEL` | `BAAI/bge-m3` |
| `MEMPALACE_ENTITY_LANGUAGES` | `pl,en` — entity detection; defaults to `en`, see D-011 |
| `MAILER_DSN` | invitations and notifications |

The three MemPalace embedding variables are **inseparable** — see D-003.
Changing the model invalidates every vector in the database.

## TLS

`nginx` with Let's Encrypt (`certbot` in webroot mode). The only exposed ports
are 80 (redirect and renewals) and 443. No other service maps a port to the
host (D-006).

## Backup

**One command covers the whole system** — the main benefit of keeping the
palace inside Postgres (D-002):

```bash
docker compose exec -T postgres pg_dump -U postgres --format=custom ws_memory \
  > backup-$(date +%F-%H%M).dump
```

It covers documents, every revision, accounts, permissions, the audit trail
**and the palace** (vectors together with metadata). Restoring:

```bash
docker compose exec -T postgres pg_restore -U postgres -d ws_memory --clean < backup.dump
```

Beyond the database, only the JWT keys need copying — the server keeps no raw
source material.

**Schedule:** a daily `pg_dump` kept for 30 days, a weekly one kept for a year.
Restoring from a backup must be tested — an untested backup is not a backup.

## Upgrading MemPalace

MemPalace is a black box behind the HTTP MCP boundary (D-001), so upgrading it
does not touch our code:

1. Back up the database.
2. Bump the version in `docker/mempalace/Dockerfile`, rebuild the image.
3. `docker compose up -d mempalace`, check `/healthz`.
4. Run the Polish semantics integration test (`make test-semantyka`) — if it
   passes, the vectors are intact.

What you must **not** do while upgrading: change the embedding model "while
you're at it". That is a separate operation requiring the whole base to be
recomputed.

## Known limitation: Doctrine schema tooling

`doctrine:schema:validate` (the full form) and `doctrine:migrations:diff`
**do not work** with this set of packages: DoctrineBundle 3 needs the
`Schema::edit()` API from DBAL **^4.5**, and the newest stable release is 4.4.4.
This is not a consequence of our configuration — verified: the error appears
even with `schema_filter` removed.

Until DBAL 4.5 ships:

- migrations are **written by hand** (which is more explicit anyway),
- mapping correctness is checked with `doctrine:schema:validate --skip-sync`,
- `schema_filter` stays in the configuration, because it will work once DBAL
  arrives.

The filter is not decorative: the `ws_app` role **can see** the tables in the
`palace` schema, so without it the diff generator would one day propose
dropping them.

## A trap: the schema filter versus `search_path`

Doctrine's `schema_filter` matches the pattern against table names **as DBAL
returns them** — and those depend on `search_path`. The `ws_app` role runs with
`search_path = ws, public`, so our own tables come back **unqualified**
(`users`, not `ws.users`). A `~^ws\.~` filter rejected all of them, so Doctrine
could not see its own migrations table and tried to create it again on the
second run:

```
SQLSTATE[42P07]: Duplicate table: relation "doctrine_migration_versions" already exists
```

The correct pattern is the inverse — it **excludes** `palace` instead of
requiring `ws`:

```yaml
schema_filter: '~^(?!palace\.)~'
```

This works because `palace` sits outside `search_path`, so its tables always
arrive schema-qualified and the pattern catches them.

## Monitoring

| What | How |
|---|---|
| mempalace liveness | `GET /healthz` (unauthenticated) |
| embeddings liveness | `docker compose exec mempalace curl http://embeddings:80/health` — the TEI image is distroless and cannot check itself |
| **silent search degradation** | a search carrying an error inside the tool payload (`results: []` + `error`) — MemPalace does not report it in the JSON-RPC envelope, so the alert has to look inside |
| backend liveness | `GET /api/health` |
| queue backlog | Messenger jobs older than an hour |
| palace/register divergence | nightly job: `memory_entries` without a drawer in the palace |
| database growth | size of the `ws` and `palace` schemas in a weekly report |

Divergence is reported, never silently repaired. A silent repair would hide the
bug causing it.

**The most dangerous failure in this system is a silent one.** When the
embedding server stops responding, search still returns a valid response — just
an empty one. To a user that looks like "we have nothing on this" rather than an
outage. Monitoring therefore has to inspect the response body, not only the HTTP
status.

## Retention

| Data | Retention |
|---|---|
| document revisions | indefinitely (history does not shrink) |
| `audit_log` | 24 months, then aggregated into statistics |
| publication batches | indefinitely (unit of undo and an audit trail) |

The server **does not store** raw session transcripts — they are mined locally
and never arrive here (D-012).
