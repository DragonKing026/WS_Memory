---
tags: [ws-memory, documentation, backend, symfony, layers, api]
---

> Translated from [`docs/08-backend.md`](../08-backend.md) (synced 2026-09-13).
> **The Polish version is authoritative.**

# The backend — what each part is for

Status: **working** (2026-09-12). Implemented: health, invitations, accounts,
sign-in, spaces and roles, auditing, access to memory (search, writing, the
knowledge graph, the diary), the MCP gateway with agent tokens and **the wiki with
revisions, rollback and the review queue**. Missing: the frontend (TODO-006…008),
publishing from local palaces (TODO-012).

This document describes **every part of the backend and why it exists**. If you
do not know where a new thing belongs, start here.

## Layers and the direction of dependencies

```
Presentation/    ← entry points: HTTP and console. They translate a request into a call.
Application/     ← use cases: "issue an invitation", "accept one".
Domain/          ← rules. NO Symfony, NO Doctrine, NO MemPalace.
Infrastructure/  ← port implementations: Doctrine, HTTP, MemPalace.
Entity/          ← the persistence model (Doctrine). Table mapping, not rules.
```

Dependencies point **inwards only**. `Domain` imports nothing from the other
layers — which is what lets permission rules be tested without a database and
without a container, so the negative tests run on every commit in a fraction of
a second.

The test: *if Doctrine or MemPalace had to be replaced tomorrow, how many files
in `Domain/` would need touching?* The answer must be "zero".

**Why `Entity/` sits apart rather than inside `Domain/`:** Doctrine entities
carry mapping attributes, i.e. they know about the database. Keeping them in
`Domain/` would soil a layer that is meant to be clean. Keeping a separate
domain model alongside the entities would mean hand-copying in both directions —
a cost out of proportion to this project's size. The compromise: entities are
the **persistence model**, and the rules live in `Domain/`.

## File map

### `Domain/` — rules, no framework

| File | Role |
|---|---|
| `Identity/Actor.php` | Who is performing an operation: a person or an agent token. **One type for both**, because REST and MCP call the same services — were identity modelled twice, the two surfaces would eventually differ on permissions, and the difference would surface as a leak rather than an error. |
| `Space/SpaceId.php` | A space identifier as a value object. A string can be confused with another string; this type cannot. |
| `Space/SpaceRole.php` | A role in a space: `reader` / `writer` / `admin`. Ordered by strength, so "at least a writer" is a comparison rather than a list of cases somebody forgets to extend when a fourth role appears. |
| `Space/SpaceMembershipRepository.php` | **Port**: where memberships come from. Declared in the domain, implemented in infrastructure. |
| `Space/SpaceAccessResolver.php` | **The single place that computes permissions.** Every read and write goes through it. It holds no cache — a revoked role stops working at once, not when something expires. |
| `Space/SpaceCatalog.php` | **Port**: what a space is (its palace wing, whether it is private) — as opposed to who may enter it. Kept apart from memberships because one is read on the permission path of every request, the other only once a space is already known to be allowed. |
| `Memory/PalaceWing.php` | A wing name — **the only axis memory can be filtered on**. The type refuses an empty value, so a query without a space filter (rule 3) is inexpressible rather than merely forbidden. It also owns the rule for qualifying graph entity names (D-021). |
| `Memory/DrawerId.php` | The identifier of content in the palace. `forFact()` derives a stable fingerprint for a graph fact, because the palace returns no identifier for facts — derived in one place, because the write side and the read side must agree on it exactly. |
| `Memory/MemoryKind.php` | The class of knowledge (`note` / `document` / `diary` / `kg_fact` / `transcript`) and **the only place mapping it to a palace room**. A divergence between writing and reading would not fail — it would file content where nobody looks for it. |
| `Memory/MemoryQuery.php` | What to look for — and deliberately **not** where. An object that could also carry a wing would let one arrive from a request body. |
| `Memory/MemoryFragment.php` | One piece of content coming back from memory. An empty `space` means "not authorised yet" — the service refuses to hand out a fragment in that state. |
| `Memory/KnowledgeFact.php` | A graph triple with a validity window. The fingerprint ignores the window: extending a fact's validity must not change its identity. |
| `Memory/MemoryStore.php` | **Port**: the memory engine. `search()` takes a `PalaceWing` as its first, non-optional argument — that is where the filter guarantee comes from. One wing per call, because the palace filters by one. |
| `Memory/MemoryRegistry.php` | **Port**: our own register of content in the palace. It also draws the transaction boundary — a write is real once it is booked (D-020). |
| `Memory/MemoryWrite.php` | The row to be booked. A parameter object, because the list will grow when publishing from local palaces lands (TODO-012). Derives the title and the content digest in one place. |
| `Memory/SearchMode.php` | Semantic or lexical. Not "better and worse" but two different questions — and since the palace has no lexical mode (D-029), this type is also the boundary between two data sources with different coverage. |
| `Memory/LexicalIndex.php` | **Port**: exact-term search over the text we hold ourselves. Deliberately **not** a second `MemoryStore`: it answers from our own tables and sees less — full content for documents only. The list of spaces is a mandatory argument, exactly as the wing is in `MemoryStore`. |
| `Memory/MemoryBrowser.php` | **Port**: what is in memory, newest first — without asking a question. Separate from search because it answers something else: not 'does anybody know about X' but 'what has been going into memory lately'. That is how somebody notices an agent filing nonsense. Answered from our registry alone; asking the palace would mean fetching content in order to show a title. |
| `Memory/MemoryEntryView.php` | One row of raw memory in a listing. Deliberately **not** a `SearchHit`: a search result exists in answer to a question and carries a relevance, while a listing answers none. An invented number on screen is worse than no number. |
| `Memory/EntryFacts.php` | What we know about a drawer that the palace does not: who wrote it (person or agent) and whether anybody has checked it. In a base half-written by agents, that is the difference between a result a reader can weigh and one they must take on faith. |
| `Search/SearchHit.php` | One result as a person reads it. A separate type from `MemoryFragment` because it carries authorship and verification, and its identifier is **optional**: a document is searchable from the moment it is saved, while the worker files its drawer a moment later. `score` is comparable only within one mode — the scales differ. |
| `Search/Snippet.php`, `Search/SnippetPart.php` | The snippet with its matches marked **as structure, not as markup**. Content written by people and agents can contain any HTML; a marked-up string rendered in a browser is stored XSS. Structure cannot be injected into. |
| `Memory/MemoryUnavailable.php` | "I could not look", kept separate from "I found nothing". An agent told "there is nothing" writes the knowledge again, next to the copy it could not see. |
| `Document/DocumentSlug.php` | A document's address as a value object. It **refuses** a malformed one rather than tidying it: somebody linking to "Umowy Najmu" and getting a document at "umowy-najmu" has a broken link they cannot see. `fromTitle()` offers a suggestion when asked. |
| `Document/DocumentStatus.php` | `draft` / `published`. **Not** a review gate — an agent's document is visible at once (D-005); draft is the state of a person who has not finished. |
| `Document/ProposalStatus.php` | The state of a queue entry. The queue is active only where a space asks for it. |
| `Document/RevisionDiff.php` | The difference between two revisions, computed on demand (line-based LCS). Not stored, because revisions hold complete content — a stored diff would be a second representation of one fact, and two representations eventually disagree. Capped at 5000 lines: the LCS table is O(n·m). |
| `Memory/StoredMemory.php` | Where a write ended up: drawer, space, kind. A write returns this rather than a bare identifier, because a caller that named no space cannot otherwise learn where its content went (rule 6). |
| `Identity/AgentIdentity.php` | Who a presented token turns out to be: the actor plus a label. Both at once, because an MCP request needs both and one query is cheaper than two on the path of every call. |
| `Identity/AgentTokenDirectory.php` | **Port**: turning a secret into an identity and recording its use. In the domain, because both halves are rules: a revoked token must stop working at the **next** call, and every call must leave a trace. |
| `Memory/MemoryAccessDenied.php` | A refused **write**. A read outside one's permissions answers empty, because "you have no access to HR" is itself a disclosure. |
| `Audit/AuditTrail.php` | **Port**: recording who did what. In the domain, because auditing is a rule of this system rather than an infrastructure convenience (D-016). |
| `Health/*` | The `HealthProbe` port plus `HealthChecker` assembling a report. Monitoring another dependency means adding a class, not editing a controller. |

