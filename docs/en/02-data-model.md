---
tags: [ws-memory, documentation, data-model, postgres, doctrine, pgvector]
---

> Translated from [`docs/02-model-danych.md`](../02-model-danych.md) (synced 2026-09-12).
> **The Polish version is authoritative.**

# Data model

Status: **partly implemented** (2026-09-12). Present in the database: `users`,
`invitations`, `spaces`, `space_members`, `audit_log` (migration
`Version20260912000002`) and `memory_entries` (`Version20260912000003`). The
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

**`agent_tokens`** — a machine credential.
`id`, `user_id` (owner), `label` ("Artur's laptop"), `token_hash`,
`space_scope` (`JSONB`: a subset of the owner's spaces, or `null` = all of
them), `expires_at`, `revoked_at`, `last_used_at`, `last_used_ip`.

> A token resolves to its owner and the **intersection** of their permissions
> with `space_scope`. Never the union. The scope can only narrow.

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

**`documents`** — the canonical document.
`id`, `space_id`, `slug` (unique within the space), `title`, `status`
(`draft` / `published`), `current_revision_id`, `authored_by_ai` (bool),
`verified_by` (user, `null` = unverified), `verified_at`, `created_at`,
`updated_at`, `archived_at`.

**`document_revisions`** — full history, nothing overwritten.
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
> `author_agent_token_id` and `document_id` carry **no foreign key** — the
> `agent_tokens` and `documents` tables arrive in `TODO-004` and `TODO-005`. For
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
> `content_hash` solves a different problem: with transfer on by default
> (D-014), three people mining the same repository would send identical content
> three times. The index `(space_id, content_hash)` makes the second and third
> copy **within the same space** get skipped.
>
> That index is deliberately **not unique**. Skipping repeats is a publishing
> policy, not an invariant of the data: two people may record the same sentence
> and the registry must not refuse them with a write error. Publishing does the
> checking, not the table.

> Why this table exists when the data is in the palace: **so permissions and
> auditing work in SQL rather than on results returned by the palace.** We
> filter before the semantic query, not after it. It also provides listing and
> statistics without loading MemPalace.

### The hybrid: local palaces and publishing

**`mirrors`** — a mapping of a local palace wing onto a **team space**.
`id`, `user_id`, `source_replica`, `source_wing`, `space_id`, `excluded_rooms`
(`JSONB`), `is_active`, `is_confirmed`, `paused_at`, `last_synced_at`,
`last_drawer_filed_at` (the incremental watermark), `created_at`.

> A mapping is needed **only to make content reach the team**. An unmapped wing
> travels to the server anyway — into its owner's private space (D-014). That is
> why the confirmation (`is_confirmed`) applies to the mapping and not to the
> transfer: the mapping is what decides visibility to others.

**`publish_settings`** — transfer settings per user and replica.
`id`, `user_id`, `source_replica`, `auto_publish` (default **`true`**),
`private_space_id` (where unmapped wings land), `last_watermark` (how far the
transfer has got), `updated_at`.

**`publish_batches`** — one publication batch, so it can be undone.
`id`, `user_id`, `mirror_id` (`null` for selective publishing), `space_id`,
`mode` (`selective` / `mirror`), `drawer_count`, `skipped_count`,
`skipped_reasons` (`JSONB` — what the secret filter rejected and why),
`status` (`preview` / `applied` / `reverted`), `created_at`, `reverted_at`.

> The batch is the unit of undo: "I pushed the wrong wing" is solved with one
> action rather than by hunting for drawers. The skip report is part of the
> batch, not a separate log — otherwise nobody would read it.

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
| wiki document | a drawer in the `documentation` room + a `memory_entries` row |

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
6. A mirror without `is_confirmed` **publishes nothing** — it can only produce a
   preview. Enforced in code and covered by a test.
7. Reverting a batch removes the drawers from the palace and the
   `memory_entries` rows, but **keeps the batch itself** with status `reverted`
   — publication history does not shrink.
