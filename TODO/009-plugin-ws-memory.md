---
noteId: "ac1d7760aeb211f1997d030a3cd38ca7"
tags: [ws-memory, todo, plugin, claude-code, hooki, agenci-ai]

---

# TODO-009 — Plugin WS_Memory do Claude Code

**Utworzono:** 2026-09-12 16:05 · **Stan:** do zrobienia · **Zależności:** 004, 005

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

1. `plugin/.claude-plugin/plugin.json` — `dependencies: ["mempalace"]`,
   `userConfig` (adres + token `sensitive`), serwer MCP `ws_memory` po HTTP
   z `${user_config.*}`.
2. `plugin/.claude-plugin/marketplace.json` — wpis z `source` typu `command`
   instalującym `mempalace[extract]` i wykonującym `mempalace init`.
3. `hooks/session-start.sh` — woła `ws_status`, wstrzykuje do kontekstu:
   kim jest użytkownik, jakie ma przestrzenie, co się ostatnio zmieniło.
4. `hooks/session-end.sh` — **nie** mieli i **nie** wysyła transkryptu
   (robi to hook MemPalace lokalnie); uruchamia publikację lustra, jeśli
   użytkownik je skonfigurował.
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
- `ws-onboarding` zapytany o rzecz nieobecną w bazie mówi, że jej nie ma,
  zamiast odpowiadać z wiedzy ogólnej.
