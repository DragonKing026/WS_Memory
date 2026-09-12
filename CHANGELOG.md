---
noteId: "32101d30aeb011f1997d030a3cd38ca7"
tags: [ws-memory, changelog, historia-zmian]

---

# CHANGELOG — WS_Memory

Wszystkie istotne zmiany w projekcie, z datą i godziną. Najnowsze na górze.
Format: `## RRRR-MM-DD GG:MM — tytuł`.

---

## 2026-09-12 17:52 — TODO-000 ukończone: fundament działa, polska semantyka potwierdzona

**Pierwszy kod w projekcie.** Trzy usługi w Dockerze stoją, a założenie, na
którym stoi cały projekt, jest potwierdzone w działaniu, nie na papierze:

```
zapisano: „Umowa najmu lokalu wymaga aneksu przy zmianie stawki czynszu"
szukano:  „zmiana opłaty za wynajem — jakie dokumenty"
wynik:    podobieństwo 0.743, dopasowanie słów (BM25) 0.0
```

`bm25_score = 0.0` jest tu istotą dowodu: wyszukiwarka nie miała ani jednego
wspólnego słowa, więc trafienie jest wyłącznie semantyczne. Wymiar wektora w
bazie to **1024**, czyli pracuje `bge-m3`, a nie domyślny 384-wymiarowy
`minilm`.

**Powstało:** `docker-compose.yml` (postgres 18 + pgvector, TEI z bge-m3,
mempalace 3.7.0), `docker/mempalace/` (obraz z `psycopg` i oczekiwaniem na
embeddingi), `docker/postgres/init/` (schematy `palace` i `ws`, dwie role z
rozdzielonymi uprawnieniami), `docker/wygeneruj-sekrety.sh`, `.env.example`,
`README.docker.md`, `test/semantyka.sh` z `test/sprawdz_odpowiedz.py`.

**Cztery rzeczy wyszły dopiero w działaniu:**

1. **Postgres 18 zmienił konwencję montowania** — wolumen na
   `/var/lib/postgresql`, nie na `/var/lib/postgresql/data`. Stara ścieżka
   kończy się odmową startu.
2. **Obraz TEI jest distroless** — brak `curl`, `wget`, `nc` i powłoki, więc
   healthcheck Dockera jest tam niewykonalny. Zastąpiony oczekiwaniem w
   entrypoincie `mempalace` i udokumentowanym poleceniem diagnostycznym.
   Pierwotny plan zadania zakładał healthcheck przez `/health` — nie dało się.
3. **Wyszukiwanie degraduje się cicho.** Przy niedostępnym serwerze
   embeddingów MemPalace zwraca **poprawną** odpowiedź JSON-RPC z błędem
   ukrytym w treści narzędzia i pustą listą wyników. Pierwsza wersja testu
   postawiła z tego powodu fałszywą diagnozę. Rozróżnienie trafiło do testu
   i do monitorowania w `docs/05-deployment.md` — alert patrzący tylko na kod
   HTTP tej awarii nie zobaczy.
4. **`bge-m3` potrzebuje ~2–3 GB RAM** po załadowaniu i 1–3 minut na start
   z cache'u. Przy braku pamięci kontener się przeładowuje, a wyszukiwanie
   w tym czasie zwraca puste wyniki.

Zadanie przeniesione do `TODO/DONE/` z pełnym zapisem weryfikacji.

---

## 2026-09-12 19:05 — Pomiary modeli embeddingów i limity zasobów

Domknięcie incydentu z TODO-000: serwer embeddingów zajął 21 GB i zdławił
maszynę deweloperską. Zamiast zgadywać lżejszy model, zmierzono cztery
kandydaty na trzech polskich parach zdań (`test/porownaj-modele.py`, każdy z
twardym limitem pamięci, sprzątanie po każdym).

**Wynik obalił dwie pozorne oczywistości:**

- **`bge-m3` po dostrojeniu zajmuje 2,2 GB, nie 21 GB.** Te 19 GB to była
  wyłącznie rezerwacja buforów TEI (`--max-batch-tokens 16384` i tyle wątków
  tokenizacji, ile rdzeni) przy modelu z oknem 8192 tokenów. Model zostaje.
- **Najlepszy margines miał `paraphrase-multilingual-MiniLM` (0,281) — i to
  pułapka.** Ma okno **128 tokenów**, więc ucinałby każdą szufladę po ~90
  słowach, cicho i bez błędu. Odrzucony.
