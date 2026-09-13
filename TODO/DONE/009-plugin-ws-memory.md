---
noteId: "ac1d7760aeb211f1997d030a3cd38ca7"
tags: [ws-memory, todo, plugin, claude-code, hooki, agenci-ai]

---

# TODO-009 — Plugin WS_Memory do Claude Code

**Utworzono:** 2026-09-12 16:03 · **Stan:** ✅ **UKOŃCZONE 2026-09-13 17:23** · **Zależności:** 004, 005

## Powód

Plugin jest jedyną rzeczą, którą deweloper instaluje u siebie, i jedynym
miejscem, gdzie agent dowiaduje się, **jak** korzystać z firmowej bazy wiedzy.
Same narzędzia MCP nie wystarczą: agent musi wiedzieć, że ma szukać **zanim**
odpowie, gdzie zapisywać ustalenia i jak pisać dokumentację, którą przeczyta
człowiek.

## Analiza

Wzorzec konstrukcyjny bierzemy z pluginu MemPalace (przejrzany lokalnie):
`.claude-plugin/plugin.json` z sekcją `mcpServers`, katalogi `hooks/`,
`skills/`, `commands/`, `agents/`.

Trzy mechanizmy Claude Code, potwierdzone w dokumentacji, na których to stoi
(D-012):

- **`dependencies`** w `plugin.json` — deklarujemy wymaganie wtyczki
  `mempalace`, opcjonalnie z ograniczeniem wersji semver. Użytkownik instaluje
  jedną rzecz i dostaje lokalny pałac.
- **`userConfig`** — adres i token pytane przy włączeniu wtyczki, token z
  `sensitive: true`. Dostępne jako `${user_config.KEY}` w konfiguracji MCP i
  `CLAUDE_PLUGIN_OPTION_*` w hookach. **Token nigdy nie trafia do repozytorium
  ani do zmiennych ustawianych ręcznie.**
- **`source: {"type": "command"}`** we wpisie marketplace — polecenie przed
  instalacją: pakiet `mempalace[extract]` i pierwsze `mempalace init`.

Prywatność wynika teraz z architektury, nie z ustawienia: transkrypty mielą się
**do lokalnego pałaca** i nie ma ścieżki, którą surowa rozmowa wychodzi na
serwer. Do wspólnej bazy trafia wyłącznie to, co ktoś opublikuje (`TODO-012`).

Czego **nie** duplikujemy: mielenia transkryptów. Robią to hooki MemPalace,
które przychodzą z zależności. Nasz `session-end` tylko domyka publikację,
jeśli użytkownik ma ustawione lustro.

## Rozwiązanie

1. `plugin/shared/` — treść niezależna od klienta: protokół recall, zasady
   dokumentowania, opisy podagentów. **Pakowania jej nie duplikują, tylko
   zaciągają** (D-013).
2. Wystawienie tej samej treści jako **zasobów MCP** przez gateway
   (`ws-memory://protokol-recall`, `ws-memory://jak-dokumentowac`) — czyta je
   każdy klient MCP, a zmiana instrukcji nie wymaga aktualizacji wtyczek.
3. `plugin/.claude-plugin/plugin.json` — `dependencies: ["mempalace"]`,
   `userConfig` (adres + token `sensitive`), serwer MCP `ws_memory` po HTTP
   z `${user_config.*}`.
4. `plugin/.claude-plugin/marketplace.json` — wpis z `source` typu `command`
   instalującym `mempalace[extract]` i wykonującym `mempalace init`.
5. **Jeden** skrypt `hooks/ws-hook.sh <zdarzenie>` (wzorzec z pakowania
   MemPalace dla Codeksa) plus `hooks.json` mapujący zdarzenia:
   - `session-start` — woła `ws_status`, wstrzykuje kontekst: kim jest
     użytkownik, jakie ma przestrzenie, co się ostatnio zmieniło;
   - `session-end` — **nie** mieli i **nie** wysyła transkryptu (robi to hook
     MemPalace lokalnie); uruchamia publikację lustra, jeśli skonfigurowane.
