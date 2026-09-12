---
tags: [ws-memory, documentation, plugin, claude-code, hooks, ai-agents, hybrid]
---

> Translated from [`docs/04-plugin.md`](../04-plugin.md) (synced 2026-09-12).
> **The Polish version is authoritative.**

# The WS_Memory plugin for Claude Code

Status: **design**, not implemented (2026-09-12).

The plugin is the only thing a user installs — and it **pulls in the MemPalace
plugin as a dependency**, so everyone ends up with a local palace (D-012).

**The plugin exists so that a copy of your knowledge reaches the server.**
Anyone who wants to work purely locally installs MemPalace alone and creates no
account — that is the proper way to opt out, not a setting (D-015).

The division of labour is therefore simple: **mining happens exclusively
locally** (projects, documents, conversation transcripts), and **the result
travels to the server by default** — no clicking, nothing to remember (D-014).
The server mines nothing and accepts no raw sources; it receives finished
drawers.

## Structure

```
plugin/
  shared/                    ← ONE source of content, independent of the AI client
    protokol-recall.md       ← search the base before you answer
    jak-dokumentowac.md      ← document structure, language, where things go
    agenci/                  ← subagent descriptions as Markdown
  .claude-plugin/
    plugin.json              ← dependencies, userConfig, MCP server
    marketplace.json         ← entry with the install command
    hooks.json               ← maps events onto a single script
    hooks/ws-hook.sh         ← ONE script: ws-hook.sh <event>
    skills/                  ← thin wrappers around content from shared/
    commands/                ← /ws-search /ws-doc /ws-status /ws-publish
    agents/                  ← subagents, content from shared/agenci/
  .codex-plugin/             ← created only once somebody actually uses Codex
```

The split is deliberate (D-013): **`shared/` is content, the rest is
packaging.** One hook script taking the event name instead of three separate
ones — exactly as in MemPalace's own Codex packaging. Porting to another AI
client is then a new manifest, not new code.

## Configuration: dependency, MCP and `userConfig`

Three Claude Code mechanisms carry this:

**`dependencies`** — the plugin declares that it requires the MemPalace plugin.
The user need not know MemPalace is underneath; they install one thing.

**`userConfig`** — the instance URL, the token and the `auto_publish` switch
(on by default) are asked for **when the plugin is enabled**, with the token
marked `sensitive`. The values reach the MCP configuration as
`${user_config.KEY}` and the hooks as `CLAUDE_PLUGIN_OPTION_*`. Nobody sets
environment variables by hand and **the token never enters the repository**.

**`source: {"type": "command"}`** in the marketplace entry — a command run
before installation. This is where the `mempalace` package is installed (with
the `extract` extra, so PDFs and DOCX files can be mined) and the first
`mempalace init` is performed.

```json
{
  "name": "ws-memory",
  "dependencies": ["mempalace"],
  "userConfig": {
    "url": {
      "type": "string",
      "title": "WS_Memory URL",
      "description": "e.g. https://wsmemory.twoja-domena.pl"
    },
    "token": {
      "type": "string",
      "title": "Agent token",
      "description": "Issue it in WS_Memory → Settings → Tokens",
      "sensitive": true
    },
    "auto_publish": {
      "type": "boolean",
      "title": "Send to the server automatically",
      "description": "On by default. Unmapped wings land in your private space.",
      "default": true
    }
  },
  "mcpServers": {
    "ws_memory": {
      "type": "http",
      "url": "${user_config.url}/mcp",
      "headers": { "Authorization": "Bearer ${user_config.token}" }
    }
  }
}
```

The `mempalace` MCP server (local, stdio) comes from the MemPalace plugin — we
do not configure it ourselves. The agent sees both at once.

## Hooks

All three are short `curl` scripts. No dependencies beyond what every system
already has.

**`session-start`** — calls `ws_status` and injects into the context: who the
user is, which spaces they have, what changed recently in the project they are
working on. The point: an agent starts a session knowing where knowledge lives
instead of guessing.

**`session-end`** — mining the transcript **into the local palace** (done by
the MemPalace hook, which we do not duplicate) plus, if the user has a mirror
configured, incremental publication of new drawers to the shared base. The raw
conversation never leaves the machine.

**`pre-compact`** — before the context is compacted, writes a summary of what
was decided via `ws_diary_write`. It rescues conclusions that would otherwise
evaporate along with the context.

### Privacy

Conversation transcripts stay **on the user's machine**, in their local palace.
Only what somebody publishes reaches the shared base — manually, or through a
mirror whose first run requires confirmation. There is no path by which a raw
conversation leaves for the server, so there is nothing to secure.

## Skills

**`ws-memory-recall`** — the recall protocol: **search the base before you
answer** about past decisions, people and projects. Never guess. Modelled on the
`mempalace-recall` skill, but it queries `ws_search` and therefore respects
permissions.

