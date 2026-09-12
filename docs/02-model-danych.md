---
noteId: "8b915270aeb011f1997d030a3cd38ca7"
tags: []

---

# Model danych

Stan: **projekt**, encje Doctrine jeszcze nie istnieją (2026-09-12).

Jedna baza PostgreSQL 18, dwa schematy:

- **`palace`** — tabele MemPalace (backend pgvector). **Nie dotykamy ich
  zapisem.** Czytamy z nich tylko do listowania i statystyk; każdy zapis idzie
  przez MemPalace, żeby wektory i metadane pozostały spójne.
- **`ws`** — nasze dane. Źródło prawdy dla dokumentów, kont i uprawnień.

## Schemat `ws`

### Tożsamość i dostęp

**`users`** — konto człowieka.
`id`, `email` (unikalne), `password_hash`, `display_name`, `roles` (globalne:
`ROLE_USER`, `ROLE_ADMIN`), `is_active`, `created_at`, `last_login_at`.

**`invitations`** — zaproszenie zamiast otwartej rejestracji.
`id`, `email`, `token_hash`, `invited_by`, `role`, `expires_at`, `accepted_at`.
Token widoczny raz, w chwili wystawienia.

**`agent_tokens`** — poświadczenie maszyny.
`id`, `user_id` (właściciel), `label` („laptop Artura"), `token_hash`,
`space_scope` (`JSONB`: podzbiór przestrzeni właściciela lub `null` = wszystkie
jego), `expires_at`, `revoked_at`, `last_used_at`, `last_used_ip`.

> Token rozwiązuje się na właściciela i **przecięcie** jego uprawnień ze
> `space_scope`. Nigdy sumę. Zakres może tylko zawężać.

### Przestrzenie

**`spaces`** — jednostka podziału wiedzy: projekt, klient, dział.
`id`, `slug`, `name`, `description`, `palace_wing` (nazwa skrzydła w pałacu),
`palace_namespace` (`null` = wspólny; wartość = osobne tabele pgvector dla
przestrzeni wrażliwych), `is_private` (prywatna przestrzeń użytkownika),
`requires_proposal` (czy zapisy agentów idą do kolejki), `created_at`.

**`space_members`** — `space_id`, `user_id`, `role` (`reader` / `writer` /
`admin`), `added_at`, `added_by`. Klucz złożony `(space_id, user_id)`.

Konwencja: prywatna przestrzeń użytkownika to `slug = priv_<user_id>`,
`is_private = true`, `palace_wing = priv_<user_id>`. Tworzona automatycznie
przy akceptacji zaproszenia.

### Wiki

**`documents`** — dokument kanoniczny.
`id`, `space_id`, `slug` (unikalny w przestrzeni), `title`, `status`
(`draft` / `published`), `current_revision_id`, `authored_by_ai` (bool),
`verified_by` (user, `null` = niezweryfikowany), `verified_at`,
`created_at`, `updated_at`, `archived_at`.

**`document_revisions`** — pełna historia, bez nadpisywania.
`id`, `document_id`, `number` (rosnący w dokumencie), `content` (Markdown,
**pełna treść**, nie diff), `title_at_revision`, `author_user_id`,
`author_agent_token_id`, `change_note`, `created_at`.

> Trzymamy pełne treści, nie diffy. Diff liczymy w locie przy porównywaniu
> rewizji. Rollback to utworzenie **nowej** rewizji z treścią starej — historia
> nigdy się nie skraca.
>
> Dokładnie jedno z `author_user_id` / `author_agent_token_id` jest wypełnione
> (ograniczenie `CHECK`). Nie ma rewizji bez autora.

**`proposals`** — kolejka, aktywna tylko gdy `spaces.requires_proposal`.
`id`, `space_id`, `title`, `content`, `author_agent_token_id`, `status`
(`pending` / `accepted` / `rejected`), `reviewed_by`, `reviewed_at`,
`resulting_document_id`, `created_at`.

### Most do pałaca

**`memory_entries`** — rejestr wszystkiego, co nasze trafiło do pałaca.
`id`, `drawer_id` (identyfikator w MemPalace), `space_id`, `kind`
(`note` / `document` / `diary` / `kg_fact` / `transcript`), `author_user_id`,
`author_agent_token_id`, `document_id` (gdy `kind = document`), `title`,
`created_at`.

> Po co ta tabela, skoro dane są w pałacu: **żeby uprawnienia i audyt działały
> w SQL, a nie na wynikach z pałaca.** Filtrujemy przed zapytaniem
> semantycznym, nie po nim. Dodatkowo daje listowanie i statystyki bez
> obciążania MemPalace.

### Operacje

**`mining_jobs`** — `id`, `source_type` (`transcript` / `repository` /
`documents`), `source_ref`, `space_id`, `requested_by`, `status`
(`queued` / `running` / `done` / `failed`), `stats` (`JSONB`), `error`,
`created_at`, `finished_at`.

**`session_uploads`** — `id`, `session_id`, `user_id`, `agent_token_id`,
`byte_offset` (dokąd doszliśmy), `file_path` (na wolumenie),
`last_upload_at`. Klucz unikalny `(session_id, user_id)`.

**`audit_log`** — `id`, `actor_user_id`, `actor_agent_token_id`, `action`
(`search` / `doc_read` / `doc_write` / `remember` / `login` / `token_create` …),
`space_id`, `target` (`JSONB`: co dokładnie), `ip`, `user_agent`, `created_at`.
Indeks po `created_at` i po aktorze. Retencja: patrz `docs/05-deployment.md`.

## Mapowanie przestrzeni na pałac

| WS_Memory | MemPalace |
|---|---|
| przestrzeń (`spaces`) | skrzydło (`wing`) |
| klasa wiedzy (`kind`) | pokój (`room`): `documentation`, `diary`, `technical`, … |
| przestrzeń wrażliwa | osobny **namespace** pgvector (osobne tabele) |
| dokument wiki | szuflada w pokoju `documentation` + wiersz w `memory_entries` |

Dwa poziomy izolacji nie są redundancją: `wing` to filtr w zapytaniu (tani,
dla większości przestrzeni), `namespace` to osobne tabele (dla przestrzeni,
gdzie błąd w filtrze byłby nieakceptowalny).

## Reguły integralności

1. Każda rewizja ma dokładnie jednego autora — człowieka **albo** token
   agenta (`CHECK`).
2. `documents.current_revision_id` wskazuje rewizję tego samego dokumentu
   (klucz obcy + `CHECK`).
3. Usunięcie użytkownika nie usuwa rewizji — autorstwo zostaje
   (`ON DELETE RESTRICT`). Konto się dezaktywuje, nie wymazuje.
4. Unieważnienie tokena agenta nie usuwa jego wpisów; `revoked_at` zamyka
   dostęp na przyszłość.
5. `memory_entries` bez odpowiadającej szuflady w pałacu to sygnał rozjazdu
   — zadanie cykliczne to raportuje (nie naprawia po cichu).