4. `hooks/pre-compact.sh` — `ws_diary_write` z podsumowaniem przed
   kompaktowaniem kontekstu.
5. Skille: `ws-memory-recall` (szukaj przed odpowiedzią), `ws-memory-document`
   (jak pisać firmową dokumentację, gdzie co trafia), `ws-memory-setup`
   (konfiguracja tokena).
6. Komendy: `/ws-search`, `/ws-doc`, `/ws-status`.
7. Podagenci: `ws-dokumentalista`, `ws-archiwista`, `ws-onboarding`,
   `ws-recall` — opisy zadań i ograniczeń w `docs/04-plugin.md`.
   `ws-onboarding` ma zakaz odpowiadania z wiedzy ogólnej: brak odpowiedzi w
   bazie zgłasza jako lukę.
9. Firmowy marketplace pluginów + instrukcja instalacji w `docs/04-plugin.md`.

## Kryteria ukończenia

- `claude plugin install ws-memory` na czystej maszynie instaluje **także**
  wtyczkę i pakiet MemPalace, po czym pyta o adres i token; narzędzia `ws_*`
  oraz lokalny serwer `mempalace` działają oba.
- `SessionStart` wstrzykuje kontekst — widoczne w pierwszej odpowiedzi agenta.
- Transkrypt sesji ląduje w **lokalnym** pałacu; w ruchu sieciowym do serwera
  nie ma ani jednego bajtu surowej rozmowy (sprawdzone na zapisie ruchu).
- Token nie występuje w żadnym pliku pluginu ani w zmiennych środowiskowych
  ustawianych ręcznie (sprawdzone `grep`).
- Treść instrukcji występuje w repozytorium **raz** — w `plugin/shared/`;
  pakowanie jej nie kopiuje (sprawdzone przeglądem).
- Zasoby MCP z instrukcjami są odczytywalne przez klienta i zgodne z treścią
  w `shared/`.
- `ws-onboarding` zapytany o rzecz nieobecną w bazie mówi, że jej nie ma,
  zamiast odpowiadać z wiedzy ogólnej.

---

## Co zostało zrobione

**2026-09-13 17:23.** Wtyczka działa i została **zainstalowana z tego
repozytorium**, a nie tylko napisana.

### Powstało

- **`plugin/shared/`** — siedem plików z treścią: protokół recall, zasady
  dokumentowania, konfiguracja, czterej podagenci. To jedyne miejsce, w którym
  ta treść istnieje.
- **`plugin/`** — manifest (`dependencies`, `userConfig`, serwer MCP `ws_memory`
  po HTTP), jeden hook z nazwą zdarzenia w argumencie, trzy komendy, trzy skille
  i katalog podagentów. Skille i podagenci to **dowiązania** do `shared/`.
- **`.claude-plugin/marketplace.json`** w korzeniu repozytorium, wskazujący
  `./plugin` (D-033).
- **Zasoby MCP** w gatewayu — `resources/list`, `resources/read`, `resources`
  w `capabilities`. Siedem adresów `ws-memory://`, treść czytana wprost
  z `plugin/shared/`, frontmatter wycięty.
- **Dwie decyzje**: D-033 (jedno repozytorium) i D-034 (korekta mechaniki D-012).

### Kryteria ukończenia — jak sprawdzone