### `Application/` — use cases

| File | Role |
|---|---|
| `Invitation/IssueInvitation.php` | Issues an invitation. The token is random and the database holds **only its hash**; the plain value is returned once and never stored. |
| `Invitation/AcceptInvitation.php` | Turns an invitation into an account **together with its private space, in one transaction**. A write naming no space lands there (rule 6), so an account without one would break an agent's very first write. |
| `Invitation/IssuedInvitation.php` | The result object carrying the plain token. **Not a service** — excluded from the container. |
| `AgentToken/IssueAgentToken.php` | Issues an agent credential. Random secret, hashed in the database, plain value once. Checks the requested scope against the owner's **current** permissions — not because that enforces rule 4 (the resolver intersects on every request), but so a mistake is reported now, to a person who can fix it, rather than becoming a token that reads nothing and cannot be debugged from the agent's side. |
| `AgentToken/RevokeAgentToken.php` | Revokes — immediately, and only one's own, global administrators included. Somebody else's token answers identically to one that does not exist, or credentials could be enumerated. |
| `AgentToken/IssuedAgentToken.php` | The result carrying the plain token and a ready `claude mcp add`. **Not a service.** |
| `Document/DocumentService.php` | **The only way into the wiki.** A document in a space the actor cannot read is NOT FOUND, never forbidden (rule 7). `verify()` takes a `User`, not an `Actor` — an agent cannot be passed (D-005). `rollback()` **adds** a revision holding the old content; history never shrinks. Publication is dispatched after the `flush`, not before: a job for a revision that failed to save would have the worker publishing content nobody can read. |
| `Document/ProposalService.php` | The review queue. Submitting needs the **reader** role (D-026); accepting goes **through `DocumentService`** — a second path into the wiki would eventually skip the permission check, the publication or the audit entry. |
| `Document/PublishDocument.php` | The job "publish revision N". It carries the revision number, and that is exactly what makes it safe in any delivery order. |
| `Document/PublishDocumentHandler.php` | Idempotent and order-proof: a job older than the current revision is **dropped** (D-025). The palace copy is authored by the revision's author rather than "the worker" — for an agent the owner comes from `AgentTokenDirectory::ownerOf()`. |
| `Document/DocumentNotFound.php`, `Document/ProposalRequired.php` | One exception for "no such thing" and "not yours"; the other **names the way through**, because an error saying only "denied" would have an agent retrying the same call. |
| `Memory/MemoryService.php` | **The only way into memory.** REST and MCP call this class and nothing below it, so choosing a different door cannot get you a different answer (D-008). This is where the fan-out across wings lives, the re-ranking, the second filtering layer (D-019), the private-space default (rule 6) and the author label. Nothing above this layer may hold a `MemoryStore`. |
| `Search/SearchService.php` | Search as a person reads it: one call, two modes. It does **not** decide permissions — it calls `MemoryService` for both modes so that no second place works out which spaces an actor may read. What it adds is what only a human needs: the author, the verification, and the **weak-match cut**, computed relative to the best hit in that answer, because a constant does not separate the measured bands. |

