---
noteId: "be5f5840aeb111f1997d030a3cd38ca7"
tags: [ws-memory, dokumentacja, plugin, claude-code, hooki, agenci-ai, hybryda]

---

# Plugin WS_Memory do Claude Code

Stan: **projekt**, nieimplementowany (2026-09-12).

Plugin jest jedyną rzeczą, którą użytkownik instaluje — i **pociąga za sobą
wtyczkę MemPalace jako zależność**, więc każdy dostaje lokalny pałac (D-012).

**Wtyczka istnieje po to, żeby kopia Twojej wiedzy trafiała na serwer.** Kto
chce pracować wyłącznie lokalnie, instaluje samo MemPalace i nie zakłada konta
— to jest właściwa droga rezygnacji, nie ustawienie (D-015).

Podział pracy jest przez to prosty: **mielenie dzieje się wyłącznie lokalnie**
(projekty, dokumenty, transkrypty rozmów), a **wynik domyślnie jedzie na
serwer** — bez klikania i bez pamiętania (D-014). Serwer nie mieli niczego i
nie przyjmuje surowych źródeł; dostaje gotowe szuflady.

## Struktura

```
plugin/
  shared/                    ← JEDNO źródło treści, niezależne od klienta AI
    protokol-recall.md       ← szukaj w bazie, zanim odpowiesz
    jak-dokumentowac.md      ← struktura dokumentu, język, gdzie co trafia
    agenci/                  ← opisy podagentów jako Markdown
  .claude-plugin/
    plugin.json              ← dependencies, userConfig, serwer MCP
    marketplace.json         ← wpis z poleceniem instalacyjnym
    hooks.json               ← mapowanie zdarzeń na jeden skrypt
    hooks/ws-hook.sh         ← JEDEN skrypt: ws-hook.sh <zdarzenie>
    skills/                  ← cienkie opakowania treści z shared/
    commands/                ← /ws-search /ws-doc /ws-status /ws-publish
    agents/                  ← podagenci, treść z shared/agenci/
  .codex-plugin/             ← powstanie dopiero, gdy ktoś użyje Codeksa
```

Podział jest celowy (D-013): **`shared/` to treść, reszta to opakowanie.**
Jeden skrypt hooka przyjmujący nazwę zdarzenia zamiast trzech osobnych —
tak samo jak w pakowaniu MemPalace dla Codeksa. Dzięki temu port na inny
klient AI to nowy manifest, nie nowy kod.

## Konfiguracja: zależność, MCP i `userConfig`

Trzy mechanizmy Claude Code, na których to stoi:

**`dependencies`** — wtyczka deklaruje, że wymaga wtyczki MemPalace. Dzięki
temu użytkownik nie musi wiedzieć, że pod spodem jest MemPalace; instaluje
jedną rzecz.

**`userConfig`** — adres instancji, token i przełącznik `auto_publish`
(domyślnie włączony) są pytane **przy włączeniu wtyczki**,
a token oznaczony jako `sensitive`. Wartości trafiają do konfiguracji MCP jako
`${user_config.KEY}` i do hooków jako `CLAUDE_PLUGIN_OPTION_*`. Nikt nie
ustawia zmiennych środowiskowych ręcznie i **token nie trafia do repozytorium**.

**`source: {"type": "command"}`** we wpisie marketplace — polecenie uruchamiane
przed instalacją. Tu instalujemy pakiet `mempalace` (z wariantem `extract`, żeby
dało się mielić PDF-y i DOCX-y) i wykonujemy pierwsze `mempalace init`.

```json
{
  "name": "ws-memory",
  "dependencies": ["mempalace"],
  "userConfig": {
    "url": {
      "type": "string",
      "title": "Adres WS_Memory",
      "description": "np. https://wsmemory.twoja-domena.pl"
    },
    "token": {
      "type": "string",
      "title": "Token agenta",
      "description": "Wystawisz go w WS_Memory → Ustawienia → Tokeny",
      "sensitive": true
    },
    "auto_publish": {
      "type": "boolean",
      "title": "Wysyłaj automatycznie na serwer",
      "description": "Domyślnie włączone. Skrzydła bez mapowania trafiają do Twojej prywatnej przestrzeni.",
      "default": true
    }
  },
  "mcpServers": {
    "ws_memory": {
      "type": "http",
      "url": "${user_config.url}/mcp",
      "headers": { "Authorization": "Bearer ${user_config.token}" }
    }
  }
}
```

Serwer MCP `mempalace` (lokalny, stdio) przychodzi z wtyczki MemPalace — nie
konfigurujemy go u siebie. Agent widzi oba naraz.

## Hooki

Wszystkie trzy to krótkie skrypty na `curl`. Zero zależności poza tym, co jest
w każdym systemie.

**`session-start`** — woła `ws_status` i wstrzykuje do kontekstu: kim jest
użytkownik, jakie ma przestrzenie, co się ostatnio zmieniło w projekcie, nad
którym pracuje. Cel: agent zaczyna sesję wiedząc, gdzie jest wiedza, zamiast
zgadywać.

**`session-end`** — mielenie transkryptu **do lokalnego pałaca** (robi to hook
MemPalace, którego nie duplikujemy) plus, jeśli użytkownik ma ustawione lustro,
publikacja przyrostowa nowych szuflad do wspólnej bazy. Surowa rozmowa nigdy
nie opuszcza maszyny.

**`pre-compact`** — przed kompaktowaniem kontekstu zapisuje przez
`ws_diary_write` podsumowanie tego, co ustalono. Ratuje wnioski, które
inaczej wyparowałyby razem z kontekstem.

### Prywatność

