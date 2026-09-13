---
tags: [ws-memory, documentation, plugin, claude-code, hooks, ai-agents, hybrid]
---

> Translated from [`docs/04-plugin.md`](../04-plugin.md) (synced 2026-09-13).
> **The Polish version is authoritative.**

# The WS_Memory plugin for Claude Code

Status: **implemented** (2026-09-13, TODO-009). Version `0.1.0`.

The plugin is the only thing a user installs — and it **pulls in the MemPalace
plugin as a dependency**, so everyone ends up with a local palace (D-012).

**The plugin ultimately exists so that a copy of your knowledge reaches the
server** (D-015) — and it does not do that yet, see the callout below. Anyone who
wants to work purely locally installs MemPalace alone and creates no account;
that is the proper way to opt out, not a setting.

That makes the division of labour simple: **mining happens exclusively
locally** (projects, documents, conversation transcripts), and the server mines
nothing and accepts no raw sources.

> **What is not there yet.** Automatic publication from the local palace to the
> server (D-014) is `TODO-012` and is not implemented. The plugin therefore
> **has no** `session-end` hook and no `auto_publish` switch — it would control
> something that does not exist. What the plugin gives today: context at the
> start of a session, the `ws_*` tools, the instructions and the subagents —
> that is, **reading the shared base and writing to it directly**. Automatic
> copying of the local palace arrives in `TODO-012`. Details and reasons: D-034.

## Structure

```
.claude-plugin/
  marketplace.json           ← in the repository ROOT, points at ./plugin (D-033)
plugin/
  shared/                    ← ONE source of content, independent of the AI client
    protokol-recall.md       ← search the base before you answer
    jak-dokumentowac.md      ← document structure, language, where things go
    konfiguracja.md          ← token, local palace, diagnostics
    agenci/                  ← subagent descriptions
  .claude-plugin/
    plugin.json              ← dependencies, userConfig, MCP server
  hooks/
    hooks.json               ← maps events onto a single script (loaded on its own)
    ws-hook.sh               ← ONE script: ws-hook.sh <event>
  skills/*/SKILL.md          ← symlinks to files in shared/
  agents -> shared/agenci    ← a symlink to the DIRECTORY, not to files (see below)
  commands/                  ← /ws-search /ws-doc /ws-status
```

The split is deliberate (D-013): **`shared/` is content, the rest is
packaging.**

### The content exists once — literally

`plugin/skills/ws-memory-recall/SKILL.md` **is a symbolic link** to
`plugin/shared/protokol-recall.md`. There are no copies to keep in sync, because
there are no copies.

The validator says this about those files outright:

> "3 components here were not read — the path is not a regular file (a symlink…).
> **A session loading this plugin does follow them**, so validate the real paths
> separately."

So a session **does follow** the links, while the validator does not read them
and tells us to check the real paths separately. We run both checks.

**Subagents require a symlink to the directory, not to the files** — and that is
not a matter of taste but the result of a measurement. With four
`plugin/agents/*.md` symlinks, the plugin inventory showed `Agents (0)`: the
files **did not load at all, without any error**. Replacing one of them with a
regular file gave `Agents (1)`, and turning the whole of `plugin/agents/` into a
symlink to `shared/agenci/` gave `Agents (4)`. Skills do not have this problem:
there, symlinks to files load normally.

This is exactly the kind of fault a validator does not protect you from: nothing
breaks, half the plugin simply does not exist. The check is
`claude plugin details ws-memory@web-systems` and counting the components.

A cost worth naming: symbolic links in git require `core.symlinks=true` on
Windows. The team works on Linux.

### The same content as MCP resources

The instructions are also exposed by the gateway as MCP resources
(`ws-memory://protokol-recall`, `ws-memory://jak-dokumentowac`, …). **Every MCP
client** reads them, not only Claude Code, and changing an instruction is then
a server deployment rather than a plugin update on every person's machine
separately. Details: `docs/03-mcp-gateway.md`.