### `Infrastructure/` — port adapters

| File | Role |
|---|---|
| `Doctrine/DoctrineSpaceMembershipRepository.php` | Reads memberships **through DBAL, not the ORM**. This query sits on the permission path of every request, and hydrating entities would put the identity map between a revoked role and its effect — exactly the cache the resolver promises not to have. |
| `Doctrine/DoctrineAuditTrail.php` | Writes audit entries. Takes the IP and user agent from the current request rather than from parameters — as parameters, some call sites would forget them, and an entry without provenance answers half the question it exists for. |
| `Doctrine/DatabaseHealthProbe.php` | Probe: does the database respond. |
| `Doctrine/DoctrineMemoryRegistry.php` | The registry on DBAL. Resolves the space by slug **inside the INSERT** — a separate SELECT would open a window in which the space disappears between the check and the write. It also holds the transaction boundary. |
| `Doctrine/DoctrineSpaceCatalog.php` | A space's wing and a user's private space. The private one is checked **by the slug convention AND by the flag** — a space hand-named `priv_<uuid>` without the flag must not become the place somebody else's writes land in. |
| `Doctrine/DoctrineMemoryBrowser.php` | The registry read as a listing. Ordered by `(space_id, created_at)` — the index from Version20260912000003 — read backwards; a btree scans in reverse just as cheaply, which is why no descending copy exists. |
| `Doctrine/DoctrineLexicalIndex.php` | Exact-term search on PostgreSQL full text. Two things here are non-obvious and both were measured: the query is tokenised by **the same parser** as the content (`simple` reads `D-029` as `d` and `-029`, so a hand-rolled tokeniser finds nothing), and both hit sets start **from the text predicate** — written "from documents", the query does not use the GIN index and reads every revision: 157 ms against 2 ms over 10 000 documents. |
| `MemPalace/MemPalaceClient.php` | A thin JSON-RPC client. Two non-obvious behaviours: it **retries reads only** (a repeated write files a second drawer) and it **never lets the token into an error message** — that is how secrets most often escape. |
| `MemPalace/CallOutcome.php` | The result of one tool call. It exists because MemPalace reports failure **inside** the payload: HTTP 200, no error in the envelope, and the cause next to an empty result list. It also separates "no such drawer" from an outage. |
| `MemPalace/McpMemoryStore.php` | The memory port's adapter — **the only place that knows MemPalace tool names** and the shape of their answers. Upgrading the palace (D-001) touches this file and no other. |
| `MemPalace/MemPalaceUnavailable.php` | One failure for many causes: a refused connection, a timeout, an HTTP 500, a malformed envelope, a tool error. The caller's options are the same in every case. |
| `Doctrine/DoctrineAgentTokenDirectory.php` | Tokens on DBAL. Revocation, expiry **and the owner still being active** checked in ONE statement — no window in which three separate checks could disagree, and no path on which somebody adds a caller that forgets the third. |
| `Doctrine/DoctrineAuditTrail.php` | Writes **immediately**, through DBAL. It used to `persist()` and wait for somebody else's `flush` — and an MCP call changes no entities, so every agent action went unrecorded (D-024). |
| `Security/AgentTokenAuthenticator.php` | Authenticates `/mcp` with an agent token. Puts the resolved identity on the request as an attribute: the Symfony token carries the owner, and the owner is not the whole answer — an agent is its owner **narrowed**, and the narrowing lives on the credential. |
| `MemPalace/MemPalaceHealthProbe.php` | Probe: does memory respond. Queries `/healthz` with a short timeout — a hanging health check is worse than a negative one. |
| `Security/ActiveAccountChecker.php` | Refuses inactive accounts — at sign-in **and on every subsequent request**. A JWT stays cryptographically valid until it expires, so without this a dismissed person would keep reading the base for the lifetime of their last token. |
| `Security/LoginAuditSubscriber.php` | Sign-in auditing. Hung off security events because the login controller **never executes** — the firewall answers first. |

### `Presentation/` — entry points

