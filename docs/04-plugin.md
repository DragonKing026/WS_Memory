---
noteId: "be5f5840aeb111f1997d030a3cd38ca7"
tags: [ws-memory, dokumentacja, plugin, claude-code, hooki, agenci-ai, hybryda]

---

# Plugin WS_Memory do Claude Code

Stan: **zaimplementowany** (2026-09-13, TODO-009). Wersja `0.1.0`.

Plugin jest jedyną rzeczą, którą użytkownik instaluje — i **pociąga za sobą
wtyczkę MemPalace jako zależność**, więc każdy dostaje lokalny pałac (D-012).

**Docelowo wtyczka istnieje po to, żeby kopia Twojej wiedzy trafiała na
serwer** (D-015) — i tego jeszcze nie robi, patrz ramka niżej. Kto chce
pracować wyłącznie lokalnie, instaluje samo MemPalace i nie zakłada konta; to
jest właściwa droga rezygnacji, nie ustawienie.

Podział pracy jest przez to prosty: **mielenie dzieje się wyłącznie lokalnie**
(projekty, dokumenty, transkrypty rozmów), a serwer nie mieli niczego i nie
przyjmuje surowych źródeł.

> **Czego jeszcze nie ma.** Automatyczna publikacja z lokalnego pałaca na serwer
> (D-014) to `TODO-012` i nie jest zaimplementowana. Wtyczka **nie ma** więc
> hooka `session-end` ani przełącznika `auto_publish` — sterowałby czymś, czego
> nie ma. Dziś wtyczka daje: kontekst na starcie sesji, narzędzia `ws_*`,
> instrukcje i podagentów — czyli **czytanie wspólnej bazy i pisanie do niej
> wprost**. Automatyczne kopiowanie lokalnego pałaca dochodzi w `TODO-012`.
> Szczegóły i powody: D-034.

## Struktura

```
.claude-plugin/
  marketplace.json           ← w KORZENIU repozytorium, wskazuje ./plugin (D-033)
plugin/
  shared/                    ← JEDNO źródło treści, niezależne od klienta AI
    protokol-recall.md       ← szukaj w bazie, zanim odpowiesz
    jak-dokumentowac.md      ← struktura dokumentu, język, gdzie co trafia
    konfiguracja.md          ← token, lokalny pałac, diagnostyka
    agenci/                  ← opisy podagentów
  .claude-plugin/
    plugin.json              ← dependencies, userConfig, serwer MCP
  hooks/
    hooks.json               ← mapowanie zdarzeń na jeden skrypt (ładowany sam)
    ws-hook.sh               ← JEDEN skrypt: ws-hook.sh <zdarzenie>
  skills/*/SKILL.md          ← dowiązania do plików z shared/
  agents -> shared/agenci    ← dowiązanie do KATALOGU, nie do plików (patrz niżej)
  commands/                  ← /ws-search /ws-doc /ws-status
```

Podział jest celowy (D-013): **`shared/` to treść, reszta to opakowanie.**

### Treść istnieje raz — dosłownie

`plugin/skills/ws-memory-recall/SKILL.md` **jest dowiązaniem symbolicznym** do
`plugin/shared/protokol-recall.md`. Nie ma kopii do zsynchronizowania, bo nie
ma kopii.

Walidator mówi o tych plikach wprost:

> „3 components here were not read — the path is not a regular file (a symlink…).
> **A session loading this plugin does follow them**, so validate the real paths
> separately."

Czyli sesja dowiązania **przechodzi**, a walidator ich nie czyta i każe sprawdzić
prawdziwe ścieżki osobno. Robimy oba sprawdzenia.

**Podagenci wymagają dowiązania do katalogu, nie do plików** — i to nie jest
kwestia gustu, tylko wynik pomiaru. Przy czterech dowiązaniach `plugin/agents/*.md`
inwentarz wtyczki pokazywał `Agents (0)`: pliki **nie ładowały się w ogóle, bez
żadnego błędu**. Podmiana jednego z nich na zwykły plik dała `Agents (1)`, a
zamiana całego `plugin/agents/` na dowiązanie do `shared/agenci/` — `Agents (4)`.
Skille tego problemu nie mają: tam dowiązania do plików ładują się normalnie.

To jest dokładnie ten rodzaj usterki, przed którym nie chroni walidator: nic nie
pada, po prostu połowa wtyczki nie istnieje. Sprawdzeniem jest
`claude plugin details ws-memory@web-systems` i policzenie składników.

Koszt, który trzeba nazwać: dowiązania w gicie wymagają na Windowsie
`core.symlinks=true`. Zespół pracuje na Linuksie.

### Ta sama treść jako zasoby MCP

Instrukcje są wystawione również przez gateway jako zasoby MCP
(`ws-memory://protokol-recall`, `ws-memory://jak-dokumentowac`, …). Czyta je
**każdy klient MCP**, nie tylko Claude Code, a zmiana instrukcji jest wtedy
deployem serwera zamiast aktualizacji wtyczki u każdej osoby z osobna.
Szczegóły: `docs/03-mcp-gateway.md`.

