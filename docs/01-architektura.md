---
noteId: "72bca9c0aeb011f1997d030a3cd38ca7"
tags: [ws-memory, dokumentacja, architektura, docker, bezpieczenstwo, mempalace]

---

# Architektura

Stan: **fundament działa** (2026-09-12). Stoją: `postgres`, `embeddings`,
`mempalace`, `backend`, `worker`, `nginx`. Brakuje `frontend` (TODO-006).
Ten dokument opisuje stan docelowy; rozbieżność z kodem = błąd w dokumentacji
albo w kodzie, do naprawy w tym samym zadaniu.

## Rozdział backendu i frontendu

Backend i frontend to **dwie niezależne aplikacje** (D-008, wzorzec z Precision
Telemed 2.0). Backend nie renderuje ani jednej strony; frontend nie wie nic o
bazie. Jedyny kontrakt to OpenAPI wystawiane przez API Platform.

Backend wystawia **dwie powierzchnie nad tą samą logiką domenową**:

| Powierzchnia | Dla kogo | Uwierzytelnianie |
|---|---|---|
| REST `/api` | frontend Vue (ludzie) | JWT: token dostępowy + odświeżający |
| MCP `/mcp` | agenci AI | token agenta (`Authorization: Bearer`) |

To rozdzielenie powierzchni przy wspólnej logice jest celowe: jedna zmiana
reguły uprawnień obowiązuje jednocześnie ludzi i agentów, bo obie powierzchnie
wołają te same serwisy domenowe.

## Usługi

Siedem kontenerów w jednym `docker-compose.yml`:

| Usługa | Obraz | Rola | Port na hosta |
|---|---|---|---|
| `nginx` | `nginx:alpine` | TLS; `/` → frontend, `/api` i `/mcp` → backend | **443, 80** |
| `frontend` | prod: statyczne `dist/` w nginx · dev: Node 22 + pnpm + Vite (HMR) | interfejs Vue 3 | brak |
| `backend` | własny (php-fpm 8.4) | API, wiki, konta, uprawnienia, gateway MCP | brak |
| `worker` | ten sam obraz co `backend` | Messenger: publikacja do pałaca, mielenie | brak |
| `mempalace` | własny, na bazie upstream `Dockerfile` | `mempalace serve` — wyszukiwanie semantyczne i zapis publikowanych szuflad; **nie mieli** | brak |
| `embeddings` | HF TEI albo Infinity | `/v1/embeddings`, model `BAAI/bge-m3` | brak |
| `postgres` | `pgvector/pgvector:pg18` | pałac + dane aplikacji | brak |

Tylko `nginx` jest osiągalny z zewnątrz. Pozostałe usługi istnieją wyłącznie
w sieci Compose — patrz `docs/06-decyzje.md`, D-006.

**`/` i `/api` na jednym origin** — to nie kosmetyka. Przeglądarka nigdy nie
wykonuje żądania międzydomenowego, więc nie ma preflightów, nie ma
konfiguracji CORS do utrzymania i nie ma klasy błędów „działa w dev, nie
działa na produkcji". W dev nginx proxuje `/` na Vite razem z websocketem HMR,
tak jak w 2.0.

## Mapa połączeń

```
  przeglądarka                      agent AI (Claude Code)
       │ HTTPS                            │ HTTPS + token agenta
       └──────────────┬───────────────────┘
                      ▼
                  [ nginx ]  ← TLS, jedyne wejście, jeden origin
                   /      \
              /  │           \ /api  ·  /mcp
             ▼                 ▼
      [ frontend ]          [ backend ]  Symfony 8 — API + gateway MCP
      Vue 3 + Vite              │
      (dist albo HMR)           │
                    ┌───────────┼───────────┬─────────────┐
                    ▼           ▼           ▼             │
             [ postgres ]  [ mempalace ]  [ worker ]       │
              schematy:         │            │            │
              ws · palace       ▼            └────────────┘
                          [ embeddings ]   (Messenger: publikacja,
                                ▲            mielenie transkryptów)
                                └── mempalace liczy wektory tutaj
```

`mempalace` i `worker` sięgają do `postgres` bezpośrednio: pierwszy jako
backend pgvector pałaca, drugi jako Doctrine. To ta sama baza, inne schematy.
`frontend` nie ma połączenia z niczym poza nginxem — nie zna adresu backendu
inaczej niż jako ścieżkę `/api` na własnym origin.

## Kto za co odpowiada

