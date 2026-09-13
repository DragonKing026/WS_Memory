---
tags: [ws-memory, todo, process, conventions]
---

> Translated from [`README.md`](README.md) (synced 2026-09-12).
> **The Polish version is authoritative.** Task files themselves stay Polish —
> they are working notes, not reference documentation.

# TODO — how tasks are run

One file = one task. File name: `NNN-short-description.md`.
The heading inside the file: **`# TODO-NNN — Task title`** — the prefix lets a
task be named unambiguously in conversation, commits and documentation
("do TODO-004") without confusing it with a decision number (`D-004`).

## Structure of a task file

Every task has four sections:

- **Powód** (Reason) — why we are doing this. What is wrong or missing now.
- **Analiza** (Analysis) — what we checked, what the options are, what to watch
  out for.
- **Rozwiązanie** (Solution) — how we do it, as a **checkbox list**, in order
  of execution.
- **Kryteria ukończenia** (Completion criteria) — a **checkbox list** of
  verifiable conditions. Not "it works", but "command X returns Y".

## Checkboxes are ticked as you go

`Rozwiązanie` and `Kryteria ukończenia` are `- [ ]` / `- [x]` lists. **You tick
an item in the same commit that delivers it** — not at the end of the task.

Two rules, both taken from mistakes rather than from theory:

1. **Tick a criterion once it is verified, not once the code exists.** In
   TODO-012 the secret filter works server-side and has tests, yet the criterion
   stays unticked, because it reads "neither from the client, nor when the client
   sends it anyway (**two separate tests**)" — and there is no client.
2. **Do not keep a second progress list beside it.** A "what is done" table that
   repeats the solution list will drift from it on the first change. Such a table
   was added to TODO-012 and removed the same day; the `Postęp` (Progress)
   section keeps only what the checkboxes cannot say — a bug found along the way,
   for instance.

## A task done in part

The state in the header then reads **`🔵 W TOKU — punkty A–B z N`** (in progress)
with a date, and the file gets a **Postęp** (Progress) section.

The reason is concrete: a half-finished task looks **exactly** like an untouched
one on the list. It happened on 2026-09-13 — the server half of TODO-012 was
merged into `main` while the file still read "do zrobienia". The person planning
the next step noticed, no check did.

**No script will enforce this.** `sprawdz-zadania.py` does not know how much of a
task is done, and will not. This is a hand-kept rule and stays one — which is why
it is written down here.

## Screenshots

Screenshots taken while verifying a task go into `TODO/zrzuty/`, named
`NNN-short-description.png` — **never into the repository root**. The rules and the
reasoning: `TODO/zrzuty/README.md`.

## When finished

0. Tick the last checkboxes. If any stays empty the task **is not finished** —
   either drop that scope deliberately and record it in the accounting as
   deferred, or finish it.
1. Add a **Co zostało zrobione** (What was done) section with the date and
   time — **taken from the clock, not from memory**, so that `git log` can verify
   it afterwards. Times written by feel have already put `CHANGELOG.md` hours out
   and placed two entries in the future: what was built, what was tested, what was deferred and why. Facts, not
   declarations.
2. `git mv TODO/NNN-....md TODO/DONE/` — the file's history is preserved.
3. Update the documentation in `docs/` **and** `docs/en/` if system behaviour
   changed.
4. Add an entry to `CHANGELOG.md` with the date and time.
5. Commit it all together: code, documentation, moved task, changelog.

A task without a **Co zostało zrobione** section does not go to `DONE/`.

**A cancelled task leaves the list too** — into `DONE/`, with a **Dlaczego
anulowane** (Why cancelled) section instead of an accounting. Nothing was built,
so an accounting of work that did not happen would be an empty section. Left in
`TODO/` it reads as a backlog item: TODO-010 sat there for a day after being
cancelled and somebody asked why we were not doing it before 011.

## Order and dependencies

```
✅ 000 skeleton ──► ✅ 001 backend foundation ──► ✅ 002 accounts and spaces
                                                      │
                                  ┌───────────────────┼───────────────────┐
                                  ▼                   ▼                   ▼
                        ✅ 003 palace client ✅ 005 wiki backend ✅ 006 frontend base
                                  │                   │                   │
                                  ▼                   │                   ▼
                        ✅ 004 MCP gateway ◄──────────┘         ✅ 007 search (UI)
                                  │                                       │
                                  ▼                                       ▼
                           009 plugin                              008 editor and history
                                  │
                                  ▼
                           012 bridge: local palace → server
                                  │
                                  ▼
                           011 polish

  ❌ 010 server-side mining — CANCELLED (D-012)
  ✅ 013 English documentation · ✅ 014 continuous integration
```

Tasks 000–002 are strictly sequential. Beyond that the backend (003–005) and
the frontend (006–008) can run in parallel, because they meet only through the
OpenAPI contract. `TODO-010` was **cancelled** by decision D-012: the server
mines nothing, so the only route for knowledge to enter is `TODO-012` —
publishing from a local palace. The file stays in place with a cancelled status
so numbering does not shift and the analysis remains available.