## Konfiguracja: zależność, MCP i `userConfig`

**`dependencies: ["mempalace@mempalace"]`** — wtyczka deklaruje, że wymaga
wtyczki MemPalace. Użytkownik instaluje jedną rzecz.

Nazwa jest **kwalifikowana marketplace'em** i musi taka być: przy samym
`"mempalace"` instalator szukał zależności we **własnym** marketplace i odmówił
komunikatem `Dependency "mempalace@web-systems" is not installed`. Zapis
`wtyczka@marketplace` wskazuje, skąd ją wziąć.

**`userConfig`** — adres instancji i token są pytane **przy włączeniu wtyczki**,
token oznaczony jako `sensitive`. Wartości trafiają do konfiguracji MCP jako
`${user_config.KEY}` (również wewnątrz nagłówków) i do hooków jako
`CLAUDE_PLUGIN_OPTION_*`. Nikt nie ustawia zmiennych środowiskowych ręcznie
i **token nie trafia do repozytorium**.

```json
{
  "name": "ws-memory",
  "dependencies": ["mempalace@mempalace"],
  "userConfig": {
    "url":   { "type": "string", "title": "Adres WS_Memory" },
    "token": { "type": "string", "title": "Token agenta", "sensitive": true }
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

Klucza `"hooks"` w manifeście **nie ma celowo**. `hooks/hooks.json` ładuje się
sam, a zadeklarowanie go dodatkowo jest błędem ładowania:
`Duplicate hooks file detected`. Pole służy do wskazywania **dodatkowych**
plików z hookami.

Serwer MCP `mempalace` (lokalny, stdio) przychodzi z wtyczki MemPalace — nie
konfigurujemy go u siebie. Agent widzi oba naraz.

**Pakiet Pythona instaluje człowiek.** Wtyczka MemPalace dostarcza manifest,
ale serwer `mempalace` uruchamia pakiet, którego wtyczka nie instaluje — i my
też tego nie udajemy. `pip install "mempalace[extract]"` plus `mempalace init`
opisuje skill `ws-memory-setup`. Dlaczego nie da się tego zautomatyzować
wpisem w marketplace: D-034.

## Hooki

**Jeden skrypt, nazwa zdarzenia w argumencie** — `ws-hook.sh <zdarzenie>`.
Wzorzec z pakowania MemPalace dla Codeksa: port na inny klient AI ma być nowym
manifestem, nie nowym kodem (D-013).

### `session-start` — jedyny hook, jaki jest

Woła `ws_status` i wstrzykuje do kontekstu: do jakich przestrzeni token ma
prawo, z jaką rolą, ile jest w nich wpisów, **gdzie wyląduje zapis bez wskazanej
przestrzeni** oraz przypomnienie protokołu recall. Cel: agent zaczyna sesję
wiedząc, gdzie jest wiedza, zamiast odkrywać swoje uprawnienia przez porażki.

Wstrzyknięcie idzie udokumentowanym kształtem
(`hookSpecificOutput.additionalContext`), bo zwykłe `stdout` trafia do logu,
a nie do kontekstu.

**Hook nigdy nie przerywa pracy.** Brak tokena, brak sieci, padnięty serwer,
błąd uwierzytelnienia — sesja startuje normalnie, tylko bez wstrzykniętego
akapitu. Kod wyjścia jest zawsze zerowy, a limit czasu wynosi 5 sekund przy
10-sekundowym limicie hooka. Sprawdzone dla każdego z tych przypadków osobno.

Jedna rzecz jest tu subtelna i została wyłapana testem: gdy filtr odpowiedzi
się podda (błąd JSON-RPC, odpowiedź nie do sparsowania), hook **nie może**
wstrzyknąć pustej ramki. Pusty kontekst wygląda dla agenta jak „baza nic nie
ma", czyli mówi nieprawdę.

### Czego hook nie robi

**Nie czyta transkryptu i nie wysyła ani bajta rozmowy.** Cały ruch wychodzący
to jedno wywołanie `ws_status` bez argumentów — 91 bajtów. Sprawdzone zapisem
ruchu: hook uruchomiono z podstawionym transkryptem i danymi na wejściu
zawierającymi znaczniki kontrolne, po czym przechwycono całe żądanie:

```
POST /mcp
Content-Type: application/json
Authorization: Bearer [ukryty]
Content-Length: 91