- Rodzina **E5 wypadła najgorzej** (margines 0,034–0,055) i potwierdziła
  pierwotny argument D-003: wymuszony wspólny prefiks `query: ` po obu
  stronach ściska podobieństwa w paśmie wokół 0,8 — para kontrolna dostaje
  0,804, dokładnie tyle co trafna.

**Prawdziwą przyczyną awarii był brak `mem_limit` w compose**, nie wybór
modelu. Kontener bez limitu bierze całą pamięć maszyny, więc pomyłka w
konfiguracji zamieniła się w zdławienie komputera. Limity są teraz na
wszystkich usługach i zostają także na produkcji jako druga linia obrony.

- Zmierzony stan pod limitami: embeddingi 2,12 GB z 4 GB, mempalace 66 MB,
  Postgres 31 MB — cały stos poniżej 2,3 GB.
- D-003 uzupełniona o tabelę pomiarów i wymóg strojenia buforów.
- `docs/05-deployment.md`: realne liczby zamiast szacunków, uzasadnienie limitów.
- Nowe narzędzie `test/porownaj-modele.py` — stanowisko do porównywania modeli
  na polskich parach zdań, przydatne przy każdej przyszłej zmianie modelu.

---

## 2026-09-12 18:10 — Lokalny pałac pierwotny, serwer trzyma kopię

Doprecyzowanie kierunku: praca dzieje się lokalnie, serwer dostaje **kopię**.
Wtyczka WS_Memory istnieje właśnie po to, żeby ta kopia powstawała — kto chce
pracować wyłącznie lokalnie, instaluje samo MemPalace i nie zakłada konta.
Przełącznik `auto_publish` schodzi do roli hamulca awaryjnego.

**D-015** dokłada wymaganie, którego wcześniej nie było i które wynika wprost
z tego ujęcia:

> Zapis lokalny nigdy nie czeka na serwer i nigdy nie zawodzi z jego powodu.

- **Lokalna kolejka wyjściowa** (`~/.ws-memory/outbox/`): brak sieci, padnięty
  serwer czy jego aktualizacja nie przerywają pracy; szuflady czekają i
  dopinają się przy następnej okazji. Ponowna wysyłka jest bezpieczna dzięki
  odsiewowi, który już mamy.
- Znacznik przesuwa się **dopiero po potwierdzeniu przez serwer**, więc
  przerwanie w połowie partii niczego nie gubi.
- Skutek szerszy: baza wiedzy przestaje być pojedynczym punktem awarii dla
  codziennej pracy, a aktualizacja serwera nie wymaga okna serwisowego.
- Bez zmian: **nie ma synchronizacji w drugą stronę.** Wiedzę zespołu agent
  czyta na żywo przez `ws_search`; ściąganie jej do lokalnych pałaców
  oznaczałoby dwukierunkową synchronizację, odrzuconą w D-004.
- Reguła nienaruszalna nr 11 (numeracja przesunięta): zapis lokalny nigdy nie
  czeka na serwer.

---

## 2026-09-12 17:52 — Wysyłka na serwer domyślna, tryb ręczny jako wyłącznik

Odwrócone domyślne zachowanie. Wcześniej publikacja była czynnością, którą
trzeba pamiętać; teraz **wszystko, co trafia do lokalnego pałaca, jedzie na
serwer samo**. Powód: baza wiedzy wypełniana tym, co ktoś akurat uznał za warte
kliknięcia, wypełnia się prawie niczym.

**D-014** wprowadza **regułę lądowania**, bez której domyślna wysyłka byłaby
wyciekiem: skrzydło zmapowane → przestrzeń zespołowa, skrzydło niezmapowane →
prywatna przestrzeń właściciela na serwerze. Potwierdzenie człowieka przenosi
się z publikacji na **mapowanie**, bo to ono decyduje o widoczności dla innych.

- Nazwane wprost: **tekst zmielonego kodu trafia na serwer** (jako szuflady).
  Właściwość „kod nie opuszcza laptopa" zmienia się w „kod nie opuszcza serwera
  firmy". Mielenie pozostaje lokalne, więc serwer nadal nie ma dostępu do
  repozytoriów ani kluczy do gita.
