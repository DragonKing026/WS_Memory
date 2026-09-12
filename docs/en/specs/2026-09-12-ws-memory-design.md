---
tags: [ws-memory, spec, design, decisions, architecture]
---

> Translated from [`docs/superpowers/specs/2026-09-12-ws-memory-design.md`](../../superpowers/specs/2026-09-12-ws-memory-design.md)
> (synced 2026-09-12). **The Polish version is authoritative.**

# WS_Memory — design spec

**Date:** 2026-09-12 · **Status:** approved · **Decisions by:** Artur Ograbek
**Stage:** design complete, implementation not started

This document preserves the **decisions and their reasoning** from the design
phase. The current description of how the system works lives in `docs/01`–`07`;
where they diverge, `docs/` is current and this file remains as a record of how
things came out that way.

> **A historical record — we do not update it.** The spec closes at decision
> D-009. Decisions D-010…D-015 followed and changed three things described
> below: mining happens **locally only** (D-012) rather than on the server;
> transfer to the server is **the default** (D-014); the local palace is
> **primary** and the server holds a copy (D-015). Current state:
> `docs/en/06-decisions.md`.

## 1. The problem

Web Systems has no shared knowledge base. Knowledge lives in people's heads, in
code, in conversations with AI agents and in files on disks. An AI agent starts
every session from nothing, and a newcomer asks colleagues about things somebody
already worked out once.

What is needed is **one knowledge base for people and for models**: a person
writes and reads through a browser, an agent reads and writes over MCP, and each
sees what the other did without exports.

## 2. Scope

**In scope:** an MCP server with identity and permissions · a web application
with sign-in and a versioned wiki · a Claude Code plugin (tools, instructions,
settings, agents) · a Docker deployment.

**Out of scope (for now):** SSO, 2FA, Slack integration, interface
localisation, a mobile application, public access to selected documents.

## 3. What MemPalace provides and what we add

MemPalace 3.7.0 is a **dependency**, not an inspiration. It provides a vector
store, semantic and lexical search, 36 MCP tools, a miner (code, PDF/DOCX,
transcripts), a knowledge graph, a diary and session hooks.

Reading the installed version's source established four facts that defined the
scope of WS_Memory:

1. **`mempalace serve` authenticates with a single shared token** — no per-user
   identity, no attribution, no permissions. This is the main gap we fill.
2. **The pgvector backend is fully featured** (HNSW, BM25+vector hybrid,
   namespace isolation) and safe with multiple writers.
3. **The hub-forward mechanism works only within one machine** (it discovers the
   hub through a file in the palace directory) — unsuitable for a remote team.
4. **The default embedding model `minilm` is trained on English only** — which
   disqualifies it for a base written in Polish.

We add: identity and attribution · spaces with roles · a human interface with
versioning · a curated MCP tool set as the permission boundary · a company
plugin · a team deployment with backups and auditing.

## 4. Three classes of knowledge

A fundamental distinction — confusing them leads to bad decisions.

1. **Raw memory** (the bulk, automatic): transcripts, the diary, the knowledge
   graph, repository mining. The agent writes freely. Not versioned.
2. **Findings and notes**: the agent writes directly, marked as AI.
3. **Canonical documentation (the wiki)**: revisions, diffs, rollback. **The
   agent writes directly here too**; it gets the "author: AI" status and a
   human-verification flag — a trust marker, **not a gate**.

Why no gate: AI will produce most of the writes (hooks mine transcripts
automatically). An approval gate would be a bottleneck and would get routed
around. The protection is the ability to roll back, not blocking the write.

## 5. Architecture

Seven Docker services; the only entrance from outside is `nginx`. The backend
(Symfony 8, a pure API) exposes REST `/api` for the Vue frontend and MCP `/mcp`
for agents — **over shared domain services**, so a permission rule exists in one
place. `mempalace` is the only component that can search semantically and mine.
`embeddings` computes vectors for the whole system. `postgres` (18 + pgvector)
holds the palace and the application data in two schemas.

Details: `docs/en/01-architecture.md`, `docs/en/02-data-model.md`,
`docs/en/03-mcp-gateway.md`, `docs/en/07-frontend.md`.

## 6. Decisions

| No. | Decision | Key reason |
|---|---|---|
| D-001 | Symfony 8 as the backend, MemPalace as a sidecar | team competence; upgrading MemPalace does not touch our code |
| D-002 | PostgreSQL 18 + pgvector instead of MariaDB | Polish stemming in FTS, pgvector, JSONB, transactional DDL |
| D-003 | a central embedding server, `BAAI/bge-m3` | `minilm` is English; `e5` needs prefixes MemPalace will not add |
| D-004 | Postgres as the wiki's source of truth, the palace as the search layer | versioning is a relational database's job; ACL in SQL, not on results |
| D-005 | the agent writes without a gate | AI will produce most writes; a gate would be routed around |
| D-006 | a closed network, transcripts over HTTPS | the alternative required Postgres on the internet |
| D-007 | a curated MCP tool set | the tool boundary is the permission boundary |
| D-008 | separating backend and frontend | the backend must run independently; the nowszy projekt z frontendem Vue pattern |
| D-009 | CodeMirror 6, not WYSIWYG | documents circulate between people and AI; every WYSIWYG round trip loses content |

Full reasoning and rejected alternatives: `docs/en/06-decisions.md`.

## 7. Security rules

1. An agent never receives the MemPalace token or the DSN.
2. No MCP tool has an "author" parameter — identity comes from the token.
3. We never query the palace without a space filter; filtering results after
   fetching is a leak, not a permission.
4. An agent token never carries more permissions than its owner.
5. An agent does not verify its own entries.
6. A write with no space → the token owner's private space.
7. No permission for a space returns an **empty result**, not an access-denied
   message (the message is itself a leak).

## 8. Project completion criteria

- A person signs in from an invitation, creates a document, edits it, sees a
  diff of two revisions and rolls a change back.
- An agent finds that document through the plugin **with a Polish query using
  different words than the content** and adds a document of its own, which the
  person sees immediately marked "author: AI".
- The agent of a user with no role in a space sees neither its content **nor its
  existence** — covered by a negative test.
- A session transcript reaches the author's private space automatically.
- `pg_dump` plus a restore on a clean machine brings back the whole system.
- `docker compose up -d` on a fresh server produces a working installation.

## 9. Risks

| Risk | Handling |
|---|---|
| changing the embedding model invalidates the vectors | decided before the first write; semantic test in CI |
| MemPalace changes its MCP API between versions | a black box behind an HTTP boundary; an integration test after every upgrade |
| a bug in the `wing` filter = a leak between spaces | negative tests; a pgvector namespace for sensitive spaces |
| AI clutters the base with low-quality entries | `ws-archiwista` detects duplicates; the verification flag; rollback |
| transcripts contain private content | the author's private space by default; a switch in the hook |
| the knowledge volume outgrows one machine | `embeddings` can move to a GPU; `worker` scales horizontally |