{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"ws_status","arguments":{}}}
```

Żaden ze znaczników w nim nie wystąpił.

Mieleniem transkryptu **do lokalnego pałaca** zajmuje się hook MemPalace, który
przychodzi z zależnością — nie duplikujemy go. Dlaczego nie ma hooka
`pre-compact` ani `session-end`: D-034.

## Skille

Wszystkie trzy to dowiązania do plików w `shared/`.

**`ws-memory-recall`** — protokół odtwarzania wiedzy: **szukaj w bazie zanim
odpowiesz** o przeszłych ustaleniach, decyzjach, osobach i projektach. Narzuca
kolejność: **najpierw wspólna baza (`ws_search`), potem lokalny pałac
(`mempalace_search`)** — notatka jest zapisem czyjegoś myślenia, dokument jest
ustaleniem. Kończy się instrukcją zapisania wniosków przez `ws_diary_write`.

**`ws-memory-document`** — jak pisać firmową dokumentację: która z trzech klas
wiedzy to jest, jak nazwać adres, co napisać w opisie zmiany, gdzie to wyląduje.
Zawiera regułę: **jeśli piszesz dokument, którego nie zweryfikuje człowiek,
powiedz to wprost w podsumowaniu sesji.**

**`ws-memory-setup`** — lokalny pałac, wystawienie tokena, tabela objawów przy
diagnostyce. Uruchamiany raz na maszynę.

## Podagenci

| Agent | Zadanie | Kiedy |
|---|---|---|
| **`ws-dokumentalista`** | spisuje **wynik** zamkniętego zadania do dokumentu kanonicznego | po zamknięciu zadania |
| **`ws-archiwista`** | znajduje duplikaty, sprzeczności i treści nieaktualne; **proponuje**, nie wykonuje | cyklicznie, na żądanie |
| **`ws-onboarding`** | odpowiada nowej osobie **wyłącznie** na podstawie bazy, z linkami do źródeł | przy wdrożeniu nowej osoby |
| **`ws-recall`** | głębokie szperanie przed decyzją, łącznie z **odrzuconymi alternatywami** | przed zmianą architektury |

`ws-onboarding` ma celowe ograniczenie: **nie wolno mu odpowiadać z wiedzy
ogólnej.** Jeśli baza nie zawiera odpowiedzi, ma to powiedzieć i nazwać lukę —
z wypisaniem słów, których szukał. Powód jest konkretny: osoba znająca firmę
wyłapie zmyślenie, a **nowa osoba je zapamięta i powtórzy jako obowiązującą
zasadę**.

Ograniczenie jest wpisane w polecenie, **nie w listę dozwolonych narzędzi**.
Lista narzędzi jest listą dozwoleń, a błędnie zapisana nazwa narzędzia MCP
**cicho je usuwa**, zamiast zgłosić błąd — a i tak nie powstrzymałaby modelu
przed odpowiedzią z własnej wiedzy, bo to nie jest narzędzie.

## Komendy

| Komenda | Co robi |
|---|---|
| `/ws-status` | kim jest ten token: przestrzenie, role, gdzie wyląduje zapis |
| `/ws-search <czego>` | najpierw `ws_search`, potem `mempalace_search`, z podaniem źródeł |
| `/ws-doc <co>` | sprawdź, czy dokument istnieje; potem `ws_doc_write` według zasad |

`/ws-publish` powstanie razem z publikacją (`TODO-012`).

## Jak to działa u użytkownika

**Konfiguracja:** dwa serwery MCP naraz — `mempalace` (lokalny, stdio) i
`ws_memory` (wspólny, HTTP). Agent czyta z obu, w kolejności narzuconej przez
skill `ws-memory-recall`.

**Mielenie** robisz sam, u siebie:

```bash
mempalace init ~/projekty/nowy-projekt
mempalace mine ~/projekty/nowy-projekt
```

Kod nie opuszcza laptopa. Nikogo nie musisz o nic prosić.

**Praca bez sieci działa w pełni.** Lokalny pałac jest pierwotny (D-015), a hook
startowy przy niedostępnym serwerze po prostu milczy.

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
i jest wystawiona także jako **zasoby MCP** — a to czyta każdy klient MCP.

**Nieprzenośne i duplikowane:** manifesty, mapowanie hooków, opakowania skilli.
Pakowania dla innych klientów **nie powstają na zapas** — dopiero gdy ktoś
faktycznie z nich korzysta.

## Instalacja u dewelopera

```bash
pip install "mempalace[extract]" && mempalace init

claude plugin marketplace add MemPalace/mempalace
claude plugin marketplace add DragonKing026/Websystems --sparse .claude-plugin plugin
claude plugin install ws-memory
```

Marketplace MemPalace dodaje się **osobno**, bo to stamtąd pochodzi zależność.
`--sparse` ogranicza pobieranie do katalogów wtyczki — nie ściągasz całej
aplikacji, żeby dostać wtyczkę (D-033). Instalator zapyta o adres oraz token.
Żadnych zmiennych środowiskowych do ustawiania ręcznie.

**Token nie trafia do repozytorium ani do żadnego pliku wtyczki.** Sprawdzone po
instalacji: ląduje w `~/.claude/.credentials.json`, bo `userConfig` oznacza go
jako `sensitive`. W katalogu projektu powstaje tylko `.claude/settings.local.json`
z nazwą wtyczki i ścieżką do marketplace'u — bez tokena, i ignorowany przez gita.

Sprawdzenie, czy się udało:

```bash
claude plugin details ws-memory@web-systems   # 3 skille, 4 podagentów, 1 hook
claude mcp list                               # plugin:ws-memory:ws_memory … ✔ Connected
```

Token wystawiasz w WS_Memory → **Ustawienia → Tokeny agentów**. Pokazuje się
raz. Token wystawiony do jednorazowej pracy odwołuje się po jej zakończeniu.