- Odsiew powtórzeń: `content_hash` w `memory_entries` z indeksem
  `(space_id, content_hash)`. Gdy nazwa lokalnego skrzydła odpowiada
  przestrzeni zespołowej, wtyczka proponuje mapowanie — wtedy trzy osoby
  mielące to samo repozytorium nie tworzą trzech kopii.
- Nowa tabela `publish_settings` z `auto_publish` domyślnie `true`;
  przełącznik również w `userConfig` wtyczki.
- Filtr sekretów leży teraz na **każdej** ścieżce, nie tylko na świadomie
  uruchomionej — jego testy stają się krytyczne.
- Dobór mocy usługi `embeddings`: serwer liczy wektory dla całego strumienia
  wszystkich maszyn, nie dla wybranych fragmentów.
- `TODO-012` rozszerzone o wysyłkę automatyczną, regułę lądowania, odsiew
  powtórzeń i testy negatywne widoczności.

---

## 2026-09-12 17:30 — Przenośność na inne klienty AI

Pytanie z biura: skoro MemPalace działa nie tylko z Claude, czy struktura
wtyczek nie zablokuje nas później przy Codeksie? Sprawdzono, jak zrobił to
MemPalace — utrzymuje cztery pakowania naraz (`.claude-plugin`,
`.codex-plugin`, `.cursor-plugin`, `.antigravity-plugin`) plus wspólną treść
protokołów w `integrations/shared/`. Codex ma **ten sam kształt `hooks.json`**
co Claude i jeden skrypt przyjmujący nazwę zdarzenia; Cursor nie ma hooków
wcale, tylko `mcp.json` i reguły.

**D-013** — budujemy najpierw dla Claude Code, ale trzy zasady utrzymują
przenośność tanim kosztem:

1. Cała wartość mieszka na serwerze. Gateway to zwykły serwer MCP po HTTP,
   więc Codex, Cursor, Zed czy Antigravity połączą się z nim **bez zmian po
   naszej stronie**, z identycznymi gwarancjami bezpieczeństwa — bo model
   uprawnień nie jest we wtyczce.
2. Treść instrukcji ma jedno źródło (`plugin/shared/`) i jest wystawiona także
   jako **zasoby MCP**. Skutek uboczny: zmiana instrukcji to deploy serwera,
   a nie aktualizacja wtyczki u dwunastu osób.
3. Części nieprzenośne trzymamy minimalne — **jeden** skrypt hooka z argumentem
   zdarzenia zamiast trzech osobnych.

- Reguła nienaruszalna nr 11: nic wartościowego nie mieszka we wtyczce.
- Struktura `plugin/` przebudowana: `shared/` (treść) + `.claude-plugin/`
  (powłoka); `.codex-plugin/` powstanie dopiero, gdy ktoś użyje Codeksa.
- Nie oparto niczego na promptach MCP — nie zweryfikowano, jak klienty je
  wystawiają. Zasoby i opisy narzędzi wystarczają.

---

## 2026-09-12 17:15 — Jedna droga: mielenie wyłącznie lokalne

Konsekwencja hybrydy, doprowadzona do końca. Skoro każdy może mielić u siebie,
druga — serwerowa — droga wnoszenia wiedzy jest zbędna.

Zweryfikowano w dokumentacji Claude Code, że da się to zrobić czysto:

- **`plugin.json` ma pole `dependencies`** — wtyczka WS_Memory deklaruje
  wymaganie wtyczki `mempalace`, więc każdy użytkownik dostaje lokalny pałac,
  instalując jedną rzecz.
- **Marketplace obsługuje `source: {"type": "command"}`** — polecenie przed
  instalacją, czyli miejsce na `mempalace[extract]` i pierwsze `init`.
- **`userConfig`** — adres i token pytane przy włączeniu wtyczki, token
  `sensitive`, dostępny jako `${user_config.KEY}` w MCP i
  `CLAUDE_PLUGIN_OPTION_*` w hookach. Zastępuje ręczne zmienne środowiskowe.

**D-012** — serwer nie mieli niczego. Znika: klonowanie repozytoriów, klucze
do gita, harmonogram nocny, wariant `extract` w obrazie serwera, endpoint
przyjmujący transkrypty, tabele `mining_jobs` i `session_uploads`, wolumen na
transkrypty oraz cała wysyłka surowych rozmów z D-006. Prywatność przestaje
być ustawieniem, a staje się właściwością architektury: **nie ma ścieżki,
którą surowa rozmowa wychodzi na serwer**.

