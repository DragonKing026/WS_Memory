---
noteId: "986e0eb0aeb111f1997d030a3cd38ca7"
tags: [ws-memory, dokumentacja, mcp, uprawnienia, agenci-ai, bezpieczenstwo]

---

# Gateway MCP

Stan: **działa** (2026-09-12, `TODO-004` i `TODO-005`). Jedenaście narzędzi,
tokeny agentów, limit tempa i audyt każdego wywołania. Zestaw jest kompletny —
kolejne narzędzia dojdą dopiero z mostkiem do lokalnych pałaców (`TODO-012`). Od
`TODO-009` gateway wystawia dodatkowo **zasoby MCP** z treścią instrukcji dla
agentów — patrz „Zasoby".

Backend wystawia pod `/mcp` serwer MCP po HTTP (JSON-RPC 2.0) z **kurowanym
zestawem narzędzi firmowych** — nie przepuszcza 44 narzędzi MemPalace na wylot
(D-007). Granica narzędzi **jest** granicą uprawnień.

## Protokół

`POST /mcp`, JSON-RPC 2.0, nagłówek `Authorization: Bearer <token agenta>`.
Obsługiwane metody: `initialize`, `tools/list`, `tools/call`, `resources/list`,
`resources/read`, `ping` oraz notyfikacje `notifications/initialized`
i `notifications/cancelled`.

Deklarowana wersja protokołu: **2025-06-18**. Klient proszący o znaną starszą
(`2025-03-26`, `2024-11-05`) dostaje swoją — odmowa zablokowałaby klienty, które
działałyby bez problemu, bo schematy narzędzi się między wersjami nie różnią.

**Żądania wsadowe nie są obsługiwane** (`-32600`). Jedno wywołanie na żądanie
utrzymuje limit tempa i audyt w zgodzie z rzeczywistością: wsad liczyłby się jako
jedno wywołanie, robiąc dwadzieścia.

Notyfikacja (żądanie bez pola `id`) dostaje **HTTP 202 i puste ciało**. Odesłanie
czegokolwiek innego wiesza klienty, które na odpowiedź nie czekają.

Klient (Claude Code) konfiguruje się jednym poleceniem — wypisuje je
`ws:agent:token` razem z tokenem:

```bash
claude mcp add --transport http ws_memory https://wsmemory.twoja-domena.pl/mcp \
  --header "Authorization: Bearer $WS_MEMORY_TOKEN"
```

## Narzędzia

### Czytanie

| Narzędzie | Parametry | Zwraca |
|---|---|---|
| `ws_status` | — | kim jest token, do jakich przestrzeni ma prawo z rolą i liczbą wpisów, **gdzie trafi zapis bez wskazanej przestrzeni** |
| `ws_search` | `query`, `spaces?`, `kind?`, `limit?`, `since?`, `before?` | dopasowania semantyczne z przestrzeni, do których token ma prawo |
| `ws_get` | `id` | pełna treść; `found: false` dla nieistniejącej **i dla zabronionej** |
| `ws_kg_query` | `entity`, `direction?`, `spaces?` | fakty z grafu wiedzy z okresem ważności |
| `ws_doc_list` | `space?`, `query?`, `include_archived?` | lista dokumentów z rewizją, `verified` i `authored_by_ai` |
| `ws_doc_read` | `space`, `slug`, `revision?` | treść dokumentu; bez `revision` — aktualna |

### Pisanie

| Narzędzie | Parametry | Efekt |
|---|---|---|
| `ws_remember` | `text`, `space?`, `tags?` | szuflada w pałacu + wiersz w `ws.memory_entries`; zwraca przestrzeń, w której **faktycznie** wylądowała |
| `ws_kg_add` | `subject`, `predicate`, `object`, `space?`, `valid_from?`, `valid_to?` | fakt w grafie wiedzy |
| `ws_diary_write` | `text`, `space?`, `topic?` | wpis w dzienniku sesji |
| `ws_doc_write` | `space`, `slug`, `title`, `content`, `change_note?` | nowa rewizja; tworzy dokument, jeśli nie istnieje. Zastępuje treść w całości |
| `ws_propose` | `space`, `title`, `content`, `slug?` | wpis do kolejki, gdy `spaces.requires_proposal`; wymaga tylko roli czytającego (D-026) |

