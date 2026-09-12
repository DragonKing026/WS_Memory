---
noteId: "50cd9ca0aeb211f1997d030a3cd38ca7"
tags: [ws-memory, todo, backend, mempalace, uprawnienia]

---

# TODO-003 — Backend: klient MemPalace i serwisy domenowe pamięci

**Utworzono:** 2026-09-12 16:05 · **Stan:** ✅ **UKOŃCZONE 2026-09-12 22:40** · **Zależności:** 002

## Powód

Backend musi rozmawiać z pałacem, ale **nigdy bez filtra przestrzeni**
(reguła nr 3). Potrzebna jedna warstwa, przez którą przechodzi każde takie
wywołanie — inaczej filtr będzie trzeba pamiętać w każdym miejscu wywołania,
a wystarczy zapomnieć raz, żeby powstał wyciek.

## Analiza

MemPalace mówi po JSON-RPC pod `POST /mcp` z tokenem w nagłówku. Z naszej
perspektywy to zwykły klient HTTP, ale z dwiema pułapkami:

- **`mempalace_search` przyjmuje `wing` jako parametr.** Gdyby ten parametr
  mógł pochodzić z żądania użytkownika, cały model uprawnień byłby fikcją.
  Dlatego klient **nie przyjmuje `wing` od wywołującego** — dostaje
  użytkownika/token i sam liczy dozwolony zbiór przez `SpaceAccessResolver`.
- **Mapowanie klas wiedzy na pokoje** musi być w jednym miejscu
  (`documentation`, `diary`, `technical`, `decisions`), bo inaczej rozjedzie
  się między zapisem a wyszukiwaniem i szuflady staną się nieosiągalne.

Awarie MemPalace muszą być odróżnialne od pustych wyników. „Nic nie znalazłem"
i „pamięć nie odpowiada" to dla agenta dwie zupełnie różne informacje.

## Rozwiązanie

1. `MemPalaceClient` — cienki klient JSON-RPC: `call(tool, args)`, timeouty,
   ponowienia dla odczytów, jasne wyjątki (`MemPalaceUnavailable`).
2. `MemoryService` — jedyne wejście dla logiki domenowej:
   - `search(Actor, query, filters)` — sam dokłada `wing IN (...)`;
   - `remember(Actor, text, space?, kind, tags)` — brak przestrzeni →
     prywatna przestrzeń aktora;
   - `get(Actor, drawerId)` — sprawdza przynależność przed zwróceniem treści;
   - `kgQuery` / `kgAdd`, `diaryWrite`.
3. `Actor` jako wspólna abstrakcja człowieka i tokena agenta — REST i MCP
   podają to samo, więc reguły są jedne.
4. Rejestrowanie każdego zapisu w `ws.memory_entries` w tej samej transakcji
   co operacja domenowa; przy błędzie pałaca transakcja się cofa.
5. Filtr wyników po `memory_entries` jako druga warstwa — jeśli pałac zwróci
   coś z niedozwolonej przestrzeni, i tak nie wyjdzie na zewnątrz.
6. Testy z podstawionym klientem (bez pałaca) + jeden test integracyjny na
   żywym kontenerze.

## Kryteria ukończenia

- W `MemoryService` nie istnieje ścieżka wywołania `mempalace_search` bez
  filtra `wing` — potwierdzone testem i przeglądem kodu.
- Zapis bez wskazanej przestrzeni trafia do prywatnej przestrzeni aktora.
- Szuflada z niedozwolonej przestrzeni nie wychodzi przez `get()` ani przez
  `search()` — dwa testy negatywne.
- Niedostępny MemPalace daje wyraźny błąd, a nie pusty wynik.
- Każdy zapis ma odpowiadający wiersz w `memory_entries`; nieudany zapis nie
  zostawia sieroty.

## Co zostało zrobione

**Ukończono:** 2026-09-12 22:40

### Kryteria ukończenia — weryfikacja

| Kryterium | Wynik |
|---|---|
| w `MemoryService` nie istnieje ścieżka wywołania `mempalace_search` bez filtra `wing` | ✅ **niewyrażalne w typach**, nie tylko nieużywane: `MemoryStore::search()` przyjmuje `PalaceWing` jako pierwszy, nieopcjonalny argument, a ten typ odrzuca wartość pustą |
| zapis bez wskazanej przestrzeni trafia do prywatnej przestrzeni aktora | ✅ dwa testy: dla człowieka i dla agenta (prywatna przestrzeń **właściciela** tokena) |
| szuflada z niedozwolonej przestrzeni nie wychodzi przez `get()` ani `search()` | ✅ cztery testy negatywne, w tym identyczność odpowiedzi dla nieznanej i zabronionej szuflady oraz szuflada wstawiona wprost do naszego skrzydła na żywym pałacu |
| niedostępny MemPalace daje wyraźny błąd, a nie pusty wynik | ✅ `MemoryUnavailable` przechodzi na wierzch; osobny test na błąd **wewnątrz payloadu**, bo tak MemPalace zgłasza awarie |
| każdy zapis ma wiersz w `memory_entries`, nieudany nie zostawia sieroty | ✅ transakcja rejestru; test wymusza błąd księgowania po udanym zapisie do pałaca |
| testy z podstawionym klientem + jeden na żywym kontenerze | ✅ 27 jednostkowych + 22 na przewodzie + 12 bazodanowych + **8 na żywym pałacu** |