- `TODO-010` **anulowane**; plik zostaje na miejscu ze statusem i wyjaśnieniem,
  żeby numeracja się nie przesunęła, a analiza pozostała dostępna. Jego zakres
  przejęły `TODO-009` i `TODO-012`.
- Na serwerze zostają `mempalace` (wyszukiwanie i zapis publikowanych szuflad)
  oraz `embeddings` — obie usługi nadal niezbędne, żadna nie mieli.
- **Znane ograniczenie:** osoba bez Claude Code nie wniesie PDF-a do bazy;
  zostaje jej wiki. Obejście opisane w D-012.

---

## 2026-09-12 16:52 — Hybryda: lokalny pałac plus wspólna baza

Na pytanie „czy ktoś może mieć MemPalace lokalnie i zapisywać do wspólnego"
przeprowadzono rozpoznanie podsystemu replikacji. **Ustalono, że replikacji
pałac↔pałac w 3.7.0 nie ma**: `logstream sync` synchronizuje zdarzenia
koordynacyjne i artefakty (w `logsync.py` nie ma ani jednego odwołania do
szuflad), `mempalace sync` to sprzątanie po usuniętych plikach, a
`replica.json` i `patch_submit` to fundament pod przyszły mesh.

Mostek zbudowano więc z tego, co jest — i wyszedł lepszy niż wersja
w pełni serwerowa:

- **D-010** — hybryda. Deweloper może mieć własny lokalny MemPalace (własne
  `init` i `mine`, **kod nie opuszcza laptopa**) i publikować wybraną wiedzę
  do wspólnej bazy **przez API**. Agent ma dwa serwery MCP: lokalny i wspólny.
  Publikacja selektywna (`/ws-publish`) oraz **lustro** — cykliczne mapowanie
  skrzydła na przestrzeń.
- Zabezpieczenia lustrzenia, bo lustro pracuje bez nadzoru: pierwszy przebieg
  jest podglądem wymagającym potwierdzenia, wykluczenia pokoi, filtr sekretów
  po obu stronach, partie z możliwością wycofania, przyrostowość.
- **Odrzucono** wariant z `MEMPALACE_PGVECTOR_DSN` na centralną bazę: prostszy,
  ale omija token, role i audyt — czyli unieważnia powód istnienia WS_Memory.
  Zapisano jako regułę nienaruszalną nr 10.
- **D-011** — `MEMPALACE_ENTITY_LANGUAGES=pl,en` i `init --no-llm`. Wykrywanie
  encji działa domyślnie po angielsku; to ta sama cicha wada co domyślny
  `minilm`, tylko dotyczy grafu wiedzy.
- **D-003 uzupełniona**: wymóg jednego modelu embeddingów dotyczy wyłącznie
  serwera. Hybryda współdzieli tekst, nie wektory, więc laptopy mogą mieć
  dowolny model.
- **D-006 uzupełniona**: wysyłka transkryptów to droga domyślna, nie jedyna —
  kto ma lokalny pałac, mieli rozmowy u siebie.
- Model danych: `mirrors`, `publish_batches`, oraz `source_replica` +
  `source_drawer_id` w `memory_entries` z unikalnością na parze źródłowej
  (idempotencja publikacji).
- Nowe zadanie **TODO-012** — mostek hybrydowy.
- Nagłówki zadań ujednolicone do formatu **`TODO-NNN`**, żeby nie myliły się
  z numerami decyzji (`D-NNN`).
- Uzupełniono `tags` w nagłówkach YAML wszystkich 25 dokumentów.

---

## 2026-09-12 16:12 — Spec projektowy i dwanaście zadań wdrożeniowych

- `docs/superpowers/specs/2026-09-12-ws-memory-design.md` — spec utrwalający
  decyzje, kryteria ukończenia projektu i ryzyka wraz z ich obsługą.
- `TODO/000` … `TODO/011` — **dwanaście** zadań, każde z sekcjami Powód,
  Analiza, Rozwiązanie i sprawdzalnymi Kryteriami ukończenia.
- `TODO/README.md` — zasady prowadzenia zadań, przenoszenia do `DONE/`
  i graf zależności między zadaniami.
- `.gitignore` — wykluczenia dla Symfony, Node, Dockera i sekretów.

