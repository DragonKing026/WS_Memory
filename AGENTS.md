---
noteId: "14240ca0aeb011f1997d030a3cd38ca7"
tags: []

---

# AGENTS.md — WS_Memory

Ten plik jest kontraktem dla każdego agenta AI i każdej osoby, która pracuje
nad **WS_Memory**. Czytasz go przed pierwszą zmianą w repozytorium.

Ostatnia aktualizacja: 2026-09-12 15:43 CEST

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

## 3. Architektura w jednym akapicie

**Backend i frontend są rozdzielone** — wzorzec przeniesiony z Precision
Telemed 2.0. Backend to czyste API Symfony, które działa niezależnie od
frontendu i ma własny kontrakt; frontend to aplikacja Vue 3 na Vite,
budowana osobno. Żadna część nie renderuje HTML-a drugiej.

Siedem usług w Dockerze. `nginx` terminuje TLS i jest jedynym wejściem; kieruje
`/` na frontend, a `/api` i `/mcp` na backend — **ten sam origin, więc
przeglądarka w ogóle nie dotyka CORS-a**. `backend` (Symfony 8 / PHP 8.4)
wystawia dwie powierzchnie nad tą samą logiką domenową: REST `/api` dla
frontendu i **gateway MCP** `/mcp` dla agentów. `frontend` (Vue 3 + Vite) to
w produkcji statyczne pliki, w dev kontener z HMR. `worker` (Symfony Messenger)
publikuje dokumenty do pałaca i mieli transkrypty. `mempalace`
(`mempalace serve`) jest jedynym komponentem, który umie szukać semantycznie
i minować. `embeddings` wystawia `/v1/embeddings` z modelem wielojęzycznym.
`postgres` (18 + pgvector) trzyma **wszystko** w dwóch schematach: `palace`
(tabele MemPalace) i `ws` (dane aplikacji). Jeden `pg_dump` to pełny backup
systemu.

Pełny opis: `docs/01-architektura.md`.

## 4. Trzy klasy wiedzy

Rozróżnienie kluczowe — pomylenie ich prowadzi do złych decyzji projektowych.

1. **Pamięć surowa** (główny wolumen, automatyczna): transkrypty sesji,
   dziennik, graf wiedzy, mining repozytoriów. Agent zapisuje swobodnie, bez
   recenzji. Nie wersjonujemy tego — to surowiec.
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
| Framework | Symfony 8.0, PHP 8.4 | jak w główna aplikacja Symfony zespołu |
| API | API Platform 4.3 | `/api`, kontrakt OpenAPI |
| Uwierzytelnianie | JWT (dostępowy + odświeżający) | API bezstanowe; agenci osobnym tokenem |
| Baza | **PostgreSQL 18 + pgvector** | odejście od firmowego MariaDB — D-002 |
| ORM | Doctrine ORM 3.x + Migrations | |
| Kolejki | Symfony Messenger | transport: Doctrine |
| Pamięć | MemPalace 3.7.0, backend pgvector | zależność, czarna skrzynka |
| Embeddingi | `BAAI/bge-m3` przez `/v1/embeddings` | 1024 wymiary, bez prefiksów — D-003 |
| Testy | PHPUnit | |

### Frontend — osobna aplikacja, wzorzec z nowszy projekt z frontendem Vue

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
- **Commitujemy po każdym ukończonym zadaniu z `TODO/`** — nie kumulujemy
  tygodnia pracy w jednym commicie.
- Nie commitujemy: sekretów, tokenów, `.env` z realnymi danymi, dumpów bazy,
  katalogu `vendor/`, plików modeli embeddingów.

### Zanim zaczniesz zadanie

1. Przeczytaj `TODO/` — zadania mają kolejność i zależności.
2. Przeczytaj `docs/06-decyzje.md` — nie podważaj rozstrzygniętych decyzji
   bez nowego argumentu.
3. Jeśli używasz MemPalace jako pamięci własnej sesji: szukaj **przed**
   odpowiedzią o przeszłych ustaleniach. Nie zgaduj.

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
  superpowers/specs/   ← spec projektowy z etapu projektowania
TODO/                  ← ponumerowane zadania
  DONE/                ← zadania ukończone
backend/               ← Symfony 8: API + gateway MCP (samodzielne repo-w-repo)
frontend/              ← Vue 3 + Vite (samodzielna aplikacja)
plugin/                ← plugin WS_Memory do Claude Code
```

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
