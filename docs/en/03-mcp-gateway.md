---
tags: [ws-memory, documentation, mcp, permissions, ai-agents, security]
---

> Translated from [`docs/03-mcp-gateway.md`](../03-mcp-gateway.md) (synced 2026-09-12).
> **The Polish version is authoritative.**

# MCP gateway

Status: **working** (2026-09-12, `TODO-004` and `TODO-005`). Eleven tools, agent
tokens, a rate limit and an audit entry for every call. The set is complete — further
tools arrive only with the bridge to local palaces (`TODO-012`). Since `TODO-009` the gateway
additionally publishes **MCP resources** carrying the instruction content for
agents — see "Resources".

The backend exposes an MCP server over HTTP (JSON-RPC 2.0) at `/mcp` with a
**curated set of company tools** — it does not pass MemPalace's 44 tools
straight through (D-007). The tool boundary **is** the permission boundary.

## Protocol

`POST /mcp`, JSON-RPC 2.0, header `Authorization: Bearer <agent token>`.
Supported methods: `initialize`, `tools/list`, `tools/call`, `resources/list`,
`resources/read`, `ping`, plus the `notifications/initialized` and
`notifications/cancelled` notifications.

The declared protocol revision is **2025-06-18**. A client asking for a known older
one (`2025-03-26`, `2024-11-05`) gets its own back — refusing would lock out
clients that would work perfectly well, since the tool schemas do not differ
between revisions.

**Batch requests are not supported** (`-32600`). One call per request keeps the
rate limit and the audit trail honest: a batch would count as one call while doing
twenty.

A notification (a request with no `id`) gets **HTTP 202 and an empty body**.
Anything else hangs clients that are not waiting for an answer.

A client (Claude Code) is configured with a single command — `ws:agent:token`
prints it alongside the token:

```bash
claude mcp add --transport http ws_memory https://wsmemory.twoja-domena.pl/mcp \
  --header "Authorization: Bearer $WS_MEMORY_TOKEN"
```

## Tools

### Reading

| Tool | Parameters | Returns |
|---|---|---|
| `ws_status` | — | who the token belongs to, which spaces with role and entry count, **where an unaddressed write will land** |
| `ws_search` | `query`, `spaces?`, `kind?`, `limit?`, `since?`, `before?` | semantic matches from spaces the token may reach |
| `ws_get` | `id` | full content; `found: false` for a missing **and for a forbidden** one |
| `ws_kg_query` | `entity`, `direction?`, `spaces?` | facts from the knowledge graph with validity windows |
| `ws_doc_list` | `space?`, `query?`, `include_archived?` | documents with revision, `verified` and `authored_by_ai` |
| `ws_doc_read` | `space`, `slug`, `revision?` | document content; without `revision` — the current one |

### Writing

| Tool | Parameters | Effect |
|---|---|---|
| `ws_remember` | `text`, `space?`, `tags?` | a drawer in the palace + a row in `ws.memory_entries`; returns the space it **actually** landed in |
| `ws_kg_add` | `subject`, `predicate`, `object`, `space?`, `valid_from?`, `valid_to?` | a fact in the knowledge graph |
| `ws_diary_write` | `text`, `space?`, `topic?` | a session diary entry |
| `ws_doc_write` | `space`, `slug`, `title`, `content`, `change_note?` | a new revision; creates the document if absent. Replaces the content wholesale |
| `ws_propose` | `space`, `title`, `content`, `slug?` | an entry in the queue where `spaces.requires_proposal`; needs only the reader role (D-026) |

> **`ws_remember` has no `kind` parameter** and always files a note. Letting an
> agent pass `document` would put a drawer in the `documentation` room with no row
> in the `documents` table — a wiki page the wiki does not know about: invisible on
> every screen and impossible to revise. Documents arrive with `ws_doc_write`,
> where a revision is created alongside.
>
> **Every answer about a document carries `verified` and `authored_by_ai`.** That
> pair is the whole trust model in two fields. Without it an agent will cite another
> agent's unverified draft as though a person had checked it.
>
> **`ws_doc_write` replaces the content wholesale; it does not append.** That is why
> the tool description tells the caller to read the current version through
> `ws_doc_read` first — skipping that step produces no error, only a revision with
> half the document missing.
>
> **A proposal is not in the wiki, and `ws_propose` says so** (`in_wiki: false`).
> Without it an agent reports a publication that never happened.
>
> **A write returns the destination space, not the requested one.** With no `space`
> parameter the two differ, and an agent answered `null` has no way to know where
> its content went — nor to notice that it went somewhere it did not intend
> (inviolable rule 6).

What **does not exist and will not**:

- **`ws_doc_verify`** — verification is a human act in the interface. An agent
  does not confirm its own entries (D-005).
- **`ws_doc_delete`** — documents are archived, never deleted. Revision history
  never shrinks.
- **a `wing` parameter** — in no tool at all. The server chooses the wing; a wing
  name in a request would invalidate the entire permission model.
