---
noteId: "50cd7590aeb211f1997d030a3cd38ca7"
tags: [ws-memory, todo, backend, mcp, tokeny, uprawnienia, agenci-ai]

---

# TODO-004 — Backend: gateway MCP i tokeny agentów

**Utworzono:** 2026-09-12 16:03 · **Stan:** ✅ **UKOŃCZONE 2026-09-12 22:12** · **Zależności:** 003

## Powód

To powierzchnia, przez którą agenci AI korzystają z bazy wiedzy — i
jednocześnie granica uprawnień (D-007). MemPalace wystawia 36 narzędzi
przyjmujących dowolne `wing`; przepuszczenie ich na wylot oznaczałoby brak
jakichkolwiek uprawnień.

## Analiza

Protokół MCP po HTTP to JSON-RPC 2.0 z trzema metodami, które musimy obsłużyć:
`initialize`, `tools/list`, `tools/call`. Klient (Claude Code) łączy się przez
`claude mcp add --transport http`.

Rozróżnienie, które trzeba zaimplementować świadomie: **brak uprawnień zwraca
pusty wynik, nie błąd** (reguła nr 7). Komunikat „nie masz dostępu do
przestrzeni Kadry" sam ujawnia, że taka przestrzeń istnieje.

Tokeny agentów: własny mechanizm, nie JWT. Powody — muszą być długowieczne,
unieważnialne natychmiast, z zakresem zawężającym uprawnienia właściciela i z
widocznym „ostatnio użyty" (żeby dało się poznać martwe tokeny). Hash w bazie,
wartość pokazywana **raz**, przy wystawieniu.

## Rozwiązanie

1. `POST /mcp` — kontroler JSON-RPC: `initialize` (deklaracja możliwości),
   `tools/list` (schematy narzędzi), `tools/call` (wykonanie).
2. Uwierzytelnianie tokenem agenta: rozwiązanie na właściciela, sprawdzenie
   `revoked_at` i `expires_at`, aktualizacja `last_used_at` i `last_used_ip`.
3. Zakres tokena jako **przecięcie**: `żądane ∩ uprawnienia_właściciela ∩
   space_scope`. Nigdy suma.
4. Narzędzia czytające: `ws_status`, `ws_search`, `ws_get`, `ws_kg_query`
   (`ws_doc_*` dochodzą w zadaniu 005).
5. Narzędzia piszące: `ws_remember`, `ws_kg_add`, `ws_diary_write`.
6. Schematy narzędzi **bez parametru `author`** — tożsamość wyłącznie z tokena.
7. Endpointy zarządzania tokenami w `/api` (wystawienie, lista, unieważnienie)
   — dla frontendu, zadanie 008.
8. Audyt każdego wywołania MCP: narzędzie, przestrzeń, wynik (liczba trafień),
   IP.
9. Ograniczenie tempa per token, żeby pętla w agencie nie zajechała pałaca.

## Kryteria ukończenia

- `claude mcp add --transport http ws_memory <url>/mcp --header "Authorization: Bearer <token>"`
  działa, `tools/list` zwraca zestaw narzędzi.
- Wywołanie `ws_search` ze wskazaniem obcej przestrzeni zwraca **pusty wynik**,
  nie błąd i nie treść.
- Unieważniony token daje `401` przy pierwszym kolejnym wywołaniu.
- Token z zawężonym zakresem nie widzi przestrzeni spoza zakresu, choć
  właściciel je widzi (test).
- Żaden schemat narzędzia nie zawiera pola autora (test przeglądający
  `tools/list`).
- Każde wywołanie ma wpis w `audit_log`.

## Co zostało zrobione

**Ukończono:** 2026-09-12 22:12

### Kryteria ukończenia — weryfikacja

| Kryterium | Wynik |
|---|---|
| `claude mcp add --transport http …` działa, `tools/list` zwraca zestaw narzędzi | ✅ sprawdzone **przez nginx**, nie tylko w testach: `initialize` odpowiada `protocolVersion 2025-06-18`, `tools/list` zwraca siedem narzędzi `ws_*` |
| `ws_search` ze wskazaniem obcej przestrzeni zwraca **pusty wynik**, nie błąd i nie treść | ✅ i pałac **nie jest przy tym pytany wcale** — puste przecięcie uprawnień nie generuje zapytania |
| unieważniony token daje `401` przy pierwszym kolejnym wywołaniu | ✅ test wykonuje wywołanie, unieważnia, wywołuje ponownie |
| token z zawężonym zakresem nie widzi przestrzeni spoza zakresu | ✅ dwa testy: `ws_status` pokazuje mniej, `ws_search` po przestrzeni spoza zakresu jest pusty |
| żaden schemat narzędzia nie zawiera pola autora | ✅ test przechodzi **wszystkie** schematy z `tools/list` po ośmiu nazwach pola autora **oraz po `wing`** |
| każde wywołanie ma wpis w `audit_log` | ✅ udane i **nieudane**; wpis niesie token, nie tylko właściciela |
| limit tempa per token | ✅ `429` + `-32005` po przekroczeniu; osobny test na to, że limit nie dotyka innych tokenów tej samej osoby |

