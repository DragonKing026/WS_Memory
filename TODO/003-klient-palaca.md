---
noteId: "50cd9ca0aeb211f1997d030a3cd38ca7"
tags: [ws-memory, todo, backend, mempalace, uprawnienia]

---

# TODO-003 — Backend: klient MemPalace i serwisy domenowe pamięci

**Utworzono:** 2026-09-12 16:05 · **Stan:** do zrobienia · **Zależności:** 002

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