- **any administrative tool** — creating spaces, granting roles and issuing
  tokens belong to the human interface alone.

### An unknown parameter is an error

Every tool refuses a parameter it does not recognise (`-32602`), together with the
list of allowed ones. It does **not** ignore it quietly, and that is a decision
rather than an oversight: the parameter an agent is most likely to invent is
`wing` — learned from the local MemPalace server, connected in the same session.
An ignored `wing` would mean the agent **believes it narrowed its search** when it
did not. Hearing "no such parameter" costs one retry; being ignored costs a wrong
conclusion about what the agent has just read.

## Resources — the instructions for agents

The gateway publishes the instruction content for agents as **MCP resources**: the
recall protocol, the documentation rules, the setup guide and the briefs of the four
subagents. Every MCP client reads them, not only Claude Code.

| URI | What it is |
|---|---|
| `ws-memory://protokol-recall` | search the base **before** answering about past decisions |
| `ws-memory://jak-dokumentowac` | what is a note, what is a document, how to name a slug and describe a change |
| `ws-memory://konfiguracja` | issuing an agent token and checking the connection |
| `ws-memory://agenci/ws-recall` | subagent: everything already decided, before a decision |
| `ws-memory://agenci/ws-dokumentalista` | subagent: writing up the result of a closed task |
| `ws-memory://agenci/ws-archiwista` | subagent: duplicates and contradictions, through proposals |
| `ws-memory://agenci/ws-onboarding` | subagent: answers **only** from the company base |

```json
{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"ws-memory://protokol-recall"}}
```

The shape is the protocol's own: `resources/list` returns entries of
`{uri, name, title, description, mimeType}`, and `resources/read` returns
`{"contents":[{"uri","mimeType","text"}]}`. `mimeType` is always `text/markdown`.
An unknown URI is a **JSON-RPC error** `-32002` (the specification's own code for
it), not an empty document — D-023.

### Where the content comes from

From `plugin/shared/`, which is its **only** source (D-013). The same content is
wired up by the plugin as skills and subagents; no packaging copies it. The
practical consequence: changing an instruction is a **server deployment** rather
than an update everybody has to install — and porting to Codex, Cursor or Zed
requires no rewriting of instructions.

The URI-to-file mapping is an **explicit table in the code**
(`Infrastructure\Instruction\FileInstructionLibrary`), not a directory scan: a
scan would publish whatever happens to land in that directory to every agent. The
files carry YAML front matter with `name` and `description` — packaging metadata, so
it is **kept out of the resource body**, while `description` serves as the
description on the list. The directory is given by `WS_INSTRUCTIONS_DIR`
(`docs/05-deployment.md`); a missing file is an error, not an empty resource.

### Reading a resource is not written to the audit journal

A tool call leaves an entry; reading a resource does **not**, and that is a decision
rather than an oversight. A resource is static text, identical for every token, and
an MCP client asks for the resource list on **every** connection. The entry would
therefore say "somebody connected", not "somebody did something".

We have paid for this exact mechanism once already — an event recorded per request
instead of per real action produced **20,335** fictitious `user.login` rows, half
the journal on the day the audit screen was first opened
(`Infrastructure\Security\LoginAuditSubscriber`). A journal whose majority is
fiction is worse than a short one, because the real entries are somewhere inside it
and nobody will find them.

**The rate limit covers these methods like every other one** and stays that way:
looping over the resource catalogue loads the server exactly as much as looping over
searches.

## How permissions are enforced

Four rules, each covered by a negative test:

1. **Identity comes from the token, never from a parameter.** No tool has an
   "author" field. Impersonation is inexpressible in the API, not merely
   forbidden.
2. **The space filter is injected server-side.** The `spaces` parameter can
   only **narrow** the scope. The backend computes the intersection:
   `requested ∩ owner's permissions ∩ token scope`. An empty intersection is an
   empty result, not an error — the agent never learns the space exists.
3. **A write without a space lands in the token owner's private space.** A safe
   default: an agent's mistake does not pollute the shared base.
4. **A token never carries more than its owner.** Revoking a person's role
   revokes it for all their agents at once, with no separate operation.

Shared domain services behind `/api` and `/mcp` (D-008) matter here: a
permission rule exists in exactly one place, so it cannot be bypassed by
choosing a different entry point. That place is `MemoryService` — an MCP tool
calling the palace directly would skip both filters (D-019) and the bookkeeping
of the write (D-020).

On top of that comes the **second filtering layer**: content that
`memory_entries` does not place in a permitted space does not leave, even when it
came back from a wing we asked about ourselves (D-019).

## Mapping onto MemPalace