## Configuration: dependency, MCP and `userConfig`

**`dependencies: ["mempalace@mempalace"]`** — the plugin declares that it
requires the MemPalace plugin. The user installs one thing.

The name is **qualified with the marketplace**, and it has to be: with plain
`"mempalace"` the installer looked for the dependency in **its own** marketplace
and refused with the message `Dependency "mempalace@web-systems" is not
installed`. The `plugin@marketplace` form says where to take it from.

**`userConfig`** — the instance URL and the token are asked for **when the
plugin is enabled**, with the token marked `sensitive`. The values reach the MCP
configuration as `${user_config.KEY}` (inside headers as well) and the hooks as
`CLAUDE_PLUGIN_OPTION_*`. Nobody sets environment variables by hand and **the
token never enters the repository**.

```json
{
  "name": "ws-memory",
  "dependencies": ["mempalace@mempalace"],
  "userConfig": {
    "url":   { "type": "string", "title": "WS_Memory URL" },
    "token": { "type": "string", "title": "Agent token", "sensitive": true }
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

The `"hooks"` key is **deliberately absent** from the manifest.
`hooks/hooks.json` loads on its own, and declaring it on top of that is a
loading error: `Duplicate hooks file detected`. The field is there for pointing
at **additional** hook files.

The `mempalace` MCP server (local, stdio) comes from the MemPalace plugin — we
do not configure it ourselves. The agent sees both at once.

**The Python package is installed by a human.** The MemPalace plugin provides a
manifest, but the `mempalace` server is run by a package the plugin does not
install — and we do not pretend otherwise. `pip install "mempalace[extract]"`
plus `mempalace init` is described by the `ws-memory-setup` skill. Why this
cannot be automated with a marketplace entry: D-034.

## Hooks

**One script, the event name as an argument** — `ws-hook.sh <event>`. The
pattern comes from MemPalace's packaging for Codex: porting to another AI client
is to be a new manifest, not new code (D-013).

### `session-start` — the only hook there is

It calls `ws_status` and injects into the context: which spaces the token has
rights to, with what role, how many entries they hold, **where a write with no
space given will land**, and a reminder of the recall protocol. The point: an
agent starts a session knowing where knowledge lives, instead of discovering its
own permissions through failures.

The injection uses the documented shape
(`hookSpecificOutput.additionalContext`), because plain `stdout` goes to the log
rather than into the context.

**The hook never interrupts work.** A missing token, no network, a dead server,
an authentication error — the session starts normally, just without the injected
paragraph. The exit code is always zero, and the time limit is 5 seconds against
the hook's 10-second limit. Checked for each of those cases separately.

One thing here is subtle and was caught by a test: when the response filter
gives up (a JSON-RPC error, a response that cannot be parsed), the hook **must
not** inject an empty frame. An empty context looks to the agent like "the base
holds nothing", which means it tells an untruth.

### What the hook does not do

**It does not read the transcript and does not send a single byte of the
conversation.** The entire outgoing traffic is one `ws_status` call with no
arguments — 91 bytes. Checked by recording the traffic: the hook was run with a
substituted transcript and with input data containing control markers, after
which the whole request was captured:

```
POST /mcp
Content-Type: application/json
Authorization: Bearer [hidden]
Content-Length: 91