| Route / command | File | Note |
|---|---|---|
| `GET /api/health` | `Api/HealthController.php` | Unauthenticated: monitoring carries no token and must not need one. `200` when healthy, `503` when not — Docker reads the code, not the body. |
| `POST /api/login` | `Api/LoginController.php` | **The body never executes.** The route exists because Symfony must resolve `check_path`; the `json_login` firewall answers. |
| `GET /api/me` | `Api/MeController.php` | The frontend's entry into the permission model. The space list comes from `SpaceAccessResolver`, not from a query of its own. |
| `GET /api/spaces`, `GET /api/spaces/{slug}` | `Api/SpaceController.php` | A space outside your permissions answers **byte for byte** like one that does not exist. |
| `POST /api/spaces`, `POST /api/spaces/{slug}/members` | `Api/SpaceAdministrationController.php` | Creating spaces and granting roles. The creator becomes its administrator at once; the `priv_` prefix is reserved; a private space cannot be shared. |
| `POST /api/invitations/accept` | `Api/AcceptInvitationController.php` | Public by necessity — the caller has no account yet. The password policy is enforced here, not in the browser. |
| `GET /api/memory` | `Api/MemoryController.php` | Raw memory, browsable, kept apart from the wiki: the wiki is what the team **decided** to write down, this is what was **noticed**. Mixed into one list, the second would bury the first. Paged from the start — the registry is the fastest-growing table in the system. |
| `GET /api/search` | `Api/SearchController.php` | Both modes, filters, and two things a plain result list would not do: **weak hits travel separately** (semantic search always answers, only progressively worse) and the answer **states its own coverage** — lexical mode admits that it searches full content in documents only. |
| `GET /api/spaces/{s}/documents`, `GET/PUT .../{slug}`, `.../history`, `.../diff`, `.../rollback`, `.../verify`, `.../archive` | `Api/DocumentController.php` | The routes use `{slug<.+>}`, because an address may contain a slash — without it "umowy/najem" would be unreachable. The mapping from refusals to HTTP lives in one place, because that is where a mistake becomes a disclosure. |
| `GET/POST /api/spaces/{s}/proposals`, `POST /api/proposals/{id}/accept`, `/reject` | `Api/ProposalController.php` | Review is a human act, so there is no MCP counterpart (D-005). |
| `GET/POST /api/agent-tokens`, `DELETE /api/agent-tokens/{id}` | `Api/AgentTokenController.php` | One's **own** tokens only, global administrators included (D-016). The plain value appears in one response — the one that created it. |
| `ws:agent:token` | `Console/IssueAgentTokenCommand.php` | The only route to connecting an agent until the screens exist (TODO-008). Prints a ready `claude mcp add`, because the alternative is everybody reconstructing it from the documentation and getting the header wrong. |
| `ws:user:invite` | `Console/InviteUserCommand.php` | The only route to the first account. It prints the link, because the first invitation is usually issued before the mailer is configured. |
| `ws:agent:list` | `Console/ListAgentTokensCommand.php` | The identifiers, labels, last use and state of an account's tokens — never a token itself, since only its hash is stored. **A separate command** from revoking: one that reads with a flag and destroys without it is a single typo away from taking an agent offline. |
| `ws:agent:revoke` | `Console/RevokeAgentTokenCommand.php` | Calls `RevokeAgentToken` — the same use case as `DELETE /api/agent-tokens/{id}`. A second revocation path (until now it was an `UPDATE` straight against the database) skips the audit trail and the "only your own token" rule. Somebody else's token answers exactly like one that does not exist. |

Three commands take an e-mail address as their first argument, and
`Console/AccountLookup.php` resolves it — one place for normalising the address
and one message for an account that is not there. Written out at each of them,
the normalisation is what would drift: an address typed with a capital letter
would work in one command and not in the next, and nobody would suspect the
lookup.

### `Presentation/Mcp/` — the gateway for agents