> **`ws_remember` nie ma parametru `kind`** i zawsze zapisuje notatkę. Pozwolenie
> agentowi na `document` założyłoby szufladę w pokoju `documentation` bez wiersza
> w tabeli `documents` — czyli stronę wiki, o której wiki nie wie: niewidoczną na
> każdym ekranie i niemożliwą do poprawienia. Dokumenty dochodzą z `ws_doc_write`,
> gdzie powstaje też rewizja.
>
> **Każda odpowiedź o dokumencie niesie `verified` i `authored_by_ai`.** Ta para
> jest całym modelem zaufania w dwóch polach. Bez niej agent zacytuje
> niepotwierdzony szkic innego agenta tak, jakby sprawdził go człowiek.
>
> **`ws_doc_write` zastępuje treść w całości, nie dopisuje.** Dlatego opis
> narzędzia każe najpierw przeczytać aktualną wersję przez `ws_doc_read` —
> pominięcie tego kroku nie daje błędu, tylko rewizję, w której zginęła połowa
> dokumentu.
>
> **Propozycja nie jest w wiki i `ws_propose` mówi to wprost** (`in_wiki: false`).
> Bez tego agent zamelduje publikację, której nie było.
>
> **Zapis zwraca przestrzeń docelową, nie tę z żądania.** Przy braku parametru
> `space` te dwie rzeczy się różnią, a agent, któremu odpowiemy `null`, nie ma
> skąd wiedzieć, gdzie trafiła treść — ani zauważyć, że trafiła nie tam, gdzie
> chciał (reguła nienaruszalna 6).

Czego **nie ma i nie będzie**:

- **`ws_doc_verify`** — weryfikacja to czynność człowieka w interfejsie.
  Agent nie potwierdza własnych wpisów (D-005).
- **`ws_doc_delete`** — dokumenty się archiwizuje, nie usuwa. Historia rewizji
  nigdy się nie skraca.
- **narzędzia administracyjnego** — zakładanie przestrzeni, nadawanie roli,
  wystawianie tokena to wyłącznie interfejs człowieka.
- **parametru `wing`** — w żadnym narzędziu. Skrzydło wybiera serwer; nazwa
  skrzydła w żądaniu unieważniłaby cały model uprawnień.

### Nieznany parametr jest błędem

Każde narzędzie odrzuca parametr, którego nie zna (`-32602`), razem z listą
dozwolonych. **Nie ignoruje go po cichu**, i to jest rozstrzygnięcie, nie
niedopatrzenie: parametrem, który agent wymyśli najczęściej, jest `wing` —
nauczony od lokalnego serwera MemPalace, podłączonego w tej samej sesji.
Zignorowany `wing` znaczyłby, że agent **uwierzy, iż zawęził wyszukiwanie**,
choć go nie zawęził. Usłyszenie „nie ma takiego parametru" kosztuje jedno
ponowienie; przemilczenie kosztuje błędny wniosek o tym, co agent właśnie
przeczytał.

## Zasoby — instrukcje dla agentów

Gateway wystawia jako **zasoby MCP** treść instrukcji dla agentów: protokół
recall, zasady dokumentowania, konfigurację i opisy czterech podagentów. Czyta je
każdy klient MCP, nie tylko Claude Code.

