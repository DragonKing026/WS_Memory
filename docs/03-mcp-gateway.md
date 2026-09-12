---
noteId: "986e0eb0aeb111f1997d030a3cd38ca7"
tags: [ws-memory, dokumentacja, mcp, uprawnienia, agenci-ai, bezpieczenstwo]

---

# Gateway MCP

Stan: **projekt** (2026-09-12). Sam gateway powstaje w `TODO-004`, ale
**warstwa pod nim już działa**: `MemoryService` egzekwuje uprawnienia dla obu
powierzchni (`TODO-003`). Narzędzia MCP będą więc cienkie — tłumaczą żądanie na
wywołanie serwisu i nic więcej.

Backend wystawia pod `/mcp` serwer MCP po HTTP (JSON-RPC 2.0) z **kurowanym
zestawem narzędzi firmowych** — nie przepuszcza 36 narzędzi MemPalace na wylot
(D-007). Granica narzędzi **jest** granicą uprawnień.

## Protokół

`POST /mcp`, JSON-RPC 2.0, nagłówek `Authorization: Bearer <token agenta>`.
Obsługiwane metody: `initialize`, `tools/list`, `tools/call`.

Klient (Claude Code) konfiguruje się jednym poleceniem:

```bash
claude mcp add --transport http ws_memory https://wsmemory.twoja-domena.pl/mcp \
  --header "Authorization: Bearer $WS_MEMORY_TOKEN"
```

## Narzędzia

### Czytanie

| Narzędzie | Parametry | Zwraca |
|---|---|---|
| `ws_status` | — | kim jest token, jakie przestrzenie, liczby szuflad i dokumentów |
| `ws_search` | `query`, `spaces?`, `kind?`, `limit?`, `since?` | dopasowania semantyczne + leksykalne z przestrzeni, do których token ma prawo |
| `ws_get` | `id` | pełna treść szuflady albo dokumentu |
| `ws_doc_list` | `space?`, `query?`, `status?` | lista dokumentów z metadanymi (autor, weryfikacja, rewizja) |
| `ws_doc_read` | `space`, `slug`, `revision?` | treść dokumentu; bez `revision` — aktualna |
| `ws_kg_query` | `subject?`, `predicate?`, `space?` | fakty z grafu wiedzy |

### Pisanie

| Narzędzie | Parametry | Efekt |
|---|---|---|
| `ws_remember` | `text`, `space?`, `kind?`, `tags?` | szuflada w pałacu + wiersz w `ws.memory_entries` |
| `ws_doc_write` | `space`, `slug`, `title`, `content`, `change_note` | nowa rewizja; tworzy dokument, jeśli nie istnieje |
| `ws_kg_add` | `subject`, `predicate`, `object`, `space?` | fakt w grafie wiedzy |
| `ws_diary_write` | `text`, `space?` | wpis w dzienniku sesji |
| `ws_propose` | `space`, `title`, `content` | wpis do kolejki — tylko gdy `spaces.requires_proposal` |

Czego **nie ma i nie będzie**:

- **`ws_doc_verify`** — weryfikacja to czynność człowieka w interfejsie.
  Agent nie potwierdza własnych wpisów (D-005).
- **`ws_doc_delete`** — dokumenty się archiwizuje, nie usuwa. Historia rewizji
  nigdy się nie skraca.
- **narzędzia administracyjnego** — zakładanie przestrzeni, nadawanie roli,
  wystawianie tokena to wyłącznie interfejs człowieka.

## Jak egzekwowane są uprawnienia

Cztery reguły, każda pokryta testem negatywnym:

1. **Tożsamość z tokena, nigdy z parametru.** Żadne narzędzie nie ma
   parametru „autor". Podszycie się jest niewyrażalne w API, a nie tylko
   zabronione.
2. **Filtr przestrzeni wstrzykiwany po stronie serwera.** Parametr `spaces`
   może zakres tylko **zawężać**. Backend liczy przecięcie:
   `żądane ∩ uprawnienia_właściciela ∩ space_scope_tokena`. Puste przecięcie
   to pusty wynik, nie błąd — agent nie dowiaduje się nawet, że przestrzeń
   istnieje.
3. **Zapis bez przestrzeni → prywatna przestrzeń właściciela tokena.**
   Bezpieczny domyślny: pomyłka agenta nie zaśmieca wspólnej bazy.