| File | Role |
|---|---|
| `McpController.php` | `POST /mcp`. HTTP only: the body, the status code, recording the token's use and **the rate limit**. The limit is here rather than in the decorator chain because it is per token and per request — a decorator would have to be told the same thing seven times. |
| `McpServer.php` | The JSON-RPC side: `initialize`, `tools/list`, `tools/call`, `ping`, notifications. Translates every failure into a code from `docs/03`. Testable by handing it an array. |
| `McpTool.php` | **The tool port.** The set of tools IS the permission boundary (D-007). Adding a tool is adding a class — the tag collects it into the registry. **None has an author parameter**, and a test walks every schema to keep it that way. |
| `McpToolRegistry.php` | Collects tools by tag and wraps each in auditing **on the way in**. The whole chain around a call is visible in one class instead of scattered across container decorators. Refuses two identical names and any name without the `ws_` prefix. |
| `AuditedTool.php` + `AuditedToolFactory.php` | Decorator: every call leaves a trace, successful or not. It deliberately overlaps with `MemoryService`'s entry — this one says which **tool** a given **token** invoked, and it exists for calls that never reach memory or that fail before it. |
| `ToolArguments.php` | Typed access to whatever an agent sent. The method that matters is `rejectUnknown()`: an unknown parameter is an **error**, because the parameter an agent is most likely to invent is `wing` — and ignoring it would let the agent believe it had narrowed its search. |
| `McpError.php` | JSON-RPC codes, ours above `-32000`. What is **not** here: a "space forbidden" code — a read outside one's permissions returns an empty result (rule 7). |
| `Tool/StatusTool.php` | `ws_status` — what the token is, what it sees, **where an unaddressed write will land**. Counts from our registry, never from the palace: an agent orienting itself must not cost a semantic query. |
| `Tool/SearchTool.php` | `ws_search`. The schema offers no way to name a wing; `spaces` can only narrow. |
| `Tool/GetTool.php` | `ws_get`. `found: false` for forbidden **and** missing content alike. |
| `Tool/KgQueryTool.php` | `ws_kg_query`. Entity names are wing-qualified in storage and bare here (D-021). |
| `Tool/RememberTool.php` | `ws_remember`. No author parameter **and no `kind`** — see `docs/03`. |
| `Tool/KgAddTool.php` | `ws_kg_add`. A fact belongs to one space and is invisible from others; the description says so outright, so an agent does not write it twice. |
| `Tool/DocListTool.php` | `ws_doc_list`. Separate from `ws_search` because the questions differ: "what do we know about X" versus "what documents are there". Every row carries `verified` and `authored_by_ai`. |
| `Tool/DocReadTool.php` | `ws_doc_read`. `found: false` for a forbidden document and a missing one alike. |
| `Tool/DocWriteTool.php` | `ws_doc_write`. Writes directly (D-005); in a queued space it answers `-32004` and **names** `ws_propose`. |
| `Tool/ProposeTool.php` | `ws_propose`. Needs the reader role; answers `in_wiki: false` so an agent does not report a publication that never happened. |
| `Tool/DiaryWriteTool.php` | `ws_diary_write`. Private by default — session notes are the content people most expect to be theirs. |

### `Entity/` — the persistence model

| Entity | Design notes |
|---|---|
| `User` | Accounts are **deactivated, never deleted** — revisions and audit entries point at their author, and history that loses its author stops being evidence. `ROLE_USER` is implicit and not stored. |
| `Space` | `Space::privateFor()` creates the private space `priv_<uuid>` — the slug convention itself lives in `SpaceId::privateFor()`, so writing and looking up cannot drift apart. A non-null `palace_namespace` means separate pgvector tables for sensitive spaces. |
| `SpaceMember` | A **composite key** `(space, user)`: two roles for one person in one space are unrepresentable, so "which one wins" cannot be asked. |
| `Invitation` | Only the token hash. `isUsable()` combines "unused" and "unexpired" in one place, so a second entry point cannot forget one of them. |
| `AuditLog` | Append-only. The actor is stored as a **plain identifier, not a foreign key** — deactivating an account never touches the record of what it did. |

## How a request flows

### Signing in

1. `POST /api/login` → the `json_login` firewall intercepts; the controller does
   not run.
2. The `app_users` provider finds the account by e-mail, the hasher verifies the
   password.
3. Success → `LoginSuccessEvent` → `LoginAuditSubscriber` records `user.login`
   and the last-login stamp; Lexik returns a JWT.

   **The subscriber checks which firewall the event came from**, and that is not
   precautionary tidiness. Every firewall is stateless, so `api` authenticates the
   token on **every request** and dispatches the same event each time; `mcp` does the
   same for agent tokens. Without that guard, one person clicking around the
   application wrote a "sign-in" row per HTTP request — the first time the audit
   screen was opened it held 40 889 entries, 20 335 of which described sign-ins that
   never happened. An audit trail whose majority is fiction is worse than a short
   one: the real entries are in there, and nobody will find them.
4. Failure → `LoginFailureEvent` → a `user.login_failed` entry **with no actor**:
   at that point we have a claimed identity, not a confirmed one, so recording
   it would let anyone forge audit entries using somebody else's address.

An unknown account and a wrong password answer identically — otherwise the login
form would double as a way to check who works here.

**The general rule, from the same lesson: an action that changed nothing writes no
audit entry.** Granting somebody the role they already hold succeeds — repeating a
request is not an error — but **records no row**. An entry reading "role changed
from admin to admin" describes a change that did not happen, and it spoils the
journal in exactly the way the fictitious sign-ins did, only more slowly.

### Reading a space

1. The `api` firewall verifies the JWT and loads the user **from the database on
   every request**.
2. The controller builds an `Actor` and asks `SpaceAccessResolver`.
3. The resolver reads roles through DBAL — no cache, so revoking a role takes
   effect immediately.
4. **Permission is checked before existence.** The other order would make the
   response time differ between "absent" and "forbidden", and a slower answer is
   a disclosure too.

### Invitation

`ws:user:invite` or the administrative endpoint → `IssueInvitation` (hash in the
database, plain token once on the way out) → the person opens the link →
`POST /api/invitations/accept` → password validation → `AcceptInvitation`
creates the account, the private space and the membership in one transaction →
an `invitation.accepted` entry.

In the same transaction the account **joins the shared team space**
(`SharedSpaceForEveryone`, D-035). By default `wiedza` with the `writer` role;
the configuration is `WS_DEFAULT_SPACE_SLUG`, `WS_DEFAULT_SPACE_NAME`,
`WS_DEFAULT_SPACE_ROLE`, and an empty slug switches the mechanism off. The space
is created along with the first account if it does not exist yet.