| URI | Co to jest |
|---|---|
| `ws-memory://protokol-recall` | szukaj w bazie, **zanim** odpowiesz o przeszłych ustaleniach |
| `ws-memory://jak-dokumentowac` | co jest notatką, co dokumentem, jak nazwać adres i opisać zmianę |
| `ws-memory://konfiguracja` | wystawienie tokena agenta i sprawdzenie połączenia |
| `ws-memory://agenci/ws-recall` | podagent: całość wcześniejszych ustaleń przed decyzją |
| `ws-memory://agenci/ws-dokumentalista` | podagent: spisanie wyniku zamkniętego zadania |
| `ws-memory://agenci/ws-archiwista` | podagent: duplikaty i sprzeczności, przez propozycje |
| `ws-memory://agenci/ws-onboarding` | podagent: odpowiedzi **wyłącznie** z firmowej bazy |

```json
{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"ws-memory://protokol-recall"}}
```

Odpowiedź ma kształt z protokołu: `resources/list` zwraca listę wpisów
`{uri, name, title, description, mimeType}`, a `resources/read` —
`{"contents":[{"uri","mimeType","text"}]}`. `mimeType` to zawsze `text/markdown`.
Nieznany adres jest **błędem JSON-RPC** `-32002` (kod z samej specyfikacji MCP),
nie pustym dokumentem — D-023.

### Skąd pochodzi treść

Z katalogu `plugin/shared/`, który jest jej **jedynym** źródłem (D-013). Ta sama
treść jest podłączana przez wtyczkę jako skille i podagenci; pakowania jej nie
kopiują. Konsekwencja praktyczna: zmiana instrukcji jest **deployem serwera**,
a nie aktualizacją wtyczki u każdej osoby — i port na Codeksa, Cursora czy Zeda
nie wymaga przepisywania instrukcji.

Mapowanie adresu na plik jest **jawną tablicą w kodzie**
(`Infrastructure\Instruction\FileInstructionLibrary`), nie skanem katalogu: skan
opublikowałby każdemu agentowi cokolwiek, co do tego katalogu wpadnie. Pliki mają
frontmatter YAML z polami `name` i `description` — to metadane opakowania, więc
**nie wchodzą do treści zasobu**, a `description` służy za opis na liście.
Katalog wskazuje `WS_INSTRUCTIONS_DIR` (`docs/05-deployment.md`); brakujący plik
jest błędem, nie pustym zasobem.

### Odczytu zasobu nie zapisujemy w dzienniku audytu

Wywołanie narzędzia zostawia wpis, odczyt zasobu — **nie**, i jest to decyzja,
nie przeoczenie. Zasób to statyczny tekst, identyczny dla każdego tokena, a klient
MCP odpytuje listę zasobów przy **każdym** połączeniu. Wpis mówiłby więc „ktoś się
podłączył", a nie „ktoś coś zrobił".

Ten dokładny mechanizm — zdarzenie zapisywane przy każdym żądaniu zamiast przy
realnej czynności — zapłaciliśmy już raz: dał **20 335** fałszywych wpisów
`user.login`, czyli połowę dziennika w dniu, w którym pierwszy raz otwarto ekran
audytu (`Infrastructure\Security\LoginAuditSubscriber`). Dziennik, którego
większość jest fikcją, jest gorszy od krótkiego, bo prawdziwe wpisy gdzieś w nim
są i nikt ich nie znajdzie.

**Limit tempa obejmuje te metody tak samo jak resztę** i tak zostaje: pętla po
katalogu zasobów obciąża serwer identycznie jak pętla po wyszukiwaniach.

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

## Tokeny agentów

Token to **nie JWT** i nie jest to przeoczenie: żyje miesiącami, musi umrzeć
w chwili, gdy ktoś tak powie, nosi zakres zawężający uprawnienia właściciela
i pokazuje, kiedy był ostatnio użyty. JWT nie robi żadnej z tych rzeczy.

Wystawienie z wiersza poleceń — jedyna droga, dopóki nie ma ekranów
(`TODO-008`):

```bash
docker compose exec backend php bin/console ws:agent:token \
  artur@web-systems.pl "laptop Artura" --space=projekt-alfa
```

Polecenie wypisuje gotowe `claude mcp add`. **Token widać jeden raz** — w bazie
jest tylko skrót `sha256`. Przedrostek `wsm_` nie jest ozdobą: pozwala skanerom
sekretów i ludziom rozpoznać, na co patrzą w pliku konfiguracyjnym.

