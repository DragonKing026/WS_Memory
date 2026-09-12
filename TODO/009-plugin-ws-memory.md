# 009 — Plugin WS_Memory do Claude Code

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

Twarde ograniczenie, które trzeba utrzymać: **hooki nie mogą wymagać Pythona
ani MemPalace na maszynie dewelopera** (D-006). Zwykły `curl` w skrypcie
powłoki — nic więcej.

Transkrypty wysyłamy **przyrostowo**, bo transkrypt długiej sesji to megabajty,
a hook `SessionEnd` odpala się po każdej sesji. Offset trzymamy lokalnie.

Prywatność: transkrypty domyślnie do prywatnej przestrzeni autora. Wysłanie
całej rozmowy do wspólnej bazy bez decyzji człowieka byłoby nadużyciem — ludzie
rozmawiają z agentami także o rzeczach, których nie chcą archiwizować zespołowo.

Token nie może wylądować w repozytorium pluginu — wyłącznie zmienna środowiskowa.

## Rozwiązanie

1. `plugin/.claude-plugin/plugin.json` — serwer MCP `ws_memory` przez
   `--transport http`, adres i token ze zmiennych `WS_MEMORY_URL` /
   `WS_MEMORY_TOKEN`.
2. `hooks/session-start.sh` — woła `ws_status`, wstrzykuje do kontekstu:
   kim jest użytkownik, jakie ma przestrzenie, co się ostatnio zmieniło.
3. `hooks/session-end.sh` — wysyła nowe linie transkryptu na
   `POST /api/sessions/{id}/transcript`; offset w `~/.ws-memory/offsets/`;
   wyłącznik `WS_MEMORY_TRANSCRIPTS=0`.
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
8. Endpoint `POST /api/sessions/{id}/transcript` w backendzie (przyrostowy
   zapis + utworzenie `mining_jobs`).
9. Firmowy marketplace pluginów + instrukcja instalacji w `docs/04-plugin.md`.

## Kryteria ukończenia

- `claude plugin install ws-memory` na czystej maszynie **bez MemPalace i bez
  Pythona** daje działające narzędzia `ws_*`.
- `SessionStart` wstrzykuje kontekst — widoczne w pierwszej odpowiedzi agenta.
- `SessionEnd` wysyła tylko nowe linie: druga sesja nie duplikuje pierwszej
  (sprawdzone na `session_uploads.byte_offset`).
- Transkrypt trafia do prywatnej przestrzeni autora, nie do wspólnej.
- `WS_MEMORY_TRANSCRIPTS=0` skutecznie wyłącza wysyłkę.
- Token nie występuje w żadnym pliku pluginu (sprawdzone `grep`).
- `ws-onboarding` zapytany o rzecz nieobecną w bazie mówi, że jej nie ma,
  zamiast odpowiadać z wiedzy ogólnej.