Two things here are not obvious, and both are deliberate:

- **The `space.member_added` audit entry has no actor.** Nobody granted this. The
  new account as the actor would read as "let itself in", and the inviter is
  sometimes unknown, because an invitation issued from the console has none. The
  `target` holds `reason: default_space`.
- **Nothing is flushed here.** An earlier version saved the new space straight
  away, in order to catch a key collision and re-read somebody else's row. That
  cannot be done: Doctrine **closes** the EntityManager after a failed `flush`, so
  the rescue path was already operating on a closed manager and the account was
  left half-created. Several dozen tests reported this at once. The space is
  therefore only `persist`-ed and goes out in a single `flush` together with the
  account; a genuine race (two invitations accepted in the same second on an
  instance without that space) is rejected by the unique index, and the person
  retries.

### Searching memory

1. The caller (REST or MCP) builds an `Actor` and a `MemoryQuery`. **The query
   has no "space" field on the palace side** — it can only narrow a list that we
   intersect with permissions anyway.
2. `MemoryService` computes the permitted spaces via `SpaceAccessResolver`. An
   empty intersection → **an empty answer and zero palace calls**. That is not an
   optimisation: this is exactly where a shortcut like "no spaces, so no filter"
   would turn into a query across the whole base.
3. For each permitted space `SpaceCatalog` supplies the wing, and
   `McpMemoryStore` issues **one `mempalace_search` per wing**. The palace filters
   by a single wing, not by a list — hence the fan-out.
4. Results are **re-ranked** and cut to the limit. Concatenating without
   re-ranking would return the best N from whichever space answered first.
5. **The second layer:** the registry says which space each drawer sits in.
   Anything it does not know, or places elsewhere, is dropped (D-019).
6. An audit entry: the query, the spaces, the number of results.

### Lexical search

1. `SearchService` branches on the mode and, for lexical, calls
   `MemoryService::searchLexically()` — **through the same permission path** as the
   semantic one. What differs is where the text is; who may see it is decided
   identically.
2. `DoctrineLexicalIndex` receives a **non-empty** list of spaces. An empty one would
   be indistinguishable from "no filter", so the port forbids it by type and the
   adapter checks at runtime — a PHPDoc type is not enforced.
3. The user's query is tokenised by `to_tsvector`, and a `to_tsquery` is rebuilt from
   the resulting lexemes. **Only the last word gets `:*`** — the one being typed.
   `quote_literal` makes it impossible for input to be read as query syntax.
4. Two hit sets: documents by the full content of the current revision, everything else
   by title and tags. Both **start from the text predicate**, so the planner reaches for
   the GIN index.
5. `ts_headline` runs **after** the limit — it reparses the whole document, so running
   it on every match would be waste.
6. An audit entry: query, mode, spaces, number of results.

### Writing to memory

1. The target space: the one named, or **the owner's private space** (rule 6). A
   missing private space means a broken account rather than a missing argument —
   `AcceptInvitation` creates it in the same transaction as the account.
2. `SpaceAccessResolver` checks the **write** permission. A refusal is an
   exception, not an empty answer: the agent named the space itself, so it
   already knows the space exists.
3. The registry transaction opens. Inside it: `mempalace_add_drawer` → a row in
   `ws.memory_entries` → an audit entry → commit.
4. A failing palace rolls back a transaction that held nothing yet. Failing
   bookkeeping rolls back the row and **leaves the drawer in the palace** — a
   deliberately chosen direction of failure (D-020), because a drawer with no row
   is invisible, while a row with no drawer would be a result nobody can open.

### An MCP tool call

1. The `mcp` firewall reads `Authorization: Bearer`, resolves the token in **one
   statement** (revocation, expiry, the owner being active) and puts the identity on
   the request. Every failure answers the same `401` — the difference helps only
   somebody guessing tokens.
2. The controller records the use and gets back the number of calls in the window.
   Over the limit → `429` and `-32005`, without touching the tool.
3. `McpServer` validates the JSON-RPC envelope and picks the method. A batch request
   is refused: it would count as one call while doing twenty.
4. `McpToolRegistry` hands over the tool **already wrapped in auditing**.
5. The tool validates its arguments (`ToolArguments`) and calls `MemoryService` —
   never the palace directly. Going around the service would skip both filters
   (D-019) and the bookkeeping (D-020).
6. The result travels as an MCP text part with JSON inside. A failure travels as a
   **JSON-RPC error**, not a success with an error in its payload (D-023).

### Writing a document in the wiki

1. `DocumentService` checks the **write** permission. A queued space refuses an
   agent's write and **names** `ws_propose` (`-32004`); a person writes there
   directly, because they are the reviewer — putting them in their own queue would
   leave nobody to empty it.
2. `Document::addRevision()` assigns the number, moves the current pointer, copies the
   title, sets the AI flag and **clears the verification**.
3. An audit entry, the `flush`, and **then** the publish job. In that order, because a
   job for a revision that failed to save would have the worker publishing content
   nobody can read.
4. The `worker` picks the job up. If the document already has a newer revision it
   **drops it** (D-025). Otherwise it updates the document's single drawer in place.