Dla frontendu: `GET /api/agent-tokens`, `POST /api/agent-tokens`,
`DELETE /api/agent-tokens/{id}`. Wszystko **wyłącznie własne tokeny**, również
dla administratora globalnego — kto mógłby po cichu wycofać cudzego agenta,
mógłby zatrzymać czyjąś pracę bez śladu (D-016). Widoczna droga to dezaktywacja
konta, która jest zapisana.

Lista pokazuje `lastUsedAt`. To pole, bez którego nikt nie odważy się wycofać
żadnego tokena, więc lista rośnie w nieskończoność.

### Limit tempa

**120 wywołań na minutę na token** (`MCP_CALLS_PER_MINUTE`), liczone w bazie
w tym samym zapisie co „ostatnio użyty" (D-022). Przekroczenie daje `429`
i kod `-32005`.

Limit jest **per token, nie per konto**: rozbiegana pętla w jednym agencie nie
zatrzymuje wszystkiego, co dana osoba ma uruchomione. Liczą się wszystkie
metody, `tools/list` włącznie — pętla po katalogu narzędzi obciąża tak samo jak
pętla po wyszukiwaniach.

## Błędy

Awaria narzędzia wraca jako **błąd JSON-RPC**, nie jako udana odpowiedź
z błędem w treści. To świadome odstępstwo od zalecenia specyfikacji MCP
i powód jest empiryczny — MemPalace robi to zgodnie z zaleceniem, a przy
zatrzymanym serwerze embeddingów jego odpowiedź była nie do odróżnienia od „nic
nie znalazłem" (D-023).

| Sytuacja | Odpowiedź |
|---|---|
| brak / zły token | `401` HTTP, bez treści JSON-RPC |
| token unieważniony albo wygasły | `401` + nagłówek `WWW-Authenticate` |
| konto właściciela wyłączone | `401` — bez unieważniania tokenów po kolei |
| przestrzeń poza uprawnieniami (odczyt) | **pusty wynik**, nie błąd (nie ujawniamy istnienia) |
| szuflada poza uprawnieniami (`ws_get`) | `found: false` — identycznie jak nieistniejąca |
| zapis do przestrzeni bez roli `writer` | `-32003`, komunikat wskazujący brak uprawnienia do zapisu |
| przestrzeń wymaga kolejki, użyto `ws_doc_write` | `-32004` z podpowiedzią, żeby użyć `ws_propose` |
| dokument poza uprawnieniami (`ws_doc_read`) | `found: false` — identycznie jak nieistniejący |
| przekroczony limit tempa | `429` + `-32005` |
| MemPalace niedostępny | `-32010`, „pamięć chwilowo niedostępna — nie znaczy, że nic nie znaleziono" |
| nieznany parametr, zły typ, brak wymaganego | `-32602` z listą dozwolonych parametrów |
| nieznane narzędzie albo metoda | `-32601` z podpowiedzią `tools/list` |
| nieznany adres zasobu (`resources/read`) | `-32002` z podpowiedzią `resources/list` |
| zadeklarowanej instrukcji nie da się odczytać | `-32603` — nigdy pusty dokument; szczegóły w dzienniku serwera |
| ciało nie jest JSON-em | `-32700` |
| żądanie wsadowe albo bez `jsonrpc: "2.0"` | `-32600` |
| błąd wewnętrzny | `-32603`, celowo bez szczegółów — te idą do dziennika serwera |

Rozróżnienie między „pusty wynik" i „brak uprawnień" jest celowe: komunikat
„nie masz dostępu do przestrzeni *Kadry*" sam jest wyciekiem informacji.

Rozróżnienie w drugą stronę jest równie celowe: „nie udało się sprawdzić" nigdy
nie zamienia się w pusty wynik. Agent, któremu powiemy „nic nie ma", zapisze
wiedzę drugi raz obok kopii, której nie zobaczył.