**Razem: 153 testy, 461 asercji** (było 115). PHPStan poziom 8 bez błędów.

### Co powstało

**Domena** — `Identity\AgentIdentity`, port `Identity\AgentTokenDirectory`,
`Memory\StoredMemory`, `MemoryRegistry::countsFor()`.

**Aplikacja** — `AgentToken\{IssueAgentToken,RevokeAgentToken,IssuedAgentToken}`.

**Infrastruktura** — `Doctrine\DoctrineAgentTokenDirectory`,
`Security\AgentTokenAuthenticator`, przepisany `Doctrine\DoctrineAuditTrail`.

**Prezentacja** — `Presentation\Mcp\`: `McpController`, `McpServer`, `McpTool`,
`McpToolRegistry`, `AuditedTool` + fabryka, `ToolArguments`, `McpError` oraz
siedem narzędzi (`ws_status`, `ws_search`, `ws_get`, `ws_kg_query`,
`ws_remember`, `ws_kg_add`, `ws_diary_write`). Plus `Api\AgentTokenController`
i komenda `ws:agent:token`.

**Encja i migracja** — `Entity\AgentToken`, `Version20260912000004`.

### Decyzje podjęte po drodze

**D-022** — limit tempa w bazie, w tym samym wierszu co „ostatnio użyty".
**D-023** — błąd narzędzia jako błąd JSON-RPC, wbrew zaleceniu specyfikacji MCP.
**D-024** — wpis audytu zapisuje się od razu, nie czeka na cudzy `flush`.

### Usterka, którą wykryły testy — i była poważna

**Wpisy audytu nigdy nie zapisywały się przy wywołaniach MCP.**
`DoctrineAuditTrail` robił `persist()` i zostawiał `flush` wołającemu. Działało
to, dopóki każdy wołający akurat flushował — a wywołanie narzędzia nie zmienia
żadnej encji, więc **nic nie flushowało i cała aktywność agentów przechodziła
bez śladu**. Nic nie zgłaszało błędu. Wykrył to test, który poprosił o wpis
i nie znalazł żadnego.

To jest dokładnie ten rodzaj cichej zależności, której nie widać w przeglądzie
kodu: „audyt zapisze się, jeśli ktoś inny później flushnie". Naprawione
natychmiastowym `INSERT`-em przez DBAL, z nazwanym kompromisem (D-024).

Druga, mniejsza: `JsonResponse(null, 202, [], true)` wywracało się na 500 przy
notyfikacji — czyli przy pierwszej rzeczy, jaką robi klient MCP po uzgodnieniu.

### Trzy rzeczy, które wyszły dopiero przy sprawdzaniu przez nginx

1. **`capabilities.tools` wychodziło jako `[]`, nie `{}`.** Pusta tablica PHP
   serializuje się jako tablica, a uzgodnienie MCP wymaga obiektu. To samo
   dotyczyło `properties` narzędzia bez argumentów — `"properties": []` nie jest
   poprawnym JSON Schema. Oba pilnowane teraz testem **na surowej treści
   odpowiedzi**, bo po zdekodowaniu pusty obiekt i pusta tablica to w PHP ta
   sama wartość i test przechodziłby mimo błędu.
2. **`ws_remember` odpowiadało `space: null`** przy zapisie bez wskazanej
   przestrzeni — czyli dokładnie w przypadku, w którym agent nie ma innego
   sposobu dowiedzieć się, gdzie trafiła treść. Zapis zwraca teraz `StoredMemory`
   z przestrzenią docelową.
3. **`AuditedTool` implementuje `McpTool`**, więc kontener otagował go jako
   narzędzie i wstrzyknął do rejestru — narzędzie owijające nic. Wykluczony
   z autorejestracji.

### Czego nie zrobiono

- **`ws_doc_*` i `ws_propose`** — dochodzą z `TODO-005`, bo dopiero tam istnieją
  dokumenty i rewizje. `ws_remember` celowo **nie** przyjmuje `kind: document`:
  założyłoby szufladę w pokoju `documentation` bez wiersza w `documents`, czyli
  stronę wiki niewidoczną na każdym ekranie i niemożliwą do poprawienia.
- **Brak ekranów do tokenów** — endpointy REST są gotowe, ekrany w `TODO-008`.
- **Nie uruchomiłem `claude mcp add` na koncie użytkownika.** Polecenie jest
  wypisywane i sprawdzone przez `curl` przez nginxa, ale dopisanie serwera MCP
  do cudzej konfiguracji to decyzja właściciela maszyny, nie moja.
- **Okno limitu jest stałe, nie przesuwane** — świadomie (D-022). Na przełomie
  okien agent może wykonać dwa razy limit; limit istnieje, żeby pętla nie
  zajechała pałaca, a nie żeby rozliczać kwoty.