5. From then on the document is findable semantically, and the old text is not.

## Permissions end to end

Six layers, each covered by a negative test:

1. **The firewall** — without a valid JWT there is no access to `/api` beyond
   health, sign-in, invitation acceptance and the contract documentation. `/mcp`
   has a firewall of its own for agent tokens, declared before the JWT one.
2. **`SpaceAccessResolver`** — the single place computing roles. An agent
   token's scope **narrows and never widens** its owner's permissions.
3. **The controller** — checks permission before existence and returns `404`
   where `403` would disclose that a resource exists.
4. **Auditing** — every state change leaves an entry with the actor, IP and user
   agent.
5. **Memory — two filters, not one.** The wing narrows the question put to the
   palace, the registry checks the answer (D-019). The first guards against a
   mistake in our query, the second against drift between two stores.
6. **The MCP tool set** — what is not in `tools/list` an agent cannot do. No tool
   accepts an author or a wing, so impersonation and bypassing the filter are
   **inexpressible**, not merely forbidden.

A global administrator **does not silently read other people's spaces** (D-016).
They may grant themselves a role — and that stays in the log.

## Migrations

Written **by hand**, not generated: with `schema_filter` in place the diff
generator needs DBAL `^4.5` while the stable release is 4.4.4. Mapping is checked
with `doctrine:schema:validate --skip-sync`.

| Migration | Contents |
|---|---|
| `Version20260912000001` | The Messenger queue in `ws`, with a `LISTEN/NOTIFY` trigger. |
| `Version20260912000002` | Accounts, invitations, spaces, roles, the audit log. |
| `Version20260912000003` | The register of content in the palace (`ws.memory_entries`). |
| `Version20260912000004` | AI agent tokens (`ws.agent_tokens`) with the rate-limit counter. |
| `Version20260912000005` | The wiki: documents, revisions, the review queue; the `memory_entries.document_id` foreign key deferred from TODO-003. |

**The `schema_filter` trap** — described in `docs/en/05-deployment.md`. In short:
a `~^ws\.~` filter rejects our own tables, because with `search_path = ws` DBAL
returns them unqualified. The correct pattern **excludes** `palace`.

## Tests

| File | What it checks |
|---|---|
| `Domain/Space/SpaceAccessResolverTest.php` | Permission rules **without a database** — the fastest and the most important. Five of nine cases are negative. |
| `Application/InvitationFlowTest.php` | The whole invitation flow against a real database: the private space, a single-use token, an expired one, an unknown one, the absence of the plain token in storage, the audit entry. |
| `Api/AuthenticationTest.php` | Sign-in, `/api/me`, identical refusal for a wrong password and an unknown account, auditing. |
| `Api/SpaceAccessTest.php` | `404` instead of `403`, identical responses, a list containing only your own spaces, the immediate effect of revoking a role. |
| `Api/SpaceAdministrationTest.php` | Who **cannot**: a writer does not promote themselves, a stranger gets `404`, a private space cannot be shared. |
| `Application/Memory/MemoryServiceTest.php` | Memory **without a palace and without a database**: an empty intersection of spaces never asks the palace, a drawer from a foreign space is dropped, an unknown drawer answers identically to a forbidden one, failed bookkeeping rolls the write back. The doubles are hand-written so the test can read the traffic to the palace rather than verify that a method was called. |
| `Infrastructure/MemPalace/MemPalaceClientTest.php` | The wire: an error in the payload rather than the envelope, HTTP 5xx, an unparseable body, a retried read, **a write that is not retried**, no token in an error message. |
| `Infrastructure/MemPalace/McpMemoryStoreTest.php` | The translation into MemPalace's dialect, asserted against the fields a live 3.7.0 palace **actually** returns. |
| `Infrastructure/Doctrine/DoctrineMemoryRegistryTest.php` | What a double cannot check: the unique index, the foreign key, the transaction rollback, `tags` round-tripping. |
| `Infrastructure/Doctrine/DoctrineSpaceCatalogTest.php` | A wing differing from its slug, the private space created on accepting an invitation, a `priv_*` impostor without the flag. |
| `Api/McpGatewayTest.php` | The gateway as an agent meets it: the protocol, version negotiation, no batch requests, `401` for a revoked token, an expired one and one whose owner was deactivated, **a foreign space as an empty result rather than an error**, a refused write, an unknown parameter, the per-token rate limit, auditing of successful and failed calls. Deliberately **needs no palace** — all of it happens before memory, so it runs on every commit. |
| `Api/MemoryBrowseTest.php` | Browsing memory: another space invisible, naming it explicitly gaining nothing, the kind filter, paging, and the listing saying who wrote each row. Rows are inserted straight into the registry — this endpoint reads our own table only, so the test needs no palace. |
| `Api/SearchTest.php` | Search through the door people use — **lexical only**, because that mode runs entirely on our own tables and needs no palace. It guards the two things that would break quietly: a query reaching into a space the searcher may not read (including when a stranger names it explicitly), and an identifier with a hyphen that naive tokenisation does not find. |
| `Domain/Search/SnippetTest.php` | Turning `ts_headline` output into structure: the right words marked, **the markers gone**, an unclosed marker not swallowing the rest of the text, and `<script>` in the content staying plain text. |
| `Api/WikiTest.php` | The wiki without a palace: revisions, history carrying each era's title, the diff, a rollback that moves **forward**, verification cleared by a new revision, no path at all for an agent to verify, an address with a slash, the review queue. Asserts that the publish job was **enqueued**. |
| `Integration/WikiOnLivePalaceTest.php` | What a double cannot show: a second revision **replaces** the first in the palace (the old content stops being findable, so `update_drawer` really does recompute the vector) and three quick saves with the queue drained **newest first** end with the newest text. |
| `Domain/Document/RevisionDiffTest.php` | The only real algorithm in the project. A wrong diff is not an error anybody sees — it is a reviewer trusting a change on the strength of a picture that does not match the text. |
| `Domain/Document/DocumentSlugTest.php` | 16 refused addresses. The test that matters most asserts an address is **refused**, not tidied up. |
| `Integration/McpOnLivePalaceTest.php` | The full round trip through `/mcp` against a live palace: `ws_remember` → `ws_search` in Polish by different words → `ws_get`, a write with no space landing in the private one, the counts in `ws_status`, a fact round trip, the diary. |
| `Integration/MemoryOnLivePalaceTest.php` | **The whole chain against a live palace**: a Polish query in different words, a refusal for a stranger, a drawer filed past the registry, the room filter, a fact round-tripping through the graph. Skipped when the palace does not answer, so the fast CI run stays fast and the nightly run covers it. |