**`ws-memory-document`** — how to write company documentation: document
structure, language, where things belong (`documentation` vs `technical` vs
`decisions`), when to create a new document and when to add a revision. It
carries one rule: **if you write a document no human will verify, say so plainly
in the session summary.**

**`ws-memory-setup`** — walks through issuing a token in the interface and
configuring the client. Run once per machine.

## Subagents

| Agent | Task | When |
|---|---|---|
| **`ws-dokumentalista`** | writes up what a task produced into a canonical wiki document | after a task closes |
| **`ws-archiwista`** | reviews a space, finds duplicates and contradictions, proposes merges | periodically, on demand |
| **`ws-onboarding`** | answers a newcomer **solely** from the base, with links to sources; reports a missing answer as a gap | when onboarding someone |
| **`ws-recall`** | deep digging through the base before a decision: every earlier finding on the topic | before an architectural change |

`ws-onboarding` carries a deliberate restriction: **it must not answer from
general knowledge.** If the base holds no answer, it must say so and report the
documentation gap — otherwise a newcomer could not tell company practice from a
model's guess.

## How it works for the user

**Configuration:** two MCP servers at once — `mempalace` (local, stdio) and
`ws_memory` (shared, HTTP). The agent reads from both. The `ws-memory-recall`
skill imposes the order: **shared base first, local second** — team knowledge
takes precedence over private notes.

**Mining** you do yourself, locally, as before:

```bash
mempalace init ~/projects/new-project
mempalace mine ~/projects/new-project
```

Code never leaves the laptop. You need to ask nobody for anything.

**By default: automatic transfer.** Everything that reaches the local palace
travels to the server after a session ends or after local mining. Where it
lands:

| Local palace wing | Lands in |
|---|---|
| **mapped** to a team space | that space — the team sees it |
| **unmapped** | your **private space on the server** |

So everything is always on the server (backup, search, access from a second
machine), yet nothing becomes visible to others until you map the wing. You
confirm a mapping once — the mapping is what decides visibility.

When a local wing's name matches an existing team space, the plugin **proposes
the mapping**. Accepting is worthwhile: deduplication by content digest then
works, and the same repository mined by three people does not sit in the base in
triplicate.

**Working offline is fully supported.** The local palace is primary and the
server receives a copy, so a write never waits for the server and never fails
because of it. On a train, with the server down, mid-upgrade: you mine and write
normally, and unsent drawers wait in a queue and catch up on their own once
connectivity returns (D-015).

**Manual mode** — an emergency brake, not the main way of working. The
`auto_publish` switch in the plugin settings; then nothing leaves on its own and
you publish with `/ws-publish`: pick a wing, a topic or a date range, review the
preview, confirm. Useful when you deliberately do not want a particular piece of
work copied.

**Safeguards**, since the transfer runs unattended:

- **mapping onto a team space requires confirmation** — without it the content
  stays in your private space;
- **topic exclusions** in the mapping (e.g. a project wing without the diary);
- **a secret filter on both sides** — the client does not send, and the server
  checks anyway; since D-014 it sits on *every* path, which makes it critical;
- **a batch log** filterable by space, with **one-action undo** — you can always
  see what went where;
- **pause and off switches** — global and per mapping.

What the hybrid does not give you: a single query spanning both stores. They are
two indexes, so the agent asks twice.

## Portability to other AI clients

We build for Claude Code first, but the system is not tied to it (D-013).

**Portable with no work at all:** the gateway is an MCP server over HTTP. Codex
(`codex mcp add --transport http`), Cursor (`mcp.json`), Zed, Antigravity or
Copilot in VS Code will connect straight away. The `ws_*` tools, permissions,
tokens and audit behave identically, because **none of them lives in the
plugin**.

**Portable cheaply:** the instructions. Their content has one source in
`shared/` and is also exposed as **MCP resources**
(`ws-memory://protokol-recall`, `ws-memory://jak-dokumentowac`) and in tool
descriptions — which every MCP client reads. A valuable side effect: changing an
instruction means **deploying the server, not updating a plugin on everyone's
machine**.

**Non-portable and duplicated:** hooks, skills, commands, subagents. Looking at
MemPalace, which maintains four packagings at once, the cost is known:
`.codex-plugin` has the same `hooks.json` shape as Claude (SessionStart / Stop /
PreCompact) and differs in the path variable name; `.cursor-plugin` is just
`mcp.json`, because Cursor has no hooks. That is rewriting manifests, not logic.

Packagings for other clients are **not built ahead of need** — only once
somebody actually uses them.

## Installing on a developer machine

```bash
claude plugin marketplace add https://git.twoja-domena.pl/ws-memory-plugin
claude plugin install ws-memory
```

The installer pulls in the MemPalace plugin, installs the package and asks for
the URL and token (`userConfig`). No environment variables to set by hand.
