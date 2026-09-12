---
noteId: "14240ca0aeb011f1997d030a3cd38ca7"
tags: [ws-memory, kontrakt-projektu, architektura, konwencje, agenci-ai]

---

# AGENTS.md — WS_Memory

Ten plik jest kontraktem dla każdego agenta AI i każdej osoby, która pracuje
nad **WS_Memory**. Czytasz go przed pierwszą zmianą w repozytorium.

Ostatnia aktualizacja: 2026-09-12 20:15 CEST

---

## 1. Czym jest WS_Memory

Wspólna baza wiedzy i dokumentacja Web Systems — **jedna dla ludzi i dla
modeli AI**. Człowiek loguje się do aplikacji internetowej i pisze
dokumentację; agent AI czyta tę samą wiedzę przez MCP i sam do niej dopisuje.
Nie ma dwóch źródeł prawdy i nie ma eksportów między nimi.

WS_Memory **nie jest** kolejną implementacją pamięci wektorowej. Silnikiem
pamięci jest [MemPalace](https://github.com/MemPalace/mempalace) 3.7.0, użyty
jako zależność. WS_Memory dokłada do niego dokładnie to, czego MemPalace nie
ma, a czego potrzebuje zespół:

| MemPalace daje | WS_Memory dokłada |
|---|---|
| Magazyn wektorowy, wyszukiwanie semantyczne + leksykalne | **Tożsamość** — kto zapisał, kto czyta, kto zatwierdził |
| 36 narzędzi MCP, HTTP MCP przez `mempalace serve` | **Uprawnienia** — przestrzenie i role, kurowany zestaw narzędzi |
| Miner (kod, PDF/DOCX, transkrypty rozmów) | **Interfejs dla ludzi** — wiki z rewizjami, diffem i rollbackiem |
| Hooki `session-start`, `stop`, `session-end`, `precompact` | **Plugin firmowy** — instrukcje, skille, podagenci, hooki bez Pythona |
| Graf wiedzy, dziennik, artefakty | **Deployment zespołowy** — Docker, backup, audyt |

## 2. Nienaruszalne reguły

Łamanie którejkolwiek z nich to błąd krytyczny, nie kwestia gustu.

1. **Agent nigdy nie dostaje tokena MemPalace ani DSN-u do bazy.** Dostaje
   swój token do `/mcp` w Symfony. Gateway jest jedyną drogą.
2. **Żadne narzędzie MCP nie ma parametru „autor".** Tożsamość wynika z
   tokena. Podszycie się musi być niewyrażalne w API, nie tylko zabronione.
3. **Nie odpytujemy pałaca bez filtra przestrzeni.** Każde zapytanie ma
   `wing IN (przestrzenie, do których ten token ma prawo)`. Filtrowanie
   wyników *po* pobraniu jest wyciekiem, nie uprawnieniem.
4. **Token agenta nigdy nie ma więcej uprawnień niż jego właściciel.**
   Może mieć mniej. Nigdy więcej.
5. **Agent nie weryfikuje własnych wpisów.** Narzędzia `ws_doc_verify` nie ma
   i nie będzie — weryfikacja to czynność człowieka w interfejsie.
6. **Zapis bez wskazanej przestrzeni ląduje w prywatnej przestrzeni
   właściciela tokena.** Pomyłka agenta nie zaśmieca wspólnej bazy.
7. **Model embeddingów jest jeden dla całego systemu i nie zmienia się
   bez migracji.** Zmiana modelu unieważnia wszystkie wektory w bazie.
   Szczegóły: `docs/06-decyzje.md`, decyzja D-003.
8. **Postgres, mempalace i embeddings nie mają portów na hoście.** Jedyne
   wejście z zewnątrz to nginx.
9. **Backend nie renderuje interfejsu, frontend nie zna bazy.** Jedyny
   kontrakt to OpenAPI. Każde obejście tej granicy (szablon w backendzie,
   zapytanie SQL z frontendu) to błąd architektoniczny.
10. **Lokalny pałac nigdy nie pisze wprost do centralnej bazy.** Publikacja
    idzie przez API, bo tylko tam działają token, role i audyt. Dawanie
    `MEMPALACE_PGVECTOR_DSN` na zewnątrz unieważniłoby całą warstwę uprawnień.
11. **Zapis lokalny nigdy nie czeka na serwer.** Niedostępny serwer nie może
    przerwać ani opóźnić pracy — szuflady czekają w kolejce wyjściowej.
    Baza wiedzy nie jest pojedynczym punktem awarii dla codziennej pracy.
12. **Nic wartościowego nie mieszka we wtyczce.** Narzędzia, uprawnienia i
    instrukcje żyją na serwerze; wtyczka je tylko podłącza. Reguła nie jest
    estetyczna — decyduje o tym, czy port na inny klient AI to godziny czy
    tygodnie (D-013).

## 3. Architektura w jednym akapicie

**Backend i frontend są rozdzielone** — wzorzec przeniesiony z Precision
Telemed 2.0. Backend to czyste API Symfony, które działa niezależnie od
frontendu i ma własny kontrakt; frontend to aplikacja Vue 3 na Vite,
budowana osobno. Żadna część nie renderuje HTML-a drugiej.

Siedem usług w Dockerze (sześć już działa, brakuje `frontend` — TODO-006).
`nginx` terminuje TLS i jest jedynym wejściem; kieruje
`/` na frontend, a `/api` i `/mcp` na backend — **ten sam origin, więc
przeglądarka w ogóle nie dotyka CORS-a**. `backend` (Symfony 8 / PHP 8.4)
wystawia dwie powierzchnie nad tą samą logiką domenową: REST `/api` dla
frontendu i **gateway MCP** `/mcp` dla agentów. `frontend` (Vue 3 + Vite) to
w produkcji statyczne pliki, w dev kontener z HMR. `worker` (Symfony Messenger)
publikuje dokumenty do pałaca i przetwarza partie publikacji. `mempalace`
(`mempalace serve`) jest jedynym komponentem, który umie szukać semantycznie
i zapisywać szuflady — **nie mieli** (D-012). `embeddings` wystawia `/v1/embeddings` z modelem wielojęzycznym.
`postgres` (18 + pgvector) trzyma **wszystko** w dwóch schematach: `palace`
(tabele MemPalace) i `ws` (dane aplikacji). Jeden `pg_dump` to pełny backup
systemu.

Pełny opis: `docs/01-architektura.md`.

### Jak wiedza wchodzi do systemu (D-010 + D-012)

**Serwer nie mieli niczego.** Wtyczka WS_Memory wymaga wtyczki MemPalace jako
zależności, więc **każdy użytkownik ma lokalny pałac**. Mielenie projektów,
dokumentów i transkryptów rozmów dzieje się wyłącznie na jego maszynie; serwer
dostaje gotowe szuflady, nigdy surowe źródła.

Agent ma dwa serwery MCP naraz: `mempalace` (lokalny, prywatny) i `ws_memory`
(wspólny). Do wspólnej bazy wiedza trafia dwiema drogami:

1. **Pisanie w wiki** — człowiek albo agent, przez `/api` lub `ws_doc_write`.
2. **Wysyłka z lokalnego pałaca — domyślna, bez udziału użytkownika** (D-014).
   Wszystko, co trafia do lokalnego pałaca, jedzie na serwer. Reguła lądowania:
   skrzydło **zmapowane** → przestrzeń zespołowa (mapowanie potwierdza człowiek
   raz), skrzydło **niezmapowane** → **prywatna przestrzeń właściciela**.
   Tryb ręczny (`/ws-publish`) to wyłącznik w ustawieniach wtyczki.

Wszystko jest więc na serwerze zawsze, ale nic nie staje się widoczne dla
zespołu bez mapowania. Wysyłka idzie **zawsze przez API**, nigdy wprost do
bazy — inaczej ominęłaby token, role i audyt.

Konsekwencja nazwana wprost: **tekst zmielonego kodu trafia na serwer** (jako
szuflady). Mielenie pozostaje lokalne, więc serwer nie potrzebuje dostępu do
repozytoriów ani kluczy do gita.

**Lokalny pałac jest pierwotny, serwer trzyma kopię** (D-015). Zapis lokalny
nigdy nie czeka na serwer i nigdy nie zawodzi z jego powodu — niewysłane
szuflady czekają w lokalnej kolejce. Wtyczka istnieje po to, żeby ta kopia
powstawała; kto chce pracować wyłącznie lokalnie, instaluje samo MemPalace.

## 4. Trzy klasy wiedzy

Rozróżnienie kluczowe — pomylenie ich prowadzi do złych decyzji projektowych.

1. **Pamięć surowa** (główny wolumen, automatyczna): transkrypty sesji,
   dziennik, graf wiedzy, mining repozytoriów. Powstaje **w lokalnym pałacu**
   i domyślnie jedzie na serwer — do przestrzeni zespołowej, jeśli skrzydło
   jest zmapowane, w przeciwnym razie do prywatnej. Nie wersjonujemy tego —
   to surowiec.
2. **Ustalenia i notatki**: agent zapisuje wprost przez `ws_remember`.
   Widoczne w aplikacji, oznaczone jako zapisane przez AI. Bez bramki.
3. **Dokumentacja kanoniczna (wiki)**: dokumenty z pełnymi rewizjami.
   **Agent też pisze wprost** przez `ws_doc_write`. Dostaje status „autor: AI"
   i osobną flagę „zweryfikowane przez człowieka" — to **znacznik zaufania,
   nie brama**. Dokument jest natychmiast widoczny i wyszukiwalny; człowiek
   może go potwierdzić albo cofnąć do dowolnej rewizji.

Wersjonowanie jest siatką bezpieczeństwa, **nie kolejką zatwierdzeń**.
Kolejka propozycji istnieje, ale jest opcją włączaną per przestrzeń — tylko
tam, gdzie treść naprawdę tego wymaga.

## 5. Stack

### Backend — działa niezależnie od frontendu

| Warstwa | Technologia | Uwaga |
|---|---|---|
| Framework | Symfony 8.0, PHP 8.4 | jak w głównej aplikacji Symfony zespołu |
| API | API Platform 4.3 | `/api`, kontrakt OpenAPI |
| Uwierzytelnianie | JWT (dostępowy + odświeżający) | API bezstanowe; agenci osobnym tokenem |
| Baza | **PostgreSQL 18 + pgvector** | odejście od firmowego MariaDB — D-002 |
| ORM | Doctrine ORM 3.x + Migrations | |
| Kolejki | Symfony Messenger | transport: Doctrine |
| Pamięć | MemPalace 3.7.0, backend pgvector | zależność, czarna skrzynka |
| Embeddingi | `BAAI/bge-m3` przez `/v1/embeddings` | 1024 wymiary, bez prefiksów — D-003 |
| Testy | PHPUnit | |

> Ograniczenie: `doctrine:schema:validate` i `migrations:diff` wymagają DBAL
> `^4.5`, a stabilne jest 4.4.4. Migracje piszemy ręcznie, mapowanie
> sprawdzamy `--skip-sync` (`docs/05-deployment.md`).

### Frontend — osobna aplikacja, wzorzec z nowszego projektu z frontendem Vue

| Warstwa | Technologia |
|---|---|
| Framework | Vue 3 (`<script setup>` + TypeScript) |
| Build | Vite 7, pnpm 10, Node 22 |
| UI | Nuxt UI 4 + Tailwind 4 |
| Stan | Pinia |
| Routing | vue-router 5, routing plikowy (`unplugin-vue-router`) |
| Walidacja | Zod 4 |
| HTTP | Axios |
| Edytor wiki | **CodeMirror 6** + podgląd Markdown — patrz D-009 |
| Testy | Vitest 4, Playwright (E2E) |

Struktura `frontend/src/` jak w 2.0: `pages/` (routing plikowy), `features/<domena>/`,
`components/<domena>/`, `composables/`, `stores/`, `api/client.ts`, `utils/`, `layouts/`.

## 6. Jak pracujemy w tym repozytorium

### Dokumentacja jest częścią zadania, nie dodatkiem

- **`docs/`** — opis działania systemu, aktualizowany na bieżąco wraz z kodem.
  Zmieniasz zachowanie → aktualizujesz odpowiedni plik w tym samym zadaniu.
- **`docs/06-decyzje.md`** — każda decyzja techniczna z uzasadnieniem i
  odrzuconymi alternatywami. Nowa decyzja = nowy numer `D-00x`, nigdy edycja
  starej (starą oznaczamy jako zastąpioną).
- **`TODO/`** — ponumerowane zadania. Jeden plik = jedno zadanie, z sekcjami:
  **Powód**, **Analiza**, **Rozwiązanie**, **Kryteria ukończenia**.
- **`TODO/DONE/`** — po ukończeniu **przenosisz** tam plik zadania (`git mv`)
  i dopisujesz sekcję **Co zostało zrobione** z datą, godziną i faktami:
  co powstało, co przetestowano, co odłożono i dlaczego.
- **`CHANGELOG.md`** — wpis przy każdej zmianie, z datą i godziną.
- **`docs/en/`** — angielski odpowiednik każdego pliku z `docs/`, aktualizowany
  w tym samym commicie co polski oryginał.

#### Definicja ukończenia — zadanie NIE jest skończone, dopóki

Reguła „aktualizuj dokumentację" jest bezużyteczna, dopóki nie da się jej
sprawdzić. Dlatego lista jest jawna i przechodzi się ją **przed commitem**:

1. **Zmieniło się zachowanie systemu?** → poprawiony odpowiedni plik w `docs/`
   **oraz** jego angielski odpowiednik w `docs/en/`.
2. **Zmieniła się struktura danych?** → `docs/02-model-danych.md` zgodny ze
   stanem migracji.
3. **Podjęto decyzję techniczną?** → nowy numer `D-0xx` w `docs/06-decyzje.md`
   z uzasadnieniem **i odrzuconymi alternatywami**; starych decyzji nie
   edytujemy, tylko oznaczamy jako zastąpione.
4. **Zmieniła się konfiguracja lub deployment?** → `docs/05-deployment.md`
   i `.env.example`.
5. **Zmienił się kontrakt API lub zestaw narzędzi MCP?** →
   `docs/03-mcp-gateway.md`.
6. **Zawsze** → wpis w `CHANGELOG.md` z datą i godziną.
7. **Zadanie ukończone?** → sekcja **Co zostało zrobione** i `git mv` do
   `TODO/DONE/`.

Sprawdzenie mechaniczne: `make sprawdz-dokumentacje` wychwytuje brakujące
odpowiedniki angielskie i rozjazd numerów decyzji. Nie zastąpi punktów 1–7,
bo żaden skrypt nie wie, czy opis odpowiada rzeczywistości — ale wyłapie to,
co da się wyłapać.

**Dlaczego to jest twarda reguła, a nie dobra praktyka:** dokumentacja, która
raz skłamie, przestaje być czytana. A ta konkretna dokumentacja jest wsadem dla
agentów AI — nieaktualny opis nie tylko wprowadza w błąd człowieka, ale zostaje
przez model potraktowany jako fakt i powielony w kolejnych decyzjach.

### Git — commitujemy każdy zamknięty krok

Zmiana bez commita nie istnieje. Zasady:

- **Jeden commit = jedna zamknięta myśl.** Zadanie z `TODO/` może dać kilka
  commitów, ale żaden commit nie łączy dwóch niezwiązanych rzeczy.
- **Commit obejmuje kod razem z dokumentacją i wpisem w `CHANGELOG.md`.**
  Nie zostawiamy dokumentacji „na potem" w osobnym commicie.
- **Format wiadomości** — po polsku, tryb rozkazujący, z prefiksem obszaru:

  ```
  <obszar>: <co zrobiono>

  <dlaczego, jeśli nieoczywiste>
  <co przetestowano>
  ```

  Obszary: `docs`, `infra`, `backend`, `frontend`, `mcp`, `wiki`, `plugin`, `db`, `test`, `todo`.
  Przykład: `mcp: dodaj ws_search z twardym filtrem przestrzeni`.
- **Przeniesienie zadania do `DONE/` robimy przez `git mv`**, żeby historia
  pliku została zachowana.
- **Commitujemy natychmiast po zamknięciu zmiany, nie na koniec zadania.**
  Skończony plik, poprawiona konfiguracja, przetłumaczony dokument, naprawiony
  błąd — commit od razu, także przy drobiazgach. Zadanie z `TODO/` daje zwykle
  kilkanaście commitów, nie jeden.

  Powód jest praktyczny, nie estetyczny: historia gita ma pokazywać **przebieg
  pracy**, a nie jej wynik. Jeden commit „zrobione wszystko" nie da się
  przejrzeć, cofnąć w części ani zrozumieć po miesiącu. Do tego praca
  niezacommitowana ginie przy każdej awarii i koliduje z równoległymi sesjami
  w tym samym repozytorium.
- Nie commitujemy: sekretów, tokenów, `.env` z realnymi danymi, dumpów bazy,
  katalogu `vendor/`, plików modeli embeddingów.
- **Nie przepisujemy historii, która trafiła na `origin`.** Żadnego `--amend`,
  `rebase` ani `push --force` na commicie, który jest już wypchnięty — nawet
  dla literówki w opisie. Przed jakąkolwiek zmianą historii sprawdzamy
  `git status -sb`; jeśli widnieje tam `origin`, poprawka idzie **nowym**
  commitem. Zdarzyło się już inaczej (2026-09-12) i skutkiem były dwie wersje
  tego samego commitu, rozjechane między maszyną a GitHubem.
- **To repozytorium ma zdalne i bywa wypychane także spoza tej sesji.** Przed
  zmianą historii i przed commitem warto zerknąć na `git log --oneline -3`,
  żeby nie nadpisać cudzej pracy.

### Zanim zaczniesz zadanie

1. Przeczytaj `TODO/` — zadania mają kolejność i zależności.
2. Przeczytaj `docs/06-decyzje.md` — nie podważaj rozstrzygniętych decyzji
   bez nowego argumentu.
3. Jeśli używasz MemPalace jako pamięci własnej sesji: szukaj **przed**
   odpowiedzią o przeszłych ustaleniach. Nie zgaduj.

### Struktura kodu i wzorce projektowe

Budujemy z myślą o rozbudowie, nie o dowiezieniu pierwszej wersji. Poniższe
nie jest ozdobnikiem — każdy z tych wyborów odpowiada konkretnej zmianie,
która na pewno nadejdzie.

**Warstwy w `backend/src/`:**

```
Domain/          reguły biznesowe — BEZ Symfony, BEZ Doctrine, BEZ MemPalace
Application/     przypadki użycia: komendy, handlery, zapytania
Infrastructure/  Doctrine, HTTP, MemPalace, Messenger — implementacje portów
Presentation/    wejścia: Api/ (REST), Mcp/ (gateway), Console/
```

Zależności idą **tylko do środka**: `Presentation` → `Application` → `Domain`.
`Infrastructure` implementuje interfejsy z `Domain`, nigdy odwrotnie. Sprawdzian
jest prosty: gdyby jutro trzeba było wymienić Doctrine albo MemPalace, ile
plików w `Domain/` trzeba tknąć? Odpowiedź ma brzmieć „zero".

**Wzorce, których używamy świadomie i po co:**

| Wzorzec | Gdzie | Jaka przyszła zmiana to uzasadnia |
|---|---|---|
| **Port i adapter** | `Domain\Memory\MemoryStore` ← `Infrastructure\MemPalace\McpMemoryStore` | MemPalace to zależność zewnętrzna (D-001); jego aktualizacja albo podmiana nie może dotykać logiki |
| **Dekorator** | łańcuch wokół narzędzi MCP: uprawnienia → audyt → limit tempa → narzędzie | każda z tych warstw dokłada się do **wszystkich** narzędzi; wpisana w każde z osobna rozjedzie się przy pierwszym nowym |
| **Rejestr usług tagowanych** | narzędzia MCP i REST | dodanie narzędzia ma być dodaniem klasy, nie edycją pięciu miejsc |
| **Komenda i handler** (Messenger) | każdy zapis zmieniający stan | publikacja i mielenie muszą dać się przenieść w tło bez przepisywania |
| **Strategia** | reguła lądowania (D-014), filtr sekretów, źródła wiedzy | reguł będzie przybywać; `if`-y w jednej metodzie nie skalują się |
| **Obiekty wartości** | `SpaceId`, `Actor`, `DrawerId` | tożsamość nie może być gołym stringiem, który da się pomylić z innym stringiem |
| **Repozytorium za interfejsem** | `Domain\...\Repository` ← Doctrine | testy jednostkowe uprawnień bez bazy |

**Czego nie robimy:** nie budujemy abstrakcji „na wszelki wypadek". Wzorzec
wchodzi wtedy, gdy potrafimy nazwać zmianę, której ma służyć — a powyższe
zmiany są w `TODO/`, nie w wyobraźni.

### Język

**W kodzie wszystko po angielsku** — nazwy klas, metod, zmiennych, tabel,
kolumn, kluczy w JSON-ie **oraz komentarze i PHPDoc**. Taka jest konwencja
główna aplikacja Symfony zespołu (`SendContractEndRemindersCommand`, `AuthEndpoint`) i nie wprowadzamy
drugiej. Dotyczy też komentarzy w plikach konfiguracyjnych kodu
(`services.yaml`, `doctrine.yaml`) oraz nazw testów.

**Dokumentacja dwujęzycznie.** Polska wersja jest **wiodąca** — w niej
zapadają decyzje i ona rozstrzyga spory. Angielskie odpowiedniki mieszkają
w `docs/en/` i aktualizuje się je **w tym samym commicie** co polski oryginał,
tak jak resztę dokumentacji (patrz zasada wyżej).

Dlaczego polski jest wiodący, a nie odwrotnie: dwie wersje zawsze się
rozjeżdżają, a rozjazd trzeba móc rozstrzygnąć jednym zdaniem zamiast
dyskusją. Zespół pracuje po polsku, więc tam powstaje myśl — angielska wersja
jest tłumaczeniem, nie równoległym źródłem.

Poza kodem po polsku zostają: `CHANGELOG.md`, zadania w `TODO/`, komunikaty
widoczne dla użytkownika w interfejsie oraz opisy commitów.

Wyjątek techniczny: pliki dokumentacji i zadań mają polskie nazwy, bo są
czytane, nie importowane.

### Kolejność implementacji

Testy przed kodem tam, gdzie dotyczy to uprawnień, rewizji i protokołu MCP.
W tych trzech obszarach błąd jest cichy — ujawnia się jako wyciek danych albo
utrata treści, nie jako wyjątek. Test negatywny („agent *nie* widzi obcej
przestrzeni") jest tu ważniejszy od pozytywnego.

### Czego nie robimy

- Nie przepuszczamy 36 narzędzi MemPalace na wylot do agentów.
- Nie implementujemy własnego magazynu wektorowego ani własnego minera.
- Nie budujemy dwukierunkowej synchronizacji między wiki a pałacem
  (odrzucone — D-004).
- Nie dodajemy zależności, których nie ma w tabeli stacku, bez decyzji `D-00x`.

## 7. Struktura repozytorium (docelowa)

```
AGENTS.md              ← ten plik
CHANGELOG.md           ← zmiany z datą i godziną
README.md              ← szybki start
docker-compose.yml     ← siedem usług
docker/                ← Dockerfile dla backendu, frontendu, nginx + konfiguracje
docs/                  ← dokumentacja działania
  01-architektura.md
  02-model-danych.md
  03-mcp-gateway.md
  04-plugin.md
  05-deployment.md
  06-decyzje.md
  07-frontend.md
  08-backend.md
  09-ci.md
  en/                  ← angielskie odpowiedniki (polski jest wiodący)
  superpowers/specs/   ← spec projektowy z etapu projektowania
TODO/                  ← ponumerowane zadania
  DONE/                ← zadania ukończone
backend/               ← Symfony 8: API + gateway MCP (samodzielne repo-w-repo)
  src/Domain/          ← reguły biznesowe, bez frameworka
  src/Application/     ← przypadki użycia
  src/Infrastructure/  ← Doctrine, MemPalace, HTTP
  src/Presentation/    ← Api/, Mcp/, Console/
frontend/              ← Vue 3 + Vite (samodzielna aplikacja)
plugin/                ← plugin WS_Memory
  shared/              ← JEDNO źródło treści: protokoły, opisy agentów, instrukcje
  .claude-plugin/      ← cienka powłoka: plugin.json, hooks.json, skills, agents
  .codex-plugin/       ← (dopiero gdy ktoś użyje Codeksa) hooks.json + skills
```

**Wtyczka jest powłoką, nie systemem** (D-013). Budujemy najpierw dla Claude
Code, ale gateway to zwykły serwer MCP po HTTP — Codex, Cursor, Zed czy
Antigravity połączą się z nim bez zmian po naszej stronie. Dlatego treść
instrukcji ma jedno źródło w `plugin/shared/`, jest wystawiona także jako
**zasoby MCP**, a hooki to jeden skrypt przyjmujący nazwę zdarzenia.

**Rozdzielenie jest twarde.** `backend/` nie zawiera ani jednego szablonu
renderującego interfejs, a `frontend/` nie wie nic o Doctrine ani o MemPalace.
Jedyny kontrakt między nimi to OpenAPI wystawiane przez API Platform.
Konsekwencja praktyczna: backend można wdrożyć i testować bez frontendu,
a frontend podmienić bez dotykania backendu.

## 8. Słownik

- **Przestrzeń** (`space`) — jednostka podziału wiedzy: projekt, klient,
  dział. Mapuje się na `wing` w pałacu. Użytkownik ma w niej rolę
  `reader` / `writer` / `admin`.
- **Prywatna przestrzeń** — `priv_<użytkownik>`, widoczna tylko dla
  właściciela i jego agentów.
- **Szuflada** (`drawer`) — atomowa jednostka pamięci w MemPalace.
- **Skrzydło** (`wing`) / **pokój** (`room`) — podział pałaca w MemPalace.
- **Token agenta** — poświadczenie maszyny, dziedziczy uprawnienia właściciela.
- **Weryfikacja** — potwierdzenie przez człowieka, że treść napisana przez AI
  jest prawdziwa. Znacznik zaufania, nie warunek publikacji.
- **Replika** — kopia pałaca na konkretnej maszynie, z własnym stabilnym
  identyfikatorem z `replica.json`. Nazywa maszynę, nie użytkownika ani modelu.
- **Lustro** (`mirror`) — mapowanie skrzydła lokalnego pałaca na przestrzeń we
  wspólnej bazie, publikujące przyrostowo i cyklicznie.
- **Partia publikacji** (`publish_batch`) — jednostka wycofania: wszystko, co
  poszło do wspólnej bazy jednym przebiegiem, da się cofnąć jednym działaniem.