| WS tool | MemPalace call | What the memory layer adds |
|---|---|---|
| `ws_search` | `mempalace_search` × the number of permitted spaces | one wing per call, re-ranking of the results, `kind` → `room`, result filtering against `memory_entries` |
| `ws_get` | `mempalace_get_drawer` | ownership verified **before** fetching; wing-to-space agreement verified after |
| `ws_remember` | `mempalace_add_drawer` | the space's `wing`, author from the token, a row in `memory_entries` in one transaction |
| `ws_kg_query` / `ws_kg_add` | `mempalace_kg_query` / `mempalace_kg_add` | the entity name qualified by the wing (D-021), author |
| `ws_diary_write` | `mempalace_diary_write` | **an explicit space wing** — without it the palace files the entry into `wing_{agent_name}`, outside every space mapping |
| `ws_doc_*` | — | SQL against `ws` only; publishing to the palace goes through `worker` |

> **`mempalace_search` takes one wing, not a list.** `wing IN (...)` is therefore
> not expressible in a single call — a read fans out into one query per permitted
> space and re-ranks the results. That costs N queries for N spaces, but keeps
> rule 3 without an exception, and an empty intersection of permissions never asks
> the palace at all.
>
> **The knowledge graph has no wing axis whatsoever.** So the scope goes into the
> key: facts are written and read under a qualified name (`wing_alfa::Entity`), and
> a query about another space does not match them rather than matching and
> filtering them out (D-021). The consequence: relationships do not cross space
> boundaries — which is intended.
>
> **`agent_name` in the diary is a path segment.** The author label may contain
> neither `:` nor `/`, so it takes the form `ws_<user>__<token>`. One label for
> every tool, not a label per tool.

The MemPalace token is known to the backend **alone**. An agent never sees it.

## Agent tokens

A token is **not a JWT**, and that is not an oversight: it lives for months, has to
die the moment somebody says so, carries a scope that narrows its owner's
permissions, and shows when it was last used. A JWT does none of those.

Issuing one from the command line — the only route until the screens exist
(`TODO-008`):

```bash
docker compose exec backend php bin/console ws:agent:token \
  artur@web-systems.pl "Artur's laptop" --space=projekt-alfa
```

The command prints a ready `claude mcp add`. **The token is shown once** — the
database holds only its `sha256` digest. The `wsm_` prefix is not decoration: it
lets secret scanners and humans recognise what they are looking at in a
configuration file.

For the frontend: `GET /api/agent-tokens`, `POST /api/agent-tokens`,
`DELETE /api/agent-tokens/{id}`. All of them cover **one's own tokens only**,
global administrators included — somebody who could quietly retire another
person's agent could stop their work without a trace (D-016). The visible route is
deactivating the account, which is recorded.

The listing shows `lastUsedAt`. It is the field without which nobody dares retire
any token, so the list only ever grows.

### Rate limit

**120 calls per minute per token** (`MCP_CALLS_PER_MINUTE`), counted in the
database by the same write that records "last used" (D-022). Exceeding it gives
`429` and code `-32005`.

The limit is **per token, not per account**: a runaway loop in one agent does not
stop everything else that person has running. Every method counts, `tools/list`
included — looping over the tool catalogue loads the palace just as much as looping
over searches.

## Errors

A tool failure comes back as a **JSON-RPC error**, not as a successful response
with an error inside. This deliberately departs from the MCP specification's
recommendation, for an empirical reason — MemPalace follows the recommendation, and
with the embedding service stopped its answer was indistinguishable from "found
nothing" (D-023).

| Situation | Response |
|---|---|
| missing / wrong token | HTTP `401`, no JSON-RPC body |
| revoked or expired token | `401` + `WWW-Authenticate` header |
| owner's account deactivated | `401` — without revoking tokens one by one |
| space outside permissions (read) | **empty result**, not an error (existence is not disclosed) |
| drawer outside permissions (`ws_get`) | `found: false` — identical to a missing one |
| write to a space without the `writer` role | `-32003`, message naming the missing write permission |
| space requires the queue, `ws_doc_write` used | `-32004` with a hint to use `ws_propose` |
| document outside permissions (`ws_doc_read`) | `found: false` — identical to a missing one |
| rate limit exceeded | `429` + `-32005` |
| MemPalace unreachable | `-32010`, "memory temporarily unavailable — this does not mean nothing was found" |
| unknown parameter, wrong type, missing required one | `-32602` with the list of allowed parameters |
| unknown tool or method | `-32601` with a hint to call `tools/list` |
| unknown resource URI (`resources/read`) | `-32002` with a hint to call `resources/list` |
| a published instruction that cannot be read | `-32603` — never an empty document; detail goes to the server log |
| body is not JSON | `-32700` |
| batch request, or missing `jsonrpc: "2.0"` | `-32600` |
| internal error | `-32603`, deliberately without detail — that goes to the server log |

The distinction between "empty result" and "no permission" is deliberate: the
message "you have no access to the *HR* space" is itself an information leak.

The distinction the other way round is just as deliberate: "I could not look" never
turns into an empty result. An agent told "there is nothing" writes the knowledge
down again, next to the copy it never saw.