**Razem: 115 testów, 238 asercji** (było 82). PHPStan poziom 8 bez błędów.

### Co powstało

**Domena** (`src/Domain/Memory/`) — `PalaceWing`, `DrawerId`, `MemoryKind`,
`MemoryQuery`, `MemoryFragment`, `KnowledgeFact`, porty `MemoryStore`
i `MemoryRegistry`, `MemoryWrite`, `MemoryUnavailable`, `MemoryAccessDenied`.
Plus port `Space\SpaceCatalog` i `SpaceId::privateFor()`.

**Aplikacja** — `Memory\MemoryService`: jedyne wejście do pamięci.

**Infrastruktura** — `MemPalace\MemPalaceClient` (JSON-RPC), `CallOutcome`,
`McpMemoryStore` (adapter), `MemPalaceUnavailable`,
`Doctrine\DoctrineMemoryRegistry`, `Doctrine\DoctrineSpaceCatalog`.

**Migracja** `Version20260912000003` — `ws.memory_entries`.

**Konfiguracja** — `mempalace.token`, `mempalace.timeout`, jawne powiązania
portów z adapterami w `services.yaml`.

### Trzy rzeczy, które zmieniły projekt zadania

1. **`mempalace_search` przyjmuje jedno skrzydło, nie listę.** Zadanie mówiło
   „sam dokłada `wing IN (...)`" — protokół tego nie umie. Odczyt rozsyła więc
   po jednym zapytaniu na dozwoloną przestrzeń i **przerankowuje** wyniki.
   Skutek uboczny jest korzystny: jedno skrzydło na wywołanie da się wymusić
   typem, a lista nie.
2. **Graf wiedzy nie ma osi skrzydła w ogóle.** `mempalace_kg_query` przyjmuje
   wyłącznie encję. Filtrowanie po pobraniu łamałoby regułę nr 3, więc zakres
   wszedł do klucza: fakty żyją pod nazwą kwalifikowaną skrzydłem (D-021).
3. **Sierota jest nieunikniona, można tylko wybrać, która.** Pałac mówi po
   HTTP i nie da się go wycofać. Wybraliśmy: szuflada bez wiersza wolna, wiersz
   bez szuflady nigdy (D-020) — bo pierwsze jest niewidoczne, a drugie byłoby
   wynikiem, którego nie da się otworzyć.

### Decyzje podjęte po drodze

**D-019** — dwie warstwy filtrowania: skrzydło zawęża pytanie, rejestr sprawdza
odpowiedź. **D-020** — kierunek awarii przy dwóch magazynach bez transakcji
rozproszonej; zapisów nie ponawiamy. **D-021** — graf wiedzy zakresowany
kwalifikowaną nazwą encji.

### Co wykrył test na żywym pałacu

Podwójki dowodzą logiki i nie mówią nic o protokole. Żywy pałac wykrył dwie
usterki, których nie dało się wykryć inaczej:

1. **MemPalace odrzuca `ws:user/token` w `agent_name`** — używa tej etykiety
   jako segmentu ścieżki. Etykieta autora ma teraz postać
   `ws_<użytkownik>__<token>`, jedna dla wszystkich narzędzi.
2. **`mempalace_diary_write` odpowiada polem `entry_id`, nie `drawer_id`**,
   a identyfikator ma przedrostek `diary_`. Bez tego wpis w dzienniku zostałby
   zapisany i **nigdy zaksięgowany** — a nieksięgowana szuflada jest
   nieczytelna na zawsze (D-019).

Oba przypadki mają teraz test jednostkowy, więc nie wrócą.

### Potknięcie warte zapamiętania

**PHPStan poziom 8 znalazł cztery rzeczy w nowym kodzie**, w tym asercję, która
nigdy nie mogła zawieść, i martwą instrukcję po wywołaniu, które zawsze rzuca.
Osobno warte uwagi: identyfikator tokena agenta wymyślony w teście
integracyjnym (`token-integracja`) przeszedł wszystkie testy jednostkowe
i wywrócił się na kolumnie `UUID`. Podwójka nie zna typów bazy.

### Czego nie zrobiono

- **`tags` nie trafiają do pałaca** — MemPalace 3.7 nie ma pola na znaczniki
  w `add_drawer`. Trzymamy je w `ws.memory_entries`: przeszukiwalne w SQL, bez
  wpływu na wyszukiwanie semantyczne. Dopisanie ich do treści szuflady
  odrzucone — pałac trzyma treść **dosłownie**, a znacznik w treści zmienia
  wektor i psuje to, po co embeddingi istnieją.
- **Brak endpointów REST i narzędzi MCP nad pamięcią** — to `TODO-004`.
  Serwis jest gotowy do obu, ale wystawienie wymaga tokenów agentów, których
  tabela powstaje właśnie tam.
- **Brak rekompensaty dla sierot w pałacu** — raportuje je zadanie cykliczne,
  nikt nie usuwa automatycznie. Uzasadnienie w D-020: sierota jest niewidoczna,
  a usuwanie też może zawieść i wtedy potrzeba kolejki rekompensat.
- **Brak przeglądu `kind = transcript`** — powstaje razem z mostkiem
  (`TODO-012`), bo dopiero tam wiadomo, jak wygląda treść z lokalnego pałaca.
