---
noteId: "be5f5840aeb111f1997d030a3cd38ca7"
tags: []

---

# Plugin WS_Memory do Claude Code

Stan: **projekt**, nieimplementowany (2026-09-12).

Plugin jest jedyną rzeczą, którą deweloper instaluje na swojej maszynie.
**Nie wymaga MemPalace, Pythona ani modelu embeddingów** — cała praca dzieje
się po stronie serwera (D-006).

## Struktura

```
plugin/
  .claude-plugin/
    plugin.json          ← serwer MCP + metadane
    marketplace.json     ← wpis do firmowego marketplace'u
  hooks/
    session-start.sh     ← wstrzyknięcie kontekstu z bazy
    session-end.sh       ← przyrostowy wysył transkryptu
    pre-compact.sh       ← zapis podsumowania przed kompaktowaniem
  skills/
    ws-memory-recall/    ← protokół: szukaj przed odpowiedzią
    ws-memory-document/  ← jak pisać firmową dokumentację
    ws-memory-setup/     ← konfiguracja tokena
  commands/
    ws-search.md
    ws-doc.md
    ws-status.md
  agents/
    ws-dokumentalista.md
    ws-archiwista.md
    ws-onboarding.md
    ws-recall.md
```

## Konfiguracja MCP

`plugin.json` wskazuje serwer po HTTP, z tokenem ze zmiennej środowiskowej —
token **nie trafia do repozytorium pluginu**:

```json
{
  "name": "ws-memory",
  "mcpServers": {
    "ws_memory": {
      "type": "http",
      "url": "${WS_MEMORY_URL}/mcp",
      "headers": { "Authorization": "Bearer ${WS_MEMORY_TOKEN}" }
    }
  }
}
```

## Hooki

Wszystkie trzy to krótkie skrypty na `curl`. Zero zależności poza tym, co jest
w każdym systemie.

**`session-start`** — woła `ws_status` i wstrzykuje do kontekstu: kim jest
użytkownik, jakie ma przestrzenie, co się ostatnio zmieniło w projekcie, nad
którym pracuje. Cel: agent zaczyna sesję wiedząc, gdzie jest wiedza, zamiast
zgadywać.

**`session-end`** — wysyła **tylko nowe linie** transkryptu na
`POST /api/sessions/{id}/transcript`. Offset trzymany lokalnie w
`~/.ws-memory/offsets/`. Serwer dopisuje je do pliku sesji i tworzy zadanie
mielenia. Przyrostowość jest istotna: transkrypt długiej sesji to megabajty,
a wysyłany jest po każdym zakończeniu.

**`pre-compact`** — przed kompaktowaniem kontekstu zapisuje przez
`ws_diary_write` podsumowanie tego, co ustalono. Ratuje wnioski, które
inaczej wyparowałyby razem z kontekstem.

### Prywatność

Transkrypty trafiają domyślnie do **prywatnej przestrzeni dewelopera**, nie do
wspólnej. Do przestrzeni zespołowej wędruje tylko to, co ktoś świadomie tam
umieści. Hook ma wyłącznik (`WS_MEMORY_TRANSCRIPTS=0`) dla sesji, których nie
chce się archiwizować.

## Skille

**`ws-memory-recall`** — protokół odtwarzania wiedzy: **szukaj w bazie zanim
odpowiesz** o przeszłych ustaleniach, decyzjach, osobach i projektach. Nigdy
nie zgaduj. Wzorowany na skillu `mempalace-recall`, ale odpytuje `ws_search`,
więc respektuje uprawnienia.

**`ws-memory-document`** — jak pisać firmową dokumentację: struktura dokumentu,
język polski, gdzie co trafia (`documentation` vs `technical` vs `decisions`),
kiedy nowy dokument, a kiedy rewizja istniejącego. Zawiera regułę: **jeśli
piszesz dokument, którego nie zweryfikuje człowiek, powiedz to wprost w
podsumowaniu sesji.**

**`ws-memory-setup`** — przeprowadza przez wystawienie tokena w interfejsie
i ustawienie zmiennych środowiskowych. Uruchamiany raz na maszynę.

## Podagenci

| Agent | Zadanie | Kiedy |
|---|---|---|
| **`ws-dokumentalista`** | spisuje, co powstało w zadaniu, do dokumentu kanonicznego w wiki | po zamknięciu zadania |
| **`ws-archiwista`** | przegląda przestrzeń, znajduje duplikaty i sprzeczności, proponuje scalenia | cyklicznie, na żądanie |
| **`ws-onboarding`** | odpowiada nowej osobie **wyłącznie** na podstawie bazy, z linkami do źródeł; brak odpowiedzi w bazie zgłasza jako lukę | przy wdrożeniu nowej osoby |
| **`ws-recall`** | głębokie szperanie w bazie przed decyzją: wszystkie wcześniejsze ustalenia w temacie | przed zmianą architektury |

`ws-onboarding` ma celowe ograniczenie: **nie wolno mu odpowiadać z wiedzy
ogólnej.** Jeśli baza nie zawiera odpowiedzi, ma to powiedzieć i zgłosić lukę
w dokumentacji — inaczej nowa osoba nie wiedziałaby, czy dostała firmową
praktykę, czy domysł modelu.

## Instalacja u dewelopera

```bash
export WS_MEMORY_URL=https://wsmemory.twoja-domena.pl
export WS_MEMORY_TOKEN=<token z /settings/tokens>
claude plugin marketplace add https://git.twoja-domena.pl/ws-memory-plugin
claude plugin install ws-memory
```
