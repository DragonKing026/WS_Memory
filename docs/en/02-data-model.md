---
tags: [ws-memory, documentation, data-model, postgres, doctrine, pgvector]
---

> Translated from [`docs/02-model-danych.md`](../02-model-danych.md) (synced 2026-09-13).
> **The Polish version is authoritative.**

# Data model

Status: **partly implemented** (2026-09-13). Present in the database: `users`,
`invitations`, `spaces`, `space_members`, `audit_log` (migration
`Version20260912000002`), `memory_entries` (`Version20260912000003`),
`agent_tokens` (`Version20260912000004`), `documents`, `document_revisions`
and `proposals` (`Version20260912000005`), and `mirrors`, `publish_settings`
and `publish_batches` (`Version20260913000005`). The
remaining tables described below are design — each arrives with the task that
needs it.

One PostgreSQL 18 database, two schemas:

- **`palace`** — MemPalace tables (the pgvector backend). **We never write to
  them.** We read them only for listing and statistics; every write goes
  through MemPalace so vectors and metadata stay consistent.
- **`ws`** — our data. The source of truth for documents, accounts and
  permissions.

## The `ws` schema

### Identity and access

**`users`** — a person's account.
`id`, `email` (unique), `password_hash`, `display_name`, `roles` (global:
`ROLE_USER`, `ROLE_ADMIN`), `is_active`, `created_at`, `last_login_at`.

**`invitations`** — invitation instead of open registration.
`id`, `email`, `token_hash`, `invited_by`, `role`, `expires_at`, `accepted_at`.
The token is shown once, at the moment it is issued.

