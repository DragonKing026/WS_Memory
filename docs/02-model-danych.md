---
noteId: "8b915270aeb011f1997d030a3cd38ca7"
tags: [ws-memory, dokumentacja, model-danych, postgres, doctrine, pgvector]

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
`created_at`, `source_replica` (identyfikator lokalnego pałaca, skąd przyszła
treść — `null` dla zapisów powstałych na serwerze), `source_drawer_id`
(identyfikator szuflady w tamtym pałacu), `publish_batch_id`,
`content_hash` (skrót treści — odsiew powtórzeń przy automatycznej wysyłce).

> Para `(source_replica, source_drawer_id)` jest **unikalna**. To ona sprawia,
> że powtórna publikacja tej samej lokalnej szuflady aktualizuje wpis, zamiast
> tworzyć drugi (D-010). Identyfikator repliki bierzemy z `replica.json`
> lokalnego pałaca — MemPalace utrzymuje go stabilnie właśnie po to.
>
> `content_hash` rozwiązuje inny problem: przy domyślnej automatycznej wysyłce
> (D-014) trzy osoby mielące to samo repozytorium przysłałyby tę samą treść
> trzy razy. Indeks `(space_id, content_hash)` sprawia, że druga i trzecia
> kopia w **tej samej** przestrzeni jest pomijana.

> Po co ta tabela, skoro dane są w pałacu: **żeby uprawnienia i audyt działały
> w SQL, a nie na wynikach z pałaca.** Filtrujemy przed zapytaniem
> semantycznym, nie po nim. Dodatkowo daje listowanie i statystyki bez
> obciążania MemPalace.

### Hybryda: lokalne pałace i publikacja

**`mirrors`** — mapowanie skrzydła lokalnego pałaca na **przestrzeń
zespołową**. `id`, `user_id`, `source_replica`, `source_wing`, `space_id`,
`excluded_rooms` (`JSONB`), `is_active`, `is_confirmed`, `paused_at`,
`last_synced_at`, `last_drawer_filed_at` (znacznik przyrostowości),
`created_at`.

> Mapowanie jest potrzebne **tylko po to, by treść trafiła do zespołu**.
> Skrzydło bez mapowania i tak jedzie na serwer — do prywatnej przestrzeni
> właściciela (D-014). Dlatego potwierdzenie (`is_confirmed`) dotyczy
> mapowania, a nie wysyłki: to mapowanie decyduje o widoczności dla innych.

**`publish_settings`** — ustawienia wysyłki per użytkownik i replika.
`id`, `user_id`, `source_replica`, `auto_publish` (domyślnie **`true`**),
`private_space_id` (gdzie lądują skrzydła bez mapowania),
`last_watermark` (do której chwili wysłano), `updated_at`.

**`publish_batches`** — jedna partia publikacji, żeby dało się ją wycofać.
`id`, `user_id`, `mirror_id` (`null` przy publikacji selektywnej), `space_id`,
`mode` (`selective` / `mirror`), `drawer_count`, `skipped_count`,
`skipped_reasons` (`JSONB` — co odrzucił filtr sekretów i dlaczego),
`status` (`preview` / `applied` / `reverted`), `created_at`, `reverted_at`.

> Partia jest jednostką wycofania: „wypchnąłem nie to skrzydło" rozwiązuje się
> jednym działaniem, a nie ręcznym szukaniem szuflad. Raport pominięć jest
> częścią partii, nie osobnym dziennikiem — inaczej nikt by go nie czytał.

### Operacje

> Tabel `mining_jobs` i `session_uploads` **nie ma** — serwer nie mieli niczego
> i nie przyjmuje surowych transkryptów (D-012). Mielenie dzieje się na
> maszynie użytkownika, a jedynym śladem po stronie serwera jest partia
> publikacji (`publish_batches`).

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
6. Lustro bez `is_confirmed` **nie wykonuje publikacji** — może tylko
   wygenerować podgląd. Warunek sprawdzany w kodzie i pokryty testem.
7. Wycofanie partii usuwa szuflady z pałaca i wiersze `memory_entries`, ale
   **zostawia samą partię** ze statusem `reverted` — historia publikacji się
   nie kurczy.
