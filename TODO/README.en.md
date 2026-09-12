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
- **Rozwiązanie** (Solution) — how we do it, in order of execution.
- **Kryteria ukończenia** (Completion criteria) — verifiable conditions. Not
  "it works", but "command X returns Y".

## Screenshots

Screenshots taken while verifying a task go into `TODO/zrzuty/`, named
`NNN-short-description.png` — **never into the repository root**. The rules and the
reasoning: `TODO/zrzuty/README.md`.

## When finished

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

## Order and dependencies

```
✅ 000 skeleton ──► ✅ 001 backend foundation ──► ✅ 002 accounts and spaces
                                                      │
                                  ┌───────────────────┼───────────────────┐
                                  ▼                   ▼                   ▼
                        ✅ 003 palace client ✅ 005 wiki backend ✅ 006 frontend base
                                  │                   │                   │
                                  ▼                   │                   ▼
                        ✅ 004 MCP gateway ◄──────────┘            007 search (UI)
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
