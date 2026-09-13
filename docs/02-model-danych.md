---
noteId: "8b915270aeb011f1997d030a3cd38ca7"
tags: [ws-memory, dokumentacja, model-danych, postgres, doctrine, pgvector]

---

# Model danych

Stan: **częściowo wdrożony** (2026-09-13). Istnieją w bazie: `users`,
`invitations`, `spaces`, `space_members`, `audit_log` (migracja
`Version20260912000002`), `memory_entries` (`Version20260912000003`),
`agent_tokens` (`Version20260912000004`), `documents`,
`document_revisions` i `proposals` (`Version20260912000005`) oraz `mirrors`,
`publish_settings` i `publish_batches` (`Version20260913000005`).
Reszta tabel opisanych niżej to projekt — powstaną wraz z zadaniami, które ich
potrzebują.

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

**`agent_tokens`** — poświadczenie maszyny. **Istnieje**
(`Version20260912000004`).
`id`, `user_id` (właściciel), `label` („laptop Artura"), `token_hash`,
`space_scope` (`JSONB`: podzbiór przestrzeni właściciela lub `null` = wszystkie
jego), `expires_at`, `revoked_at`, `last_used_at`, `last_used_ip`,
`calls_in_window`, `window_started_at`, `created_at`.

> `space_scope` = `null` znaczy „wszystko, co widzi właściciel". **Pusta lista
> znaczy „nic"** — i zostaje wyrażalna celowo: tak wygląda token wygaszany
> przed usunięciem.
>
> `calls_in_window` i `window_started_at` niosą limit tempa (D-022). Siedzą tu,
> a nie w cache, bo zapis, który je aktualizuje, to ten sam zapis, który
> odnotowuje `last_used_at` — jedno zapytanie, żadnej nowej zależności i limit
> obowiązujący przy kilku kontenerach backendu.
>
> Klucz obcy do właściciela ma `ON DELETE CASCADE`, inaczej niż w reszcie tego
> schematu. Token nie nosi własnej historii — co zrobił, zapisują `audit_log`
> i `memory_entries`, a żadna z tych tabel nie ma do niego klucza obcego.

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

**`documents`** — dokument kanoniczny. **Istnieje**
(`Version20260912000005`).
`id`, `space_id`, `slug` (unikalny w przestrzeni), `title`, `status`
(`draft` / `published`), `current_revision_id`, `authored_by_ai` (bool),
`verified_by` (user, `null` = niezweryfikowany), `verified_at`,
`created_at`, `updated_at`, `archived_at`.

> `current_revision_id` ma klucz obcy **złożony** na `(current_revision_id, id)`
> wskazujący `document_revisions (id, document_id)`. Dzięki temu wskazanie
> rewizji **innego** dokumentu jest niewyrażalne, a nie tylko niepoprawne —
> zwykły klucz obcy by to dopuścił i nikt by nie zauważył, dopóki czytelnik nie
> zobaczyłby cudzego tekstu. Ograniczenie jest `DEFERRABLE`, bo dokument i jego
> pierwsza rewizja wskazują na siebie wzajemnie w jednej transakcji.
>
> `authored_by_ai` i `verified_by` odpowiadają na **różne** pytania: kto napisał
> i czy ktoś za to ręczy. Nowa rewizja czyści weryfikację, bo „Anna to
> sprawdziła" przestaje być prawdą w chwili zmiany tekstu. To czyszczenie jest
> całą wartością tej flagi.

**`document_revisions`** — pełna historia, bez nadpisywania. **Istnieje**
(`Version20260912000005`).
`id`, `document_id`, `number` (rosnący w dokumencie), `content` (Markdown,
**pełna treść**, nie diff), `title_at_revision`, `author_user_id`,
`author_agent_token_id`, `change_note`, `created_at`.

> Trzymamy pełne treści, nie diffy. Diff liczymy w locie przy porównywaniu
> rewizji. Rollback to utworzenie **nowej** rewizji z treścią starej — historia
> nigdy się nie skraca.
>
> Dokładnie jedno z `author_user_id` / `author_agent_token_id` jest wypełnione
> (ograniczenie `CHECK`). Nie ma rewizji bez autora.
>
> Konsekwencja, którą trzeba znać: rewizja agenta **nie** zapisuje właściciela
> tokena. Kto za nią odpowiada, ustala się przez token (`agent_tokens.user_id`)
> — dlatego `AgentTokenDirectory::ownerOf()` odpowiada także dla tokenów
> unieważnionych: kto coś napisał, nie zmienia się, gdy jego poświadczenie
> zostaje wycofane.
>
> `(document_id, number)` jest **unikalne**, więc numery rewizji nie powtarzają
> się w dokumencie. To dlatego „rewizja 3" jest odnośnikiem, którego można użyć.

**`proposals`** — kolejka, aktywna tylko gdy `spaces.requires_proposal`.
**Istnieje** (`Version20260912000005`).
`id`, `space_id`, `slug` (proponowany adres, opcjonalny), `title`, `content`,
`author_agent_token_id`, `author_user_id`, `status`
(`pending` / `accepted` / `rejected`), `reviewed_by`, `reviewed_at`,
`review_note`, `resulting_document_id`, `created_at`.

> Złożenie wymaga roli **czytającego**, przyjęcie — piszącego, a autorem
> powstałej rewizji jest recenzent (D-026).

### Most do pałaca

**`memory_entries`** — rejestr wszystkiego, co nasze trafiło do pałaca.
**Istnieje** (`Version20260912000003`).
`id`, `drawer_id` (identyfikator w MemPalace, **unikalny**), `space_id`, `kind`
(`note` / `document` / `diary` / `kg_fact` / `transcript`, pilnowane przez
`CHECK`), `author_user_id`, `author_agent_token_id`, `document_id`
(gdy `kind = document`), `title` (pierwszy niepusty wiersz treści — pałac nie
ma pola tytułu), `tags` (`JSONB`), `created_at`, `source_replica`
(identyfikator lokalnego pałaca, skąd przyszła treść — `null` dla zapisów
powstałych na serwerze), `source_drawer_id` (identyfikator szuflady w tamtym
pałacu), `publish_batch_id`, `content_hash` (skrót treści — odsiew powtórzeń
przy automatycznej wysyłce).

> `drawer_id` jest **unikalny**, bo jedna treść należy do dokładnie jednej
> przestrzeni: pytanie „w której?" nie może mieć dwóch odpowiedzi, skoro na nim
> opiera się każdy odczyt.
>
> `author_agent_token_id` i `document_id` **nie mają klucza obcego**. Tabela
> `documents` powstaje w `TODO-005`; dla tokena jest to stan docelowy, tak jak
> w `audit_log`: śladu po tym, co zrobił
> token, nie wolno dać się usunąć przez usunięcie tokena.
>
> Klucz obcy do przestrzeni ma `ON DELETE RESTRICT`. Usunięcie przestrzeni
> zostawiłoby jej szuflady w pałacu bez żadnego wskazania, a treść, której
> rejestr nie zna, jest nieosiągalna na zawsze (D-019). Przestrzeń z historią
> się archiwizuje, nie usuwa.
>
> Fakt grafu wiedzy (`kind = kg_fact`) też ma tu wiersz, choć nie jest
> szufladą: pałac nie zwraca dla faktu żadnego identyfikatora, więc
> wyliczamy stabilny odcisk z samego faktu i przestrzeni (D-021). Dlatego
> `drawer_id` faktu zaczyna się od `fact_`, a wpisu w dzienniku od `diary_`.

> Para `(source_replica, source_drawer_id)` jest **unikalna**. To ona sprawia,
> że powtórna publikacja tej samej lokalnej szuflady aktualizuje wpis, zamiast
> tworzyć drugi (D-010). Identyfikator repliki bierzemy z `replica.json`
> lokalnego pałaca — MemPalace utrzymuje go stabilnie właśnie po to.
>
> Nowe od `Version20260913000005`: `publish_batch_id` ma **klucz obcy** do
> `ws.publish_batches` z `ON DELETE SET NULL` i jest **odroczony**
> (`DEFERRABLE INITIALLY DEFERRED`), więc sprawdza się przy `COMMIT`, a nie przy
> każdym `INSERT`. To nie jest poluzowanie — ograniczenie obowiązuje w każdej
> chwili, którą da się zaobserwować z zewnątrz — tylko pozwolenie, by partia
> została zapisana **po** swoich szufladach, z liczbami, które naprawdę się
> zdarzyły (D-036). Do tego indeks częściowy `(publish_batch_id)` tam, gdzie
> kolumna jest niepusta: po nim wycofanie znajduje, co usunąć.
>
> `content_hash` rozwiązuje inny problem: przy domyślnej automatycznej wysyłce
> (D-014) trzy osoby mielące to samo repozytorium przysłałyby tę samą treść
> trzy razy. Indeks `(space_id, content_hash)` sprawia, że druga i trzecia
> kopia w **tej samej** przestrzeni jest pomijana.
>
> Ten indeks celowo **nie jest unikalny**. Odsiew powtórzeń to polityka
> publikacji, a nie niezmiennik danych: dwie osoby mogą zapisać to samo zdanie
> i rejestr nie może im tego odmówić błędem zapisu. Sprawdzenie robi
> publikacja, nie tabela.
>
> `created_at` przy publikacji to **czas zapisania w lokalnym pałacu**, nie czas
> odebrania. Laptop po tygodniu bez sieci przysyła tydzień szuflad naraz, a
> opatrzenie ich wszystkich dzisiejszą datą sprawiłoby, że ekran przeglądania —
> sortowany dokładnie po tej kolumnie — twierdziłby, że tydzień pracy zdarzył
> się w jedną minutę.

> Po co ta tabela, skoro dane są w pałacu: **żeby uprawnienia i audyt działały
> w SQL, a nie na wynikach z pałaca.** Filtrujemy przed zapytaniem
> semantycznym, nie po nim. Dodatkowo daje listowanie i statystyki bez
> obciążania MemPalace.

### Hybryda: lokalne pałace i publikacja

Trzy tabele, wszystkie **istnieją** (`Version20260913000005`).

**`mirrors`** — mapowanie skrzydła lokalnego pałaca na **przestrzeń
zespołową**. `id`, `user_id`, `source_replica`, `source_wing`, `space_id`,
`excluded_rooms` (`JSONB`), `is_active` (domyślnie `true`), `is_confirmed`
(domyślnie **`false`**), `paused_at`, `last_synced_at`,
`last_drawer_filed_at` (znacznik przyrostowości), `created_at`.

> Mapowanie jest potrzebne **tylko po to, by treść trafiła do zespołu**.
> Skrzydło bez mapowania i tak jedzie na serwer — do prywatnej przestrzeni
> właściciela (D-014). Dlatego potwierdzenie (`is_confirmed`) dotyczy
> mapowania, a nie wysyłki: to mapowanie decyduje o widoczności dla innych.
>
> `is_confirmed` jest domyślnie **fałszywe** i to jest wartość, która decyduje
> o widoczności. Wiersz wstawiony bez słowa o potwierdzeniu nie kieruje niczego
> do przestrzeni zespołowej; gdyby kolumna domyślnie była prawdziwa,
> *zaproponowanie* mapowania publikowałoby zespołowi.
>
> Para `(user_id, source_replica, source_wing)` jest **unikalna**. Dwa wiersze
> sprawiłyby, że pytanie „gdzie ląduje to skrzydło" ma dwie odpowiedzi, a
> reguła lądowania musiałaby wybierać — po cichu, przy każdej publikacji.
> Klucz obcy do użytkownika ma `ON DELETE CASCADE` (mapowanie opisuje czyjąś
> maszynę i bez niej nic nie znaczy), a do przestrzeni `RESTRICT` — jak wszędzie
> tam, gdzie przestrzeń z historią się archiwizuje, nie usuwa.

**`publish_settings`** — ustawienia wysyłki per użytkownik i replika.
`id`, `user_id`, `source_replica`, `auto_publish` (domyślnie **`true`**),
`private_space_id` (gdzie lądują skrzydła bez mapowania),
`last_watermark` (do której chwili wysłano), `updated_at`. Para
`(user_id, source_replica)` unikalna.

> Dwa przeciwne domyślne ustawienia obok siebie, celowo: `auto_publish`
> domyślnie **prawdziwe**, bo wysyłka jest zachowaniem (D-014) i baza wiedzy,
> którą trzeba pamiętać, żeby nakarmić, zostaje pusta; `is_confirmed` w
> `mirrors` domyślnie **fałszywe**, bo widoczność dla zespołu potwierdza
> człowiek.
>
> Tabela istnieje, ale **serwer jej jeszcze nie czyta** — `auto_publish` jest
> przełącznikiem po stronie wtyczki, a punkty 5–7 zadania TODO-012 (kolejka
> wyjściowa, `/ws-publish`) należą do klienta.

**`publish_batches`** — jedna partia publikacji, żeby dało się ją wycofać.
`id`, `user_id`, `agent_token_id` (gdy publikował agent), `mirror_id`
(`null` przy publikacji selektywnej), `space_id`, `source_replica`,
`mode` (`selective` / `mirror`), `drawer_count`, `skipped_count`,
`skipped_reasons` (`JSONB` — co odrzucił filtr sekretów i dlaczego),
`status` (`preview` / `applied` / `reverted`), `created_at`, `reverted_at`.

> Partia jest jednostką wycofania: „wypchnąłem nie to skrzydło" rozwiązuje się
> jednym działaniem, a nie ręcznym szukaniem szuflad. Raport pominięć jest
> częścią partii, nie osobnym dziennikiem — inaczej nikt by go nie czytał.
>
> `space_id` jest `NOT NULL`, więc partia należy do **jednej** przestrzeni.
> Dlatego jedno żądanie `POST /api/publish` daje jedną partię **na przestrzeń
> docelową** (D-036): reguła lądowania rozdziela wysyłkę na zespołowe i
> prywatną, a „zabierz to, co widzi zespół" nie może kasować tygodnia
> prywatnych transkryptów, które poleciały tym samym przebiegiem.
>
> `CHECK ((status = 'reverted') = (reverted_at IS NOT NULL))` — stan i znacznik
> czasu nie mogą się rozjechać. Dziennik czyta się po to, by odpowiedzieć „co
> cofnąłem i kiedy”, a status, który może kłamać o swoim własnym znaczniku,
> odpowiada na to źle.
>
> `status = 'preview'` **nie trafia do tabeli** — podgląd nie zapisuje niczego,
> w tym siebie. Wartość istnieje w `CHECK` i w kodzie, żeby raport miał jak się
> nazwać.
>
> `mirror_id` ma `ON DELETE SET NULL`: usunięcie mapowania nie może usunąć
> zapisu o tym, co kiedyś opublikowało. Wycofania, którego nikt już nie
> znajdzie, nikt nie wykona.

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
| dokument wiki | **jedna** szuflada w pokoju `documentation`, aktualizowana w miejscu przy każdej rewizji (D-025) + wiersz w `memory_entries` |

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
6. Lustro bez `is_confirmed` **nie kieruje niczego do przestrzeni zespołowej**.
   Treść i tak jedzie na serwer — do prywatnej przestrzeni właściciela (D-014),
   bo niezmiennikiem jest „wszystko jest na serwerze, nic nie jest widoczne dla
   zespołu bez mapowania". To samo dotyczy lustra wyłączonego, wstrzymanego i
   wykluczonego pokoju (D-036). Warunek jest w `Mirror::routes()`, pokryty
   testami `PublishServiceTest` i `DoctrinePublishBridgeTest`.
7. Wycofanie partii usuwa szuflady z pałaca i wiersze `memory_entries`, ale
   **zostawia samą partię** ze statusem `reverted` — historia publikacji się
   nie kurczy.