Kolejność wynika z jednej zasady: zadanie `000` nie zawiera ani linii Symfony,
bo najpierw trzeba udowodnić, że polskie zapytanie znajduje polską treść przez
centralny serwer embeddingów. Budowanie interfejsu nad nieudowodnionym
fundamentem byłoby marnotrawstwem.

---

## 2026-09-12 16:02 — Rozdzielenie backendu i frontendu

Na wniosek zmieniono warstwę prezentacji: zamiast monolitu z Twigiem — czyste
API Symfony plus osobna aplikacja Vue 3. Wzorzec przeniesiony z Precision
Telemed 2.0 (sprawdzony w zespole), potwierdzony wyszukiwaniem w pałacu.

- **D-008** — rozdzielenie backendu i frontendu; backend wystawia REST `/api`
  i MCP `/mcp` nad wspólnymi serwisami domenowymi, frontend to osobna
  aplikacja Vue 3 + Vite 7 + Nuxt UI 4 + Tailwind 4 + Pinia + Zod.
  `nginx` trzyma oba na jednym origin, więc przeglądarka nie dotyka CORS-a.
- **D-009** — edytor wiki na CodeMirror 6, **nie** WYSIWYG. Uzasadnienie:
  dokumenty krążą między ludźmi i AI, a każdy obieg przez model dokumentu
  WYSIWYG gubi to, czego ten model nie obsługuje. Markdown jako jedyna
  reprezentacja usuwa tę klasę błędów.
- **D-001** oznaczona jako zmieniona w zakresie warstwy prezentacji.
- Dodano regułę nienaruszalną nr 9: backend nie renderuje interfejsu,
  frontend nie zna bazy; jedyny kontrakt to OpenAPI.
- Liczba usług w Compose: 6 → 7 (doszedł `frontend`); `app` przemianowany
  na `backend`.
- Nowy dokument `docs/07-frontend.md` — stack, struktura katalogów, ekrany.
- Dopisano do `AGENTS.md` konwencje gita: jeden commit = jedna zamknięta myśl,
  commit obejmuje kod razem z dokumentacją i wpisem w CHANGELOG.

---

## 2026-09-12 15:43 — Projekt zatwierdzony, dokumentacja założycielska

Etap projektowania zakończony. Kodu jeszcze nie ma.

**Rozpoznanie MemPalace 3.7.0 (jako zależności):**
- Ustalono, że `mempalace serve` wystawia HTTP MCP pod `POST /mcp` z **jednym
  wspólnym tokenem** dla wszystkich klientów — brak tożsamości per użytkownik.
  To wyznaczyło właściwy zakres WS_Memory.
- Odkryto, że MemPalace ma pełnoprawny backend **pgvector** z izolacją przez
  namespace i bezpieczeństwem przy wielu pisarzach. Pozwoliło to trzymać pałac
  i dane aplikacji w jednym Postgresie.
- Ustalono, że mechanizm hub-forward działa wyłącznie w obrębie jednej maszyny
  (wykrywanie huba przez plik w katalogu pałaca) — nie nadaje się do pracy
  zdalnej zespołu.
- Ustalono, że domyślny model embeddingów `minilm` jest trenowany **tylko na
  angielskim**, co dyskwalifikuje go dla bazy pisanej po polsku.

**Podjęte decyzje** (szczegóły w `docs/06-decyzje.md`):
- D-001 — Symfony 8 + MemPalace jako sidecar
- D-002 — PostgreSQL 18 + pgvector zamiast firmowego MariaDB
- D-003 — centralny serwer embeddingów, model `BAAI/bge-m3`
- D-004 — Postgres źródłem prawdy dla wiki, pałac warstwą wyszukiwania
- D-005 — agent zapisuje bez bramki; wersjonowanie jako siatka bezpieczeństwa
- D-006 — zamknięta sieć; hooki wysyłają transkrypty przez HTTPS
- D-007 — kurowany zestaw narzędzi MCP zamiast przepuszczania 36 narzędzi

**Dodane pliki:**
- `AGENTS.md` — kontrakt projektu: reguły nienaruszalne, stack, workflow, git
- `README.md` — opis projektu i spis dokumentacji
- `CHANGELOG.md` — ten plik
- `docs/01-architektura.md` … `docs/06-decyzje.md` — dokumentacja działania