| Kryterium | Wynik |
|---|---|
| instalacja pociąga MemPalace i pyta o adres/token | **spełnione z poprawką**: zależność musi być kwalifikowana (`mempalace@mempalace`), bo sama nazwa szuka we własnym marketplace. Instalacja przeszła, `claude mcp list` pokazuje `plugin:ws-memory:ws_memory … ✔ Connected` |
| `SessionStart` wstrzykuje kontekst | **spełnione**, sprawdzone prawdziwą sesją: hook dostał `session-start` oraz `CLAUDE_PLUGIN_OPTION_URL/TOKEN`, i zwrócił kontekst z przestrzeniami i miejscem lądowania zapisu |
| ani bajta surowej rozmowy do serwera | **spełnione**, sprawdzone zapisem ruchu: całe żądanie to 91 bajtów, `ws_status` bez argumentów; znaczniki podstawione w transkrypcie i na wejściu nie wystąpiły |
| token w żadnym pliku wtyczki | **spełnione**: `grep` po `plugin/` daje wyłącznie `${user_config.token}` i `$CLAUDE_PLUGIN_OPTION_TOKEN`. Po instalacji token leży w `~/.claude/.credentials.json`, poza repozytorium |
| treść w repozytorium raz | **spełnione**, sprawdzone świeżym klonem: `plugin/agents` ma tryb `120000`, a tekst protokołu występuje w klonie w **jednym** pliku |
| zasoby MCP zgodne z `shared/` | **spełnione**: treść trzech zasobów porównana bajt w bajt z plikami; testy backendu porównują z prawdziwym plikiem, nie z kopią |
| `ws-onboarding` nie odpowiada z wiedzy ogólnej | **niesprawdzone** — patrz niżej |

### Czego nie udało się sprawdzić

**Zakazu odpowiadania z wiedzy ogólnej w `ws-onboarding` nie da się potwierdzić
testem.** Jest wpisany w polecenie podagenta i nie ma mechanizmu, który by go
wymusił — lista dozwolonych narzędzi tego nie robi, bo odpowiedź z własnej
wiedzy nie jest wywołaniem narzędzia. Zapisano to jako ograniczenie, nie jako
spełnione kryterium. Sprawdzalne dopiero użyciem na prawdziwym pytaniu.

### Co wyszło po drodze

**Prawdziwa instalacja wyłapała trzy błędy, których `claude plugin validate`
nie widzi:** zadeklarowany klucz `"hooks"` (`Duplicate hooks file detected`),
niekwalifikowana zależność, i — najgorszy — dowiązania do **plików**
w `agents/`, które **nie ładują się bez żadnego błędu** (`Agents (0)`). Działa
dowiązanie do katalogu. Wniosek jest w D-034: sprawdzeniem końcowym jest
policzenie składników w `claude plugin details`, nie zielony walidator.

**Hook przy złym tokenie wstrzykiwał pustą ramkę kontekstu** — dla agenta
wygląda to jak „baza nic nie ma". Poprawione: przy każdej porażce milczy.

**Dwa błędy w mojej własnej dokumentacji wyłapał podagent tłumaczący:** D-034
podawała trzy różne liczby błędnych założeń, a blok JSON pokazywał dokładnie tę
zepsutą postać zależności, przed którą ostrzegał akapit obok.

**Commit `d218761` („backend: dodaj port biblioteki instrukcji w Domain") zawiera
też pakowanie wtyczki.** Dwie sesje pracowały w jednym drzewie, a gołe
`git commit` zabiera cały wspólny indeks. Nic nie zginęło, ale opis kłamie.
Historii nie przepisywałem — dwanaście commitów ryzyka za jeden opis to zły
interes. Reguła zapobiegająca powtórce jest w `AGENTS.md`.

### Odłożone

- **`session-end` i `/ws-publish`** — publikacja lustra to `TODO-012`. Hook,
  który nic nie robi, wygląda jak działająca funkcja.
- **`pre-compact`** — zdarzenia nie ma w udokumentowanej liście, a nasz hook
  mógłby wysłać wyłącznie surową rozmowę, co jest zakazane (D-034).
- **`auto_publish` w `userConfig`** — sterowałby czymś, czego nie ma.
- **Pakowania dla Codeksa i Cursora** — dopiero gdy ktoś ich użyje (D-013).
- **`resources/subscribe` i stronicowanie zasobów** — siedem statycznych
  dokumentów tego nie potrzebuje.
- **Pełny przebieg CI nie łapie zmiany samej instrukcji**, bo jego `paths-ignore`
  zawiera `**.md`, a filtry ścieżek GitHuba nie mają negacji. Szybkie sprawdzenie
  ten przypadek pokrywa — `plugin/` weszło do zakresu backendu.