**`backend` (Symfony 8)** — jedyny komponent z logiką biznesową. Trzyma
tożsamość, uprawnienia i wiki. Wystawia REST `/api` i gateway MCP `/mcp` nad
wspólnymi serwisami domenowymi. Zero szablonów interfejsu.

**`frontend` (Vue 3 + Vite)** — cały interfejs dla ludzi: wyszukiwanie,
przeglądanie przestrzeni, edytor Markdown z podglądem, porównywanie rewizji,
administracja. Rozmawia wyłącznie z `/api`. Konwencje: `docs/07-frontend.md`.

**`mempalace`** — jedyny komponent, który umie szukać semantycznie i zapisywać
szuflady w formacie pałaca. **Nie mieli** — mielenie dzieje się wyłącznie na
maszynach użytkowników (D-012).
Nie zna pojęcia użytkownika ani uprawnień; przyjmuje zapytania z już
nałożonym filtrem `wing`. Traktowany jako czarna skrzynka za granicą HTTP MCP,
żeby jego aktualizacja nie dotykała naszego kodu.

**`worker`** — wszystko, co nie może blokować odpowiedzi HTTP: wypchnięcie
opublikowanego dokumentu do pałaca, przetworzenie partii publikacji z lokalnych
pałaców, zadania kontrolne.

**`embeddings`** — liczy wektory. Wydzielony, żeby cały system miał jeden
model (D-003) i żeby laptopy nie musiały go pobierać.

**`postgres`** — jedyny komponent stanowy. `pg_dump` całej bazy to pełny
backup systemu: wiki, rewizje, konta, uprawnienia, audyt **i pałac**.

## Przepływy

### A. Człowiek czyta bazę wiedzy

1. Przeglądarka ładuje frontend z `/`, potem woła `/api/...` z tokenem JWT.
2. `backend` ustala listę przestrzeni, do których użytkownik ma prawo.
3. Wyszukiwanie: `backend` → `mempalace` (`POST /mcp`, `mempalace_search`)
   z **twardym filtrem `wing`** ograniczonym do tych przestrzeni.
4. Listowanie i metadane: `backend` czyta SQL-em ze schematów `ws` i `palace`
   — bez pośrednictwa MemPalace, bo nie trzeba liczyć wektorów.

### B. Człowiek pisze dokument

1. Frontend wysyła treść na `/api/documents/{id}/revisions`; `backend`
   zapisuje nową rewizję w `ws.document_revisions` w transakcji.
2. Po publikacji `backend` wysyła zadanie do `worker`.
3. `worker` wypycha treść do pałaca (`mempalace_add_drawer`) w przestrzeni
   dokumentu i zapisuje powiązanie w `ws.memory_entries`.
4. Od tej chwili agenci znajdują dokument semantycznie.

### C. Agent czyta i zapisuje

1. Claude Code → `nginx` → `backend` `/mcp`, nagłówek `Authorization: Bearer`.
2. `backend` rozwiązuje token na właściciela i jego uprawnienia — jednym
   zapytaniem, razem ze sprawdzeniem unieważnienia, wygaśnięcia i aktywności
   konta. Token **nigdy** nie daje więcej niż właściciel. Przy okazji tego samego
   zapisu nalicza się limit tempa (D-022).
3. `tools/call` trafia do jednego z narzędzi `ws_*` (`docs/03-mcp-gateway.md`),
   które woła **te same serwisy domenowe co REST** — reguły uprawnień są
   jedne, nie dwie.
4. Zapis: `backend` woła `mempalace` i rejestruje wpis w `ws.memory_entries`
   z autorem z tokena. Każde wywołanie ląduje w `ws.audit_log`.

### D. Automatyczne mielenie transkryptów sesji — **lokalnie**

1. Hooki MemPalace na maszynie użytkownika mielą transkrypt **do lokalnego
   pałaca**, po każdej sesji. Rozmowa nie opuszcza laptopa.
2. Wiedza warta zespołu trafia do wspólnej bazy dopiero przez publikację
   (przepływ E) — ręcznie albo lustrem.

Serwer nie przyjmuje transkryptów i nie mieli niczego (D-012). Nie ma tu
endpointu do zabezpieczania, bo nie ma takiej ścieżki.

### E. Wysyłka z lokalnego pałaca na serwer — **domyślna** (D-010 + D-014)

Jedyna droga, którą wiedza wchodzi do wspólnej bazy poza pisaniem w wiki:

1. Użytkownik ma lokalny MemPalace — wtyczka WS_Memory wymaga wtyczki
   MemPalace jako zależności, więc ma go **każdy**. Własne `mempalace init`
   i `mempalace mine` na swoich projektach. **Kod nie opuszcza laptopa.**
2. Agent ma **dwa serwery MCP**: `mempalace` (lokalny, prywatny) i `ws_memory`
   (wspólny). Skill narzuca kolejność szukania: najpierw wspólna baza, potem
   lokalna.
3. Publikacja do wspólnej bazy idzie **przez API**, nie przez bazę danych:
   plugin czyta lokalne szuflady (`mempalace_list_drawers` +
   `mempalace_get_drawer`) i wysyła ich treść na `POST /api/publish`.
4. `backend` sprawdza uprawnienia, przepuszcza treść przez filtr sekretów,
   przelicza embeddingi **swoim** modelem i zapisuje z autorem oraz parą
   `(source_replica, source_drawer_id)` — powtórna publikacja aktualizuje,
   nie duplikuje.
5. **Dzieje się to bez proszenia.** Domyślnie (`auto_publish = true`) każda
   nowa szuflada lokalnego pałaca jedzie na serwer po zakończeniu sesji albo
   po lokalnym mieleniu. Reguła lądowania:
   - skrzydło **zmapowane** (`ws.mirrors`) → przestrzeń zespołowa, widoczna
     dla innych; mapowanie wymaga jednorazowego potwierdzenia człowieka;
   - skrzydło **niezmapowane** → **prywatna przestrzeń użytkownika** na
     serwerze.
6. Kto woli decydować sam, wyłącza `auto_publish` w ustawieniach wtyczki
   i wraca do ręcznego `/ws-publish`.

**Kierunek jest jednokierunkowy i lokalny pałac jest pierwotny** (D-015):
zapis lokalny nigdy nie czeka na serwer. Gdy serwer jest niedostępny, szuflady
czekają w lokalnej kolejce wyjściowej i dopinają się przy następnej okazji —
praca bez sieci działa w pełni, a awaria serwera nie blokuje nikogo. Ponowna
wysyłka jest bezpieczna dzięki odsiewowi po parze źródłowej i `content_hash`.

Wszystko jest więc na serwerze zawsze — kopia zapasowa, wyszukiwanie, dostęp
z drugiej maszyny — ale nic nie staje się widoczne dla zespołu bez mapowania.
Konsekwencja nazwana wprost w D-014: **tekst zmielonego kodu trafia na serwer**
(jako szuflady). Mielenie pozostaje lokalne, więc serwer nadal nie potrzebuje
dostępu do repozytoriów.

Dlaczego przez API, a nie wprost do bazy: zapis do Postgresa ominąłby token,
role i audyt — czyli całą warstwę, dla której WS_Memory istnieje. Ten wariant
został świadomie odrzucony w D-010.

## Granica bezpieczeństwa

Cztery warstwy, od zewnątrz:

1. **`nginx`** — TLS, limity tempa, rozmiar żądania (upload transkryptów).
2. **Uwierzytelnienie** — JWT (ludzie) albo token agenta (maszyny).
3. **Autoryzacja** — rola w przestrzeni; filtr `wing` wstrzykiwany przez
   `backend`, nigdy nie pochodzący z parametrów wywołania. Wspólny dla obu
   powierzchni, więc nie da się go obejść przez wybór drogi wejścia.
4. **Izolacja magazynu** — przestrzenie wrażliwe mogą mieć własny
   **namespace pgvector**, czyli osobne tabele, nie filtr w zapytaniu.

Czego agent nie ma i nigdy nie dostanie: tokena MemPalace, DSN-u do Postgresa,
dostępu do `/v1/embeddings`, możliwości wskazania cudzej przestrzeni.

## Skalowanie i granice

Projekt celuje w zespół rzędu **kilkunastu osób** i bazę rzędu **setek tysięcy
szuflad** (lokalny pałac autora ma 97 tys. przy jednym użytkowniku). Przy tej
skali wąskim gardłem jest liczenie wektorów, nie Postgres — dlatego
`embeddings` jest osobną usługą, którą można przenieść na maszynę z GPU bez
zmiany czegokolwiek innego.

Mielenie jest z natury asynchroniczne i idempotentne (MemPalace koordynuje
mining po znacznikach czasu), więc `worker` można zwielokrotnić.
