---
tags: [ws-memory, documentation, mcp, permissions, ai-agents, security]
---

> Translated from [`docs/03-mcp-gateway.md`](../03-mcp-gateway.md) (synced 2026-09-12).
> **The Polish version is authoritative.**

# MCP gateway

Status: **design**, not implemented (2026-09-12).

The backend exposes an MCP server over HTTP (JSON-RPC 2.0) at `/mcp` with a
**curated set of company tools** — it does not pass MemPalace's 36 tools
straight through (D-007). The tool boundary **is** the permission boundary.

## Protocol

`POST /mcp`, JSON-RPC 2.0, header `Authorization: Bearer <agent token>`.
Supported methods: `initialize`, `tools/list`, `tools/call`.

A client (Claude Code) is configured with a single command:

```bash
claude mcp add --transport http ws_memory https://wsmemory.twoja-domena.pl/mcp \
  --header "Authorization: Bearer $WS_MEMORY_TOKEN"
```

## Tools

### Reading

| Tool | Parameters | Returns |
|---|---|---|
| `ws_status` | — | who the token belongs to, which spaces, drawer and document counts |
| `ws_search` | `query`, `spaces?`, `kind?`, `limit?`, `since?` | semantic + lexical matches from spaces the token may reach |
| `ws_get` | `id` | full content of a drawer or document |
| `ws_doc_list` | `space?`, `query?`, `status?` | documents with metadata (author, verification, revision) |
| `ws_doc_read` | `space`, `slug`, `revision?` | document content; without `revision` — the current one |
| `ws_kg_query` | `subject?`, `predicate?`, `space?` | facts from the knowledge graph |

### Writing

| Tool | Parameters | Effect |
|---|---|---|
| `ws_remember` | `text`, `space?`, `kind?`, `tags?` | a drawer in the palace + a row in `ws.memory_entries` |
| `ws_doc_write` | `space`, `slug`, `title`, `content`, `change_note` | a new revision; creates the document if absent |
| `ws_kg_add` | `subject`, `predicate`, `object`, `space?` | a fact in the knowledge graph |
| `ws_diary_write` | `text`, `space?` | a session diary entry |
| `ws_propose` | `space`, `title`, `content` | an entry in the queue — only where `spaces.requires_proposal` |

What **does not exist and will not**:

- **`ws_doc_verify`** — verification is a human act in the interface. An agent
  does not confirm its own entries (D-005).
- **`ws_doc_delete`** — documents are archived, never deleted. Revision history
  never shrinks.
- **any administrative tool** — creating spaces, granting roles and issuing
  tokens belong to the human interface alone.

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
choosing a different entry point.

## Mapping onto MemPalace

| WS tool | MemPalace call | What the gateway adds |
|---|---|---|
| `ws_search` | `mempalace_search` | `wing IN (...)`, translation of `kind` → `room`, result filtering against `memory_entries` |
| `ws_get` | `mempalace_get_drawer` | verifies the drawer belongs to a permitted space |
| `ws_remember` | `mempalace_add_drawer` | the space's `wing`, author from the token, a row in `memory_entries` |
| `ws_kg_query` / `ws_kg_add` | `mempalace_kg_query` / `mempalace_kg_add` | space scope, author |
| `ws_diary_write` | `mempalace_diary_write` | assignment to a space and an author |
| `ws_doc_*` | — | SQL against `ws` only; publishing to the palace goes through `worker` |

The MemPalace token is known to the backend **alone**. An agent never sees it.

## Errors

Standard JSON-RPC codes, plus:

| Situation | Response |
|---|---|
| missing / wrong token | HTTP `401`, no JSON-RPC body |
| revoked or expired token | `401` + `WWW-Authenticate` header |
| space outside permissions | **empty result**, not an error (existence is not disclosed) |
| write to a space without the `writer` role | `-32003`, message naming the missing write permission |
| space requires the queue, `ws_doc_write` used | `-32004` with a hint to use `ws_propose` |
| MemPalace unreachable | `-32010`, "memory temporarily unavailable" |

The distinction between "empty result" and "no permission" is deliberate: the
message "you have no access to the *HR* space" is itself an information leak.