{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"ws_status","arguments":{}}}
```

None of the markers appeared in it.

Mining the transcript **into the local palace** is handled by the MemPalace
hook, which comes with the dependency — we do not duplicate it. Why there is no
`pre-compact` hook and no `session-end` hook: D-034.

## Skills

All three are symlinks to files in `shared/`.

**`ws-memory-recall`** — the knowledge recall protocol: **search the base before
you answer** about past findings, decisions, people and projects. It imposes an
order: **the shared base first (`ws_search`), then the local palace
(`mempalace_search`)** — a note is a record of somebody's thinking, a document
is a settled matter. It ends with an instruction to write the conclusions down
via `ws_diary_write`.

**`ws-memory-document`** — how to write company documentation: which of the
three classes of knowledge this is, how to name the address, what to write in
the change description, where it will land. It carries one rule: **if you write
a document no human will verify, say so plainly in the session summary.**

**`ws-memory-setup`** — the local palace, issuing a token, a table of symptoms
for diagnostics. Run once per machine.

## Subagents

| Agent | Task | When |
|---|---|---|
| **`ws-dokumentalista`** | writes up the **result** of a closed task into a canonical document | after a task closes |
| **`ws-archiwista`** | finds duplicates, contradictions and content that is out of date; **proposes**, does not execute | periodically, on demand |
| **`ws-onboarding`** | answers a newcomer **solely** from the base, with links to sources | when onboarding someone |
| **`ws-recall`** | deep digging before a decision, including **rejected alternatives** | before an architectural change |

`ws-onboarding` carries a deliberate restriction: **it must not answer from
general knowledge.** If the base holds no answer, it has to say so and name the
gap — listing the words it searched for. The reason is concrete: somebody who
knows the company will catch an invention, whereas **a newcomer will remember it
and repeat it as an established rule**.

The restriction is written into the prompt, **not into the list of allowed
tools**. A tool list is a list of permissions, and a misspelt MCP tool name
**silently drops it** instead of reporting an error — and it would not stop the
model from answering out of its own knowledge anyway, because that is not a
tool.

## Commands

| Command | What it does |
|---|---|
| `/ws-status` | who this token is: spaces, roles, where a write will land |
| `/ws-search <what>` | `ws_search` first, then `mempalace_search`, with sources given |
| `/ws-doc <what>` | check whether the document exists; then `ws_doc_write` by the rules |

`/ws-publish` will come into being together with publication (`TODO-012`).

## How it works for the user

**Configuration:** two MCP servers at once — `mempalace` (local, stdio) and
`ws_memory` (shared, HTTP). The agent reads from both, in the order imposed by
the `ws-memory-recall` skill.

**Mining** you do yourself, locally:

```bash
mempalace init ~/projekty/nowy-projekt
mempalace mine ~/projekty/nowy-projekt
```

Code never leaves the laptop. You need to ask nobody for anything.

**Working offline is fully supported.** The local palace is primary (D-015), and
when the server is unreachable the start-up hook simply stays silent.

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
`shared/` and is exposed as **MCP resources** as well — and every MCP client
reads those.

**Non-portable and duplicated:** the manifests, the event-to-hook mapping, the
skill wrappers. Packagings for other clients are **not built ahead of need** —
only once somebody actually uses them.

## Installing on a developer machine

```bash
pip install "mempalace[extract]" && mempalace init

claude plugin marketplace add MemPalace/mempalace
claude plugin marketplace add DragonKing026/Websystems --sparse .claude-plugin plugin
claude plugin install ws-memory
```

The MemPalace marketplace is added **separately**, because that is where the
dependency comes from. `--sparse` limits the download to the plugin's
directories — you do not pull the whole application in order to get the plugin
(D-033). The installer will ask for the URL and the token. No environment
variables to set by hand.

**The token enters neither the repository nor any file of the plugin.** Checked
after installation: it lands in `~/.claude/.credentials.json`, because
`userConfig` marks it as `sensitive`. All that appears in the project directory
is `.claude/settings.local.json` holding the plugin name and the path to the
marketplace — without the token, and ignored by git.

Checking whether it worked:

```bash
claude plugin details ws-memory@web-systems   # 3 skills, 4 subagents, 1 hook
claude mcp list                               # plugin:ws-memory:ws_memory … ✔ Connected
```

You issue a token in WS_Memory → **Settings → Agent tokens**. It is shown once.
A token issued for a one-off piece of work is revoked once that work is done.