4. **Token nigdy nie ma więcej niż właściciel.** Odebranie roli człowiekowi
   natychmiast odbiera ją wszystkim jego agentom — bez osobnej operacji.

Wspólne serwisy domenowe dla `/api` i `/mcp` (D-008) są tu istotne: reguła
uprawnień istnieje w jednym miejscu, więc nie da się jej obejść, wybierając
drogę wejścia. Tym miejscem jest `MemoryService` — narzędzie MCP, które wołałoby
pałac wprost, omijałoby oba filtry (D-019) i księgowanie zapisu (D-020).

Do tego dochodzi **druga warstwa filtrowania**: treść, której `memory_entries`
nie umieszcza w dozwolonej przestrzeni, nie wychodzi, choćby wróciła ze skrzydła,
o które sami zapytaliśmy (D-019).

## Mapowanie na MemPalace

| Narzędzie WS | Wywołanie MemPalace | Co dokłada warstwa pamięci |
|---|---|---|
| `ws_search` | `mempalace_search` × liczba dozwolonych przestrzeni | jedno skrzydło na wywołanie, przerankowanie wyników, `kind` → `room`, filtr wyników po `memory_entries` |
| `ws_get` | `mempalace_get_drawer` | sprawdzenie przynależności **przed** pobraniem; zgodność skrzydła z przestrzenią po pobraniu |
| `ws_remember` | `mempalace_add_drawer` | `wing` przestrzeni, autor z tokena, wiersz w `memory_entries` w jednej transakcji |
| `ws_kg_query` / `ws_kg_add` | `mempalace_kg_query` / `mempalace_kg_add` | nazwa encji kwalifikowana skrzydłem (D-021), autor |
| `ws_diary_write` | `mempalace_diary_write` | **jawne skrzydło przestrzeni** — bez niego pałac wkłada wpis do `wing_{agent_name}`, poza mapowaniem przestrzeni |
| `ws_doc_*` | — | wyłącznie SQL na `ws`; publikacja do pałaca idzie przez `worker` |

> **`mempalace_search` przyjmuje jedno skrzydło, nie listę.** `wing IN (...)` nie
> jest więc wyrażalne jednym wywołaniem — odczyt rozsyła po jednym zapytaniu na
> dozwoloną przestrzeń i przerankowuje wyniki. Kosztuje to N zapytań przy N
> przestrzeniach, ale utrzymuje regułę nr 3 bez wyjątku, a puste przecięcie
> uprawnień nie odpytuje pałaca wcale.
>
> **Graf wiedzy nie ma osi skrzydła w ogóle.** Zakres wchodzi więc do klucza:
> fakty zapisujemy i czytamy pod nazwą kwalifikowaną (`wing_alfa::Encja`), więc
> zapytanie o cudzą przestrzeń ich nie dopasowuje, zamiast dopasować i odfiltrować
> (D-021). Konsekwencja: relacje nie przechodzą między przestrzeniami — zamierzona.
>
> **`agent_name` w dzienniku jest segmentem ścieżki.** Etykieta autora nie może
> zawierać `:` ani `/`, więc ma postać `ws_<użytkownik>__<token>`. Jedna etykieta
> dla wszystkich narzędzi, nie etykieta na narzędzie.

Token MemPalace zna **tylko** backend. Agent nigdy go nie widzi.

## Błędy

Standardowe kody JSON-RPC. Dodatkowo:

| Sytuacja | Odpowiedź |
|---|---|
| brak / zły token | `401` HTTP, bez treści JSON-RPC |
| token unieważniony albo wygasły | `401` + nagłówek `WWW-Authenticate` |
| przestrzeń poza uprawnieniami | **pusty wynik**, nie błąd (nie ujawniamy istnienia) |
| zapis do przestrzeni bez roli `writer` | `-32003`, komunikat wskazujący brak uprawnienia do zapisu |
| przestrzeń wymaga kolejki, użyto `ws_doc_write` | `-32004` z podpowiedzią, żeby użyć `ws_propose` |
| MemPalace niedostępny | `-32010`, komunikat „pamięć chwilowo niedostępna" |

Rozróżnienie między „pusty wynik" i „brak uprawnień" jest celowe: komunikat
„nie masz dostępu do przestrzeni *Kadry*" sam jest wyciekiem informacji.