**`agent_tokens`** — a machine credential. **Exists**
(`Version20260912000004`).
`id`, `user_id` (the owner), `label` ("Artur's laptop"), `token_hash`,
`space_scope` (`JSONB`: a subset of the owner's spaces, or `null` = all of them),
`expires_at`, `revoked_at`, `last_used_at`, `last_used_ip`, `calls_in_window`,
`window_started_at`, `created_at`.

> `space_scope` = `null` means "everything the owner may see". **An empty list
> means "nothing"** — and stays expressible on purpose: that is what a token being
> wound down before deletion looks like.
>
> `calls_in_window` and `window_started_at` carry the rate limit (D-022). They live
> here rather than in a cache because the write that updates them is the write that
> records `last_used_at` — one statement, no new dependency, and a limit that holds
> across several backend containers.
>
> The foreign key to the owner is `ON DELETE CASCADE`, unlike everywhere else in
> this schema. A token carries no history of its own — what it did is recorded in
> `audit_log` and `memory_entries`, neither of which has a foreign key to it.

> A token resolves to its owner and the **intersection** of their permissions with
> `space_scope`. Never the union. A scope can only narrow.

### Spaces

**`spaces`** — the unit knowledge is divided by: project, client, department.
`id`, `slug`, `name`, `description`, `palace_wing` (the wing name in the
palace), `palace_namespace` (`null` = shared; a value = separate pgvector
tables for sensitive spaces), `is_private` (a user's private space),
`requires_proposal` (whether agent writes go to a queue), `created_at`.

**`space_members`** — `space_id`, `user_id`, `role` (`reader` / `writer` /
`admin`), `added_at`, `added_by`. Composite key `(space_id, user_id)`.

Convention: a user's private space is `slug = priv_<user_id>`,
`is_private = true`, `palace_wing = priv_<user_id>`. Created automatically when
an invitation is accepted.

### Wiki

**`documents`** — the canonical document. **Exists**
(`Version20260912000005`).
`id`, `space_id`, `slug` (unique within the space), `title`, `status`
(`draft` / `published`), `current_revision_id`, `authored_by_ai` (bool),
`verified_by` (user, `null` = unverified), `verified_at`, `created_at`,
`updated_at`, `archived_at`.

> `current_revision_id` carries a **composite** foreign key on
> `(current_revision_id, id)` referencing `document_revisions (id, document_id)`.
> Pointing a document at **another** document's revision is therefore
> unrepresentable rather than merely wrong — a plain foreign key would allow it and
> nothing would notice until a reader saw somebody else's text. The constraint is
> `DEFERRABLE`, because a document and its first revision reference each other
> inside one transaction.
>
> `authored_by_ai` and `verified_by` answer **different** questions: who wrote it,
> and whether anybody vouches for it. A new revision clears the verification,
> because "Anna checked this" stops being true the moment the text changes. That
> clearing is the whole value of the flag.

**`document_revisions`** — full history, nothing overwritten. **Exists**
(`Version20260912000005`).
`id`, `document_id`, `number` (increasing within the document), `content`
(Markdown, **the full text**, not a diff), `title_at_revision`,
`author_user_id`, `author_agent_token_id`, `change_note`, `created_at`.

> We store full contents, not diffs. A diff is computed on the fly when
> comparing revisions. A rollback creates a **new** revision holding the old
> content — history never shrinks.
>
> Exactly one of `author_user_id` / `author_agent_token_id` is filled (a `CHECK`
> constraint). There is no revision without an author.

**`proposals`** — the queue, active only where `spaces.requires_proposal`.
**Exists** (`Version20260912000005`). Submitting needs the **reader** role,
accepting the writer one, and the resulting revision is authored by the reviewer
(D-026).
`id`, `space_id`, `title`, `content`, `author_agent_token_id`, `status`
(`pending` / `accepted` / `rejected`), `reviewed_by`, `reviewed_at`,
`resulting_document_id`, `created_at`.

### The bridge to the palace

**`memory_entries`** — a register of everything of ours that reached the palace.
**Exists** (`Version20260912000003`).
`id`, `drawer_id` (the MemPalace identifier, **unique**), `space_id`, `kind`
(`note` / `document` / `diary` / `kg_fact` / `transcript`, enforced by a
`CHECK`), `author_user_id`, `author_agent_token_id`, `document_id` (when
`kind = document`), `title` (the first non-empty line of the content — the
palace has no title field), `tags` (`JSONB`), `created_at`, `source_replica`
(identifier of the local palace the content came from — `null` for writes
originating on the server), `source_drawer_id` (the drawer identifier in that
palace), `publish_batch_id`, `content_hash` (a digest used to skip repeats
during automatic transfer).

> `drawer_id` is **unique**, because one piece of content belongs to exactly one
> space: "which one?" cannot have two answers when every read depends on it.
>
> `author_agent_token_id` and `document_id` carry **no foreign key**. The
> `documents` table arrives in `TODO-005`; for the token this is permanent. For
> the token that is in fact permanent, as in `audit_log`: the record of what a
> credential did must not be deletable by deleting the credential.
>
> The foreign key to the space is `ON DELETE RESTRICT`. Deleting a space would
> leave its drawers in the palace with nothing pointing at them, and content the
> registry does not know is unreachable for ever (D-019). A space with history is
> archived, not dropped.
>
> A knowledge-graph fact (`kind = kg_fact`) gets a row here too, although it is
> not a drawer: the palace returns no identifier for a fact, so we derive a
> stable fingerprint from the fact and the space (D-021). That is why a fact's
> `drawer_id` starts with `fact_`, and a diary entry's with `diary_`.

> The pair `(source_replica, source_drawer_id)` is **unique**. It is what makes
> re-publishing the same local drawer update the row instead of creating a
> second one (D-010). The replica identifier comes from the local palace's
> `replica.json` — MemPalace keeps it stable for precisely this purpose.
>
> New with `Version20260913000005`: `publish_batch_id` now has a **foreign key**
> to `ws.publish_batches` with `ON DELETE SET NULL`, and it is **deferred**
> (`DEFERRABLE INITIALLY DEFERRED`), so it is checked at `COMMIT` rather than at
> each `INSERT`. That is not a loosening — the constraint holds at every moment
> observable from outside — but a permission for the batch to be written **after**
> its drawers, with the counts that actually happened (D-036). Alongside it, a
> partial index on `(publish_batch_id)` where the column is not null: that is how
> an undo finds what to remove.
>
> `content_hash` solves a different problem: with transfer on by default
> (D-014), three people mining the same repository would send identical content
> three times. The index `(space_id, content_hash)` makes the second and third
> copy **within the same space** get skipped.
>
> That index is deliberately **not unique**. Skipping repeats is a publishing
> policy, not an invariant of the data: two people may record the same sentence
> and the registry must not refuse them with a write error. Publishing does the
> checking, not the table.
>
> For a publication, `created_at` is the time the content was filed **in the local
> palace**, not the time we received it. A laptop back from a week offline sends a
> week of drawers at once, and dating them all today would make the browse screen
> — ordered by exactly this column — claim a week of work happened in one minute.

> Why this table exists when the data is in the palace: **so permissions and
> auditing work in SQL rather than on results returned by the palace.** We
> filter before the semantic query, not after it. It also provides listing and
> statistics without loading MemPalace.

### The hybrid: local palaces and publishing

Three tables, all of which **exist** (`Version20260913000005`).

**`mirrors`** — a mapping of a local palace wing onto a **team space**.
`id`, `user_id`, `source_replica`, `source_wing`, `space_id`, `excluded_rooms`
(`JSONB`), `is_active` (default `true`), `is_confirmed` (default **`false`**),
`paused_at`, `last_synced_at`, `last_drawer_filed_at` (the incremental
watermark), `created_at`.

> A mapping is needed **only to make content reach the team**. An unmapped wing
> travels to the server anyway — into its owner's private space (D-014). That is
> why the confirmation (`is_confirmed`) applies to the mapping and not to the
> transfer: the mapping is what decides visibility to others.
>
> `is_confirmed` defaults to **false**, and that default is what decides
> visibility. A row inserted without saying anything about confirmation routes
> nothing to a team space; were the column to default to true, *proposing* a
> mapping would publish to the team.
>
> The triple `(user_id, source_replica, source_wing)` is **unique**. Two rows
> would make "where does this wing land?" a question with two answers, and the
> landing rule would have to choose — silently, on every publication. The foreign
> key to the user is `ON DELETE CASCADE` (a mapping describes somebody's machine
> and means nothing without them), the one to the space `RESTRICT` — as everywhere
> a space with history is archived rather than dropped.

**`publish_settings`** — transfer settings per user and replica.
`id`, `user_id`, `source_replica`, `auto_publish` (default **`true`**),
`private_space_id` (where unmapped wings land), `last_watermark` (how far the
transfer has got), `updated_at`. The pair `(user_id, source_replica)` is unique.

> Two opposite defaults side by side, deliberately: `auto_publish` defaults to
> **true**, because transfer is the behaviour (D-014) and a knowledge base you
> have to remember to feed stays empty; `is_confirmed` in `mirrors` defaults to
> **false**, because visibility to the team is confirmed by a human.
>
> The table exists but **the server does not read it yet** — `auto_publish` is a
> switch on the plugin's side, and points 5–7 of TODO-012 (the outbox,
> `/ws-publish`) belong to the client.

**`publish_batches`** — one publication batch, so it can be undone.
`id`, `user_id`, `agent_token_id` (when an agent published it), `mirror_id`
(`null` for selective publishing), `space_id`, `source_replica`,
`mode` (`selective` / `mirror`), `drawer_count`, `skipped_count`,
`skipped_reasons` (`JSONB` — what the secret filter rejected and why),
`status` (`preview` / `applied` / `reverted`), `created_at`, `reverted_at`.

> The batch is the unit of undo: "I pushed the wrong wing" is solved with one
> action rather than by hunting for drawers. The skip report is part of the
> batch, not a separate log — otherwise nobody would read it.
>
> `space_id` is `NOT NULL`, so a batch belongs to **one** space. That is why a
> single `POST /api/publish` request yields one batch **per target space**
> (D-036): the landing rule splits a send between team spaces and the private
> one, and "take back what the team can see" must not erase a week of private
> transcripts that went out in the same run.
>
> `CHECK ((status = 'reverted') = (reverted_at IS NOT NULL))` — the status and the
> timestamp cannot disagree. The journal is read to answer "what did I undo and
> when", and a status able to lie about its own timestamp answers it wrongly.
>
> `status = 'preview'` **never reaches the table** — a preview writes nothing,
> itself included. The value exists in the `CHECK` and in the code so a report has
> a name for what it is.
>
> `mirror_id` is `ON DELETE SET NULL`: deleting a mapping must not delete the
> record of what it once published. An undo nobody can find is an undo nobody can
> perform.

### Operations

> There are **no** `mining_jobs` or `session_uploads` tables — the server mines
> nothing and accepts no raw transcripts (D-012). Mining happens on the user's
> machine, and the only server-side trace is the publication batch
> (`publish_batches`).

**`audit_log`** — `id`, `actor_user_id`, `actor_agent_token_id`, `action`
(`search` / `doc_read` / `doc_write` / `remember` / `login` / `token_create` …),
`space_id`, `target` (`JSONB`: what exactly), `ip`, `user_agent`, `created_at`.
Indexed by `created_at` and by actor. Retention: see
`docs/en/05-deployment.md`.

## Mapping spaces onto the palace

| WS_Memory | MemPalace |
|---|---|
| space (`spaces`) | wing |
| knowledge class (`kind`) | room: `documentation`, `diary`, `technical`, … |
| sensitive space | a separate pgvector **namespace** (separate tables) |
| wiki document | **one** drawer in the `documentation` room, updated in place on every revision (D-025) + a `memory_entries` row |

The two isolation levels are not redundant: `wing` is a filter in a query
(cheap, for most spaces), a `namespace` means separate tables (for spaces where
a mistake in the filter would be unacceptable).

## Integrity rules

1. Every revision has exactly one author — a person **or** an agent token
   (`CHECK`).
2. `documents.current_revision_id` points at a revision of the same document
   (foreign key + `CHECK`).
3. Deleting a user does not delete revisions — authorship remains
   (`ON DELETE RESTRICT`). Accounts are deactivated, never erased.
4. Revoking an agent token does not remove its entries; `revoked_at` closes
   access going forward.
5. A `memory_entries` row without a matching drawer in the palace signals
   divergence — a scheduled job reports it (and never silently repairs it).
6. A mirror without `is_confirmed` **routes nothing to a team space**. The
   content still travels to the server — into its owner's private space (D-014),
   because the invariant is "everything is on the server, nothing is visible to
   the team without a mapping". The same holds for a mirror that is deactivated,
   paused, or excludes the room (D-036). The condition lives in
   `Mirror::routes()` and is covered by `PublishServiceTest` and
   `DoctrinePublishBridgeTest`.
7. Reverting a batch removes the drawers from the palace and the
   `memory_entries` rows, but **keeps the batch itself** with status `reverted`
   — publication history does not shrink.