Transkrypty rozmów zostają **na maszynie użytkownika**, w jego lokalnym pałacu.
Do wspólnej bazy trafia wyłącznie to, co ktoś opublikuje — ręcznie albo lustrem,
którego pierwszy przebieg wymaga potwierdzenia. Nie ma ścieżki, którą surowa
rozmowa wychodzi na serwer, więc nie ma czego zabezpieczać.

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

## Jak to działa u użytkownika

**Konfiguracja:** dwa serwery MCP naraz — `mempalace` (lokalny, stdio) i
`ws_memory` (wspólny, HTTP). Agent czyta z obu. Skill `ws-memory-recall`
narzuca kolejność: **najpierw wspólna baza, potem lokalna** — wiedza zespołu
ma pierwszeństwo przed prywatnymi notatkami.

**Mielenie** robisz sam, u siebie, jak dotąd:

```bash
mempalace init ~/projekty/nowy-projekt
mempalace mine ~/projekty/nowy-projekt
```

Kod nie opuszcza laptopa. Nikogo nie musisz o nic prosić.

**Domyślnie: wysyłka automatyczna.** Wszystko, co trafia do lokalnego pałaca,
jedzie na serwer po zakończeniu sesji albo po lokalnym mieleniu. Gdzie ląduje:

| Skrzydło lokalnego pałaca | Ląduje w |
|---|---|
| **zmapowane** na przestrzeń zespołową | tej przestrzeni — widzi zespół |
| **niezmapowane** | Twojej **prywatnej przestrzeni na serwerze** |

Czyli wszystko masz na serwerze zawsze (kopia, wyszukiwanie, dostęp z drugiej
maszyny), ale nic nie staje się widoczne dla innych, dopóki nie zmapujesz
skrzydła. Mapowanie potwierdzasz raz — to ono decyduje o widoczności.

Gdy nazwa lokalnego skrzydła odpowiada istniejącej przestrzeni zespołowej,
wtyczka **zaproponuje mapowanie**. Warto się zgodzić: wtedy odsiew po skrócie
treści działa i to samo repozytorium zmielone przez trzy osoby nie leży w bazie
w trzech kopiach.

**Praca bez sieci działa w pełni.** Lokalny pałac jest pierwotny, serwer dostaje
kopię — więc zapis nigdy nie czeka na serwer i nigdy nie zawodzi z jego powodu.
W pociągu, przy padniętym serwerze, w trakcie aktualizacji: mielisz i zapisujesz
normalnie, a niewysłane szuflady czekają w kolejce i dopinają się same, gdy
łączność wróci (D-015).

**Tryb ręczny** — hamulec awaryjny, nie główny sposób pracy. Wyłącznik
`auto_publish` w ustawieniach wtyczki; wtedy nic nie wychodzi samo, a
publikujesz komendą `/ws-publish`: wybierasz skrzydło, temat albo zakres dat,
widzisz podgląd, potwierdzasz. Przydatne, gdy świadomie nie chcesz kopiować
konkretnej pracy.

**Zabezpieczenia**, skoro wysyłka dzieje się bez nadzoru:

- **mapowanie na przestrzeń zespołową wymaga potwierdzenia** — bez niego treść
  zostaje w Twojej prywatnej przestrzeni;
- **wykluczenia tematów** w mapowaniu (np. skrzydło projektu bez dziennika);
- **filtr sekretów po obu stronach** — klient nie wysyła, serwer i tak sprawdza;
  od D-014 leży on na *każdej* ścieżce, więc jest krytyczny;
- **dziennik partii** z filtrem po przestrzeni i **wycofaniem jednym
  działaniem** — w każdej chwili widzisz, co i gdzie poleciało;
- **pauza i wyłącznik** — globalny oraz per mapowanie.

Czego tryb hybrydowy nie daje: jednego zapytania obejmującego oba magazyny.
To dwa indeksy, więc agent pyta dwa razy.

## Przenośność na inne klienty AI

Budujemy najpierw dla Claude Code, ale system nie jest do niego przywiązany
(D-013).

**Przenośne bez żadnej pracy:** gateway to serwer MCP po HTTP. Codex
(`codex mcp add --transport http`), Cursor (`mcp.json`), Zed, Antigravity czy
Copilot w VS Code połączą się z nim od razu. Narzędzia `ws_*`, uprawnienia,
tokeny i audyt działają identycznie, bo **żadne z nich nie jest we wtyczce**.

**Przenośne niskim kosztem:** instrukcje. Treść ma jedno źródło w `shared/`
i jest wystawiona także jako **zasoby MCP** (`ws-memory://protokol-recall`,
`ws-memory://jak-dokumentowac`) oraz w opisach narzędzi — a to czyta każdy
klient MCP. Skutek uboczny, cenny sam w sobie: zmiana instrukcji to **deploy
serwera, a nie aktualizacja wtyczki u każdej osoby z osobna**.

**Nieprzenośne i duplikowane:** hooki, skille, komendy, podagenci. Patrząc na
MemPalace, który utrzymuje cztery pakowania naraz, koszt jest znany:
`.codex-plugin` ma ten sam kształt `hooks.json` co Claude (SessionStart / Stop
/ PreCompact), różni się nazwą zmiennej ze ścieżką; `.cursor-plugin` to sam
`mcp.json`, bo Cursor nie ma hooków. To przepisanie manifestów, nie logiki.

Pakowania dla innych klientów **nie powstają na zapas** — dopiero gdy ktoś
faktycznie z nich korzysta.

## Instalacja u dewelopera

```bash
claude plugin marketplace add https://git.twoja-domena.pl/ws-memory-plugin
claude plugin install ws-memory
```

Instalator pociągnie wtyczkę MemPalace, zainstaluje pakiet i zapyta o adres
oraz token (`userConfig`). Żadnych zmiennych środowiskowych do ustawiania
ręcznie.