Running them: `make test` (prepares the test database and runs PHPUnit).

**Why negative tests matter more here than positive ones:** a permission bug is
silent. Nothing throws, nothing appears in a log — content simply reaches
somebody who should not see it.

## How to add something new

**A new endpoint:** a class in `Presentation/Api/` with a `#[Route]` attribute.
Logic goes into `Application/`, not into the controller. Permissions **always**
through `SpaceAccessResolver`.

**A new health probe:** a class implementing `Domain\Health\HealthProbe`. The
container tag picks it up automatically — the controller stays untouched.

**A new permission rule:** in `SpaceAccessResolver` only, with a negative test.
A second way of computing permissions is a second way of getting them wrong.

**A new migration:** by hand in `migrations/`, named `VersionYYYYMMDDNNNNNN`.
The `ws` schema only — we never write to `palace` (D-004).

**A new MCP tool:** a class implementing `Presentation\Mcp\McpTool` with a `ws_`
prefix in its name. The tag adds it to the registry — you touch neither the
controller nor any list. The schema **must** set `additionalProperties: false` and
must not carry an author or a wing field; `McpGatewayTest` checks both.

**A new wiki operation:** a method on `DocumentService`. Do not build a revision
outside `Document::addRevision()` — that is where the verification clearing and the
numbering live, and a second path will eventually miss one of the two.

**A new memory operation:** a method on `MemoryService`, never a new caller of
`MemoryStore`. The service is the permission boundary; going around it skips both
filters (D-019) and the bookkeeping (D-020). If you need a new MemPalace tool,
add it to the `MemoryStore` port and to `McpMemoryStore` — tool names must not
travel further up.

**A new dependency:** a `D-0xx` decision in `docs/06-decyzje.md` first.

## Configuration

| File | What it sets |
|---|---|
| `config/services.yaml` | Autowiring, the health probe and **MCP tool** tags, the rate limit, the MemPalace URL, **the palace token and timeout**, explicit port-to-adapter bindings, the public URL. A `when@test` block exposes a few services to the tests by name. |
| `config/packages/doctrine.yaml` | The connection, the `schema_filter` hiding `palace`, entity mapping. |
| `config/packages/security.yaml` | Firewalls: health unsecured, `json_login`, **a separate `/mcp` firewall for agent tokens**, JWT for `/api`. Lowered hashing cost in tests. |
| `config/packages/messenger.yaml` | The queue in the database, `auto_setup: false` — the table comes from a migration. `PublishDocument` is routed to the `async` transport. |
| `config/packages/api_platform.yaml` | The contract at `/api/docs.json`, **Swagger UI disabled** (it needs Twig, and the backend renders no interface). |

## Known limitations

- **No token refresh.** `gesdinet/jwt-refresh-token-bundle` does not support
  Symfony 8 yet (it requires `symfony/console ^7`). Until a compatible release
  appears, a token expires and one has to sign in again.
- **No invitation e-mails** — the link has to be passed on by hand.
- **The knowledge graph does not link entities across spaces.**
  `wing_alfa::Symfony` and `wing_beta::Symfony` are two entities to MemPalace,
  because the scope goes into the key (D-021). Intended: a relationship crossing
  a space boundary would be a leak.
- **`tags` never reach the palace** — MemPalace 3.7 has no tag field on
  `add_drawer`. We keep them in `ws.memory_entries`, so they are searchable in
  SQL but have no effect on semantic search.
- **The rate limit uses a fixed window, not a sliding one** — an agent can make
  twice the limit across a window boundary (D-022). The limit exists so a loop
  cannot swamp the palace, not to bill anybody.
- **There is no compensation for orphans in the palace** — a drawer with no
  registry row is reported by a periodic task; nothing deletes it automatically
  (D-020).
