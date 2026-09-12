---
noteId: "57b9d670aeb011f1997d030a3cd38ca7"
tags: [ws-memory, decyzje, adr, architektura, uzasadnienia]

---

# Decyzje techniczne

Każda decyzja ma numer, datę, stan i uzasadnienie wraz z **odrzuconymi
alternatywami**. Decyzji nie edytujemy — zastąpioną oznaczamy jako
`Zastąpiona przez D-00x` i dopisujemy nową.

Stan: `Przyjęta` · `Zastąpiona` · `Odrzucona`

---

## D-001 — Symfony 8 jako aplikacja, MemPalace jako sidecar

**Data:** 2026-09-12 · **Stan:** Przyjęta
· **Zmieniona w zakresie warstwy prezentacji przez D-008**

Symfony 8 / PHP 8.4 obsługuje logowanie, wiki, uprawnienia i gateway MCP.
MemPalace stoi w osobnym kontenerze i jest odpytywany po HTTP MCP jako czarna
skrzynka.

> Pierwotnie decyzja obejmowała także interfejs w Twigu. **D-008 to zmienia**:
> Symfony jest czystym API, interfejs jest osobną aplikacją Vue. Pozostała
> część decyzji (Symfony jako backend, MemPalace jako sidecar) obowiązuje.

**Dlaczego:** zespół Web Systems utrzymuje kod w Symfony (główna aplikacja Symfony zespołu: Symfony 8,
PHP 8.4, Doctrine, Twig). Kod w technologii, której zespół nie używa
codziennie, gnije szybciej niż rośnie. Traktowanie MemPalace jako czarnej
skrzynki za granicą HTTP oznacza, że jego aktualizacja do nowej wersji nie
dotyka naszego kodu.

**Odrzucono:**
- *Gateway w Pythonie + web app w Symfony* — dwa języki i dwa repozytoria do
  utrzymania w zamian za dostęp do wnętrza MemPalace, którego nie potrzebujemy.
- *Całość w Pythonie* — najszybsze do pierwszej wersji, ale utrzymanie spada
  na technologię poza kompetencją zespołu.
- *Symfony API + SPA React* — lepsze UX edytora, ale trzeci komponent w
  deploymencie; można dodać później, jeśli edytor wiki tego zażąda.

---

## D-002 — PostgreSQL 18 + pgvector zamiast MariaDB

**Data:** 2026-09-12 · **Stan:** Przyjęta

Jedna baza PostgreSQL 18 z rozszerzeniem pgvector. Dwa schematy: `palace`
(tabele MemPalace) i `ws` (dane aplikacji).

**Dlaczego** — to świadome odejście od firmowego domyślnego MariaDB, z czterech
konkretnych powodów:

1. **Wyszukiwanie pełnotekstowe po polsku.** Postgres ma `tsvector` ze
   słownikami Hunspell, czyli stemming: „umowy", „umowa", „umowie" trafiają w
   to samo hasło. MariaDB `FULLTEXT` nie ma polskiego stemmingu i sprowadza się
   do dopasowań prefiksowych. W bazie wiedzy pisanej po polsku to różnica
   między działającą i niedziałającą wyszukiwarką.
2. **pgvector.** MemPalace wspiera pgvector jako pełny backend, więc pałac i
   dane aplikacji mieszkają w jednej bazie: jeden `pg_dump` to pełny backup
   całego systemu, a nie dwa niezależne mechanizmy odtwarzania.
3. **`JSONB` z indeksami GIN** — metadane szuflad i ACL odpytywane w SQL,
   bez wyciągania wszystkiego do PHP.
4. **Transakcyjny DDL.** Migracja Doctrine, która padnie w połowie, cofa się
   w całości. W MariaDB zostaje pół-zmigrowany schemat.

Dla kodu Symfony to zmiana jednej linii w `DATABASE_URL` — Doctrine obsługuje
oba silniki równorzędnie.

**Koszt:** doświadczenie operacyjne zespołu jest w MariaDB; różni się składnia
backupu i narzędzia diagnostyczne. Uznano za akceptowalne.

**Odrzucono:** *osobny magazyn dla pałaca (Chroma na wolumenie)* — dwa systemy
do backupu i brak możliwości wspólnych zapytań SQL między wiki a pałacem.

---

## D-003 — Centralny serwer embeddingów, model `BAAI/bge-m3`

**Data:** 2026-09-12 · **Stan:** Przyjęta

Osobny kontener wystawia OpenAI-kompatybilny `/v1/embeddings`. MemPalace
korzysta z niego przez `MEMPALACE_EMBEDDING_MODEL=openai-compat` +
`MEMPALACE_EMBEDDING_API_URL`. Model: `BAAI/bge-m3` (1024 wymiary).

**Dlaczego:**

- **Domyślny `minilm` jest trenowany tylko na angielskim.** Dla bazy pisanej
  po polsku dałby ciche pogorszenie trafności — system by działał, tylko
  znajdywał nie to, co trzeba.
- **Jeden model dla wszystkich.** Gdyby każda maszyna liczyła wektory
  lokalnie, różnica wersji modelu między laptopami zanieczyściłaby przestrzeń
  wektorową bez żadnego komunikatu o błędzie.
- **`bge-m3` zamiast rodziny `e5`**: modele E5 wymagają prefiksów `query:` /
  `passage:` w tekście, żeby osiągać deklarowaną jakość. MemPalace ich nie
  dodaje, więc jakość spadłaby po cichu. `bge-m3` nie ma tego wymogu i jest
  mocny po polsku.
- Żaden fragment wiedzy firmowej nie wychodzi poza infrastrukturę.

**Konsekwencja operacyjna:** zmiana modelu unieważnia **wszystkie** wektory
w bazie i wymaga przeliczenia jej od zera. Dlatego decyzja podjęta przed
pierwszym zapisem, a nie po.

> **Uzupełnienie po D-010:** ta decyzja dotyczy **wyłącznie serwera**. Lokalne
> pałace deweloperów mogą mieć dowolny model, bo hybryda współdzieli **tekst,
> nie wektory** — serwer przelicza każdą publikowaną szufladę swoim modelem.
> Wymóg jednego modelu w całym zespole zniknął.

**Odrzucono:**
- *`embeddinggemma` lokalnie na każdej maszynie* — 300 MB modelu na laptop i
  ryzyko rozjazdu wersji.
- *Płatne API (OpenAI / Voyage)* — najwyższa jakość, ale cała wiedza firmowa
  wychodzi do zewnętrznego dostawcy, a mining repozytoriów to duży wolumen
  wywołań.

---

## D-004 — Postgres źródłem prawdy dla wiki, pałac warstwą wyszukiwania

**Data:** 2026-09-12 · **Stan:** Przyjęta

Dokumenty wiki mieszkają w schemacie `ws` z pełnymi rewizjami. Po publikacji
treść jest dodatkowo wypychana do pałaca jako szuflada, żeby agenci znajdowali
ją semantycznie. Pamięci agenckie (transkrypty, dziennik, graf wiedzy) żyją
natywnie w pałacu; `ws.memory_entries` trzyma ich metadane do filtrowania
uprawnień i audytu. Przepływ jest **jednokierunkowy**.

**Dlaczego:** wersjonowanie, diff i rollback to zadanie relacyjnej bazy.
Magazyn wektorowy nie ma pojęcia transakcji ani historii rewizji. Metadane
w `ws` pozwalają egzekwować uprawnienia **w zapytaniu SQL**, zamiast filtrować
wyniki po ich pobraniu z pałaca.

**Odrzucono:**
- *Pałac źródłem prawdy dla wszystkiego* — „wersje" jako szuflady oznaczone
  jako zastąpione zaśmiecają wyszukiwanie semantyczne starymi treściami, a
  uprawnienia stają się filtrem po fakcie, czyli wyciekiem.
- *Dwukierunkowa synchronizacja przez workera* — najbogatsza funkcjonalnie,
  ale wprowadza rozwiązywanie konfliktów edycji, pętle synchronizacji i
  zależność od kolejności zdarzeń. Klasa błędów trudna do zdiagnozowania,
  nieproporcjonalna do korzyści.

---

## D-005 — Agent zapisuje bez bramki; wersjonowanie jako siatka bezpieczeństwa

**Data:** 2026-09-12 · **Stan:** Przyjęta

Agent AI zapisuje swobodnie we wszystkich trzech klasach wiedzy, łącznie z
dokumentacją kanoniczną. Wpis autorstwa AI dostaje status `authored_by_ai`
i osobną flagę `verified_by` — **znacznik zaufania, nie warunek publikacji**.
Kolejka propozycji istnieje jako opcja włączana per przestrzeń.

**Dlaczego:** to AI wytworzy większość zapisów — hooki MemPalace mielą
transkrypty rozmów automatycznie, a miner przerabia repozytoria. Bramka
zatwierdzania przed każdym zapisem zamieniłaby system w wąskie gardło i
wymusiłaby obchodzenie go. Ochroną jest możliwość cofnięcia (pełne rewizje),
a nie zablokowanie zapisu. Wyjątkiem są przestrzenie wrażliwe, gdzie kolejkę
włącza się świadomie.

**Ograniczenie, które zostaje:** agent nie weryfikuje własnych wpisów.
Narzędzia `ws_doc_verify` nie ma w API — weryfikacja jest czynnością człowieka
w interfejsie.

---

## D-006 — Zamknięta sieć; hooki wysyłają transkrypty przez HTTPS

**Data:** 2026-09-12 · **Stan:** Przyjęta

`postgres`, `mempalace` i `embeddings` nie mają portów na hoście. Jedyne
wejście z zewnątrz to `nginx` (TLS): `/` dla ludzi, `/mcp` dla agentów. Hooki
na maszynach deweloperów **nie piszą do bazy** — dosyłają przyrostowo
transkrypt sesji przez HTTPS, a mielenie dzieje się po stronie serwera.

**Dlaczego:** wariant, w którym hooki piszą wprost przez
`MEMPALACE_PGVECTOR_DSN`, wymagałby wystawienia Postgresa do internetu. Baza
z całą wiedzą firmy na publicznym porcie to zła wymiana za wygodę.

**Korzyść uboczna, znacząca:** deweloper nie musi mieć zainstalowanego
MemPalace, Pythona ani modelu embeddingów. Wystarczy plugin i token.

> **Uzupełnienie po D-010:** deweloper z lokalnym pałacem mieli transkrypty
> u siebie i publikuje wybrane.
>
> **Zmienione przez D-012:** wysyłki surowych transkryptów na serwer **nie ma
> w ogóle**. Każdy ma lokalny pałac (wtyczka wymaga go jako zależności), więc
> rozmowy mielą się wyłącznie lokalnie. Pozostała część decyzji — zamknięta
> sieć, jedno wejście przez nginx — obowiązuje bez zmian.

**Odrzucono:** *dostęp do Postgresa przez VPN/WireGuard* — możliwy do dodania
później, jeśli pojawi się potrzeba lokalnego minowania repozytoriów bez
wysyłania ich na serwer. Nie jest potrzebny do działania systemu.

---

## D-007 — Kurowany zestaw narzędzi MCP

**Data:** 2026-09-12 · **Stan:** Przyjęta

Gateway wystawia agentom kilkanaście narzędzi firmowych (`ws_*`), a nie
36 narzędzi MemPalace na wylot.

**Dlaczego:** granica narzędzi **jest** granicą uprawnień. Narzędzie
MemPalace, które przyjmuje dowolne `wing`, pozwoliłoby agentowi odczytać
przestrzeń, do której jego właściciel nie ma prawa. Dodatkowo żadne z naszych
narzędzi nie ma parametru „autor" — tożsamość wynika z tokena, więc podszycie
się jest niewyrażalne w API, a nie tylko zabronione regulaminem.

---

## D-008 — Rozdzielenie backendu i frontendu

**Data:** 2026-09-12 15:58 · **Stan:** Przyjęta
· **Zmienia warstwę prezentacji z D-001**

Backend to **czyste API** Symfony 8 (API Platform 4.3) pod `/api`, bez
szablonów renderujących interfejs. Frontend to **osobna aplikacja** Vue 3 na
Vite 7, budowana niezależnie. Jedyny kontrakt między nimi to OpenAPI. `nginx`
kieruje `/` na frontend, a `/api` i `/mcp` na backend — ten sam origin, więc
przeglądarka nie dotyka CORS-a.

Wzorzec przeniesiony z **nowszy projekt z frontendem Vue**, gdzie ten rozdział już się
sprawdził: osobne katalogi aplikacji, nginx jako proxy tego samego originu,
Vite z HMR za nginxem w dev.

**Dlaczego:**

- **Backend musi działać niezależnie.** Gateway MCP dla agentów i REST dla
  ludzi to dwie powierzchnie nad tą samą logiką domenową. Jeśli backend
  renderowałby interfejs, ta logika zaczęłaby wyciekać do szablonów, a agenci
  i ludzie dostaliby rozjeżdżające się zachowania.
- **Interfejs bazy wiedzy jest z natury interaktywny** — wyszukiwanie na żywo,
  porównywanie rewizji, edytor z podglądem, drzewo przestrzeni. Renderowanie
  tego po stronie serwera w Twigu oznaczałoby pisanie tego samego dwa razy:
  raz w HTML, raz w JS.
- **Kompetencja zespołu jest po obu stronach** — Symfony (główna aplikacja Symfony zespołu) i Vue 3
  (nowszy projekt z frontendem Vue). Nie wprowadzamy nowej technologii, tylko używamy
  dwóch już używanych.
- **Frontend można podmienić bez dotykania backendu**, a backend testować bez
  frontendu. Kontrakt OpenAPI jest jednocześnie dokumentacją i punktem
  zaczepienia dla testów.

**Koszt:** jedna usługa więcej w Compose, uwierzytelnianie tokenowe (JWT)
zamiast prostszej sesji, i dwa zestawy zależności do aktualizowania.
Uznano za akceptowalny — to dokładnie ten sam koszt, który 2.0 już ponosi.

**Odrzucono:** *Twig w monolicie* — szybsze na start, ale interfejs bazy
wiedzy urósłby do JS-a i tak, tylko bez struktury.

---

## D-009 — Edytor wiki: CodeMirror 6, nie WYSIWYG

**Data:** 2026-09-12 15:58 · **Stan:** Przyjęta

Dokumenty wiki edytujemy w **CodeMirror 6** jako Markdown, z podglądem obok.
Nie używamy edytora WYSIWYG.

**Dlaczego:** to samo pole treści zapisują ludzie i agenci AI. Agent produkuje
Markdown i tylko Markdown. Edytor WYSIWYG musiałby przy każdym otwarciu
przekonwertować Markdown na swój model dokumentu, a przy zapisie z powrotem —
i każdy taki obieg gubi to, czego model nie obsługuje: tabele o nietypowym
wyrównaniu, bloki kodu z nazwą języka, przypisy, komentarze HTML. Przy
dokumencie krążącym między człowiekiem i AI ta utrata kumuluje się cicho.

Markdown jako **jedyna** reprezentacja usuwa tę klasę błędów całkowicie: to,
co agent zapisał, jest dokładnie tym, co człowiek widzi i edytuje.

**Odrzucono:** *TipTap* — używany w nowszy projekt z frontendem Vue i dobry w swojej
roli (treści redakcyjne pisane wyłącznie przez ludzi), ale tutaj jego model
dokumentu stałby się drugą reprezentacją prawdy.

---

## D-010 — Hybryda: lokalny pałac plus publikacja do wspólnej bazy

**Data:** 2026-09-12 16:38 · **Stan:** Przyjęta
· **Domyślne zachowanie zmienione przez D-014**

Deweloper może mieć **własny lokalny MemPalace** (własny `init`, własne `mine`,
własne hooki) i jednocześnie publikować wybraną wiedzę do wspólnej bazy przez
API WS_Memory. Agent ma **dwa serwery MCP**: `mempalace` (lokalny, prywatny)
i `ws_memory` (wspólny). Publikacja działa w dwóch trybach: **selektywnym**
(`/ws-publish` z filtrem i podglądem) oraz **lustrzenia** — wskazane skrzydło
lokalnego pałaca jest cyklicznie publikowane do odpowiadającej przestrzeni.

**Co ustalono w kodzie MemPalace 3.7.0, zanim podjęto decyzję:**

- **Replikacji pałac↔pałac nie ma.** `logstream sync` synchronizuje zdarzenia
  koordynacyjne i artefakty (RFC 004) — w `logsync.py` nie ma ani jednego
  odwołania do szuflad. `mempalace sync` to sprzątanie po usuniętych plikach,
  nie replikacja. `replica.json` i `patch_submit` to fundament pod przyszły
  mesh i przekazywanie patchy kodu.
- **Mostek da się zbudować z tego, co jest**: `mempalace_list_drawers`
  (paginacja, filtr skrzydła/pokoju, zakres daty) plus `mempalace_get_drawer`
  (pełna treść) na lokalnym serwerze stdio.
- **`replica.json` daje stabilny identyfikator maszyny** — w kodzie opisany
  jako „nazwa siedziska, czyli tej kopii pałaca". Dokładnie to, czego potrzeba
  do rozpoznawania, skąd przyszła szuflada.

**Dlaczego tak, a nie „wszystko na serwerze":**

1. **Kod nie opuszcza laptopa.** Mielenie dzieje się lokalnie; na serwer idzie
   tylko tekst wybranych szuflad.
2. **Uprawnienia, atrybucja i audyt zostają nietknięte**, bo publikacja idzie
   przez API — nie wystawiamy Postgresa i nikt nie dostaje DSN-u. Reguły 1–3
   obowiązują bez wyjątku.
3. **Prywatność jest domyślna.** Rozmowy i notatki robocze zostają lokalnie,
   dopóki ktoś ich świadomie nie skieruje do wspólnej bazy.
4. **Znika wymóg jednego modelu embeddingów na laptopach** (uzupełnienie D-003).
5. **Użytkownik sam uruchamia `init` i `mine`** — u siebie, bez pośrednictwa
   aplikacji i bez czekania na administratora.

**Czego ta hybryda nie daje:** jednego zapytania obejmującego oba indeksy.
Lokalny pałac i wspólna baza to dwa magazyny, więc agent pyta dwa razy — skill
`ws-memory-recall` narzuca kolejność: najpierw wspólna, potem lokalna.
Scalanie po stronie serwera wymagałoby wysyłania tam wszystkiego, czyli
rezygnacji z prywatności, która jest tu główną zaletą.

> **Zmiana po D-014:** publikacja nie jest już czynnością, którą trzeba
> pamiętać — **domyślnie wszystko, co trafia do lokalnego pałaca, jest
> wysyłane na serwer**. Tryb ręczny został wyłącznikiem w ustawieniach
> wtyczki. Opisany niżej mechanizm lustra i partii pozostaje w mocy; zmienia
> się to, co dzieje się bez żadnej konfiguracji.

**Zabezpieczenia lustrzenia** — lustro raz ustawione działa bez nadzoru, więc
ryzyko wysłania czegoś nieprzewidzianego obsługujemy wprost:

- **Pierwszy przebieg każdego lustra jest podglądem**: pokazuje, co poleci, i
  wymaga potwierdzenia. Lustro nie zaczyna działać samo.
- **Wykluczenia pokoi** w definicji lustra (np. skrzydło projektu bez pokoju
  `diary`).
- **Filtr sekretów po obu stronach** — szuflada zawierająca wzorce sekretów
  (`.env`, klucze prywatne, hasła w URL-ach) jest odrzucana z raportem.
- **Dziennik partii publikacji** z możliwością wycofania całej partii jednym
  działaniem.
- **Wyłącznik globalny i per lustro**, plus pauza.
- **Przyrostowość** — lustro wysyła tylko szuflady nowsze niż ostatni znacznik.

**Odrzucono:** *lokalny mempalace z `MEMPALACE_PGVECTOR_DSN` wskazującym
centralną bazę*. Byłoby najprostsze w konfiguracji i dawało jeden indeks, ale
zapis wprost do bazy **omija całą warstwę uprawnień** — token agenta, role w
przestrzeniach i audyt przestałyby cokolwiek znaczyć. To unieważniłoby powód,
dla którego WS_Memory istnieje.

---

## D-011 — Wykrywanie encji po polsku, `init` bez LLM-a

**Data:** 2026-09-12 16:38 · **Stan:** Przyjęta

Ustawiamy `MEMPALACE_ENTITY_LANGUAGES=pl,en`. `mempalace init` po stronie
serwera uruchamiamy z `--no-llm`.

**Dlaczego:** wykrywanie encji domyślnie działa **po angielsku** (`--lang`,
domyślnie `en`). To ten sam rodzaj cichej wady co domyślny `minilm` z D-003,
tylko dotyczy grafu wiedzy i powiązań między szufladami — nazwy, role i relacje
w polskich tekstach byłyby rozpoznawane słabiej, bez żadnego komunikatu o błędzie.

`init` domyślnie chce LLM-a (ollama) do dopracowania encji. Nie mamy ollamy w
stosie, a dodanie jej to kolejny kontener i kilka GB RAM dla funkcji, która
tylko **dopracowuje** heurystyki. Startujemy bez niej; jeśli jakość wykrywania
okaże się za słaba, to osobna decyzja z własnym numerem.

---

## D-012 — Jedna droga wnoszenia wiedzy: mielenie wyłącznie lokalne

**Data:** 2026-09-12 17:08 · **Stan:** Przyjęta
· **Zmienia D-006, anuluje TODO-010**

**Serwer nie mieli niczego.** Wtyczka WS_Memory deklaruje wtyczkę MemPalace
jako **zależność**, więc każdy użytkownik ma lokalny pałac. Mielenie —
projektów, dokumentów, transkryptów rozmów — dzieje się wyłącznie na maszynie
użytkownika. Do wspólnej bazy trafia tylko to, co ktoś opublikuje (D-010).

**Co to potwierdza w mechanice Claude Code** (sprawdzone w dokumentacji, nie
założone):

- `plugin.json` ma pole **`dependencies`**: `["mempalace"]`, opcjonalnie z
  ograniczeniem wersji semver. Wpis w marketplace ma odpowiednik `requires`.
- Marketplace obsługuje **`source: {"type": "command"}`** — polecenie
  uruchamiane przed instalacją, czyli miejsce na instalację pakietu
  `mempalace` i pierwsze `mempalace init`.
- **`userConfig`** pozwala zapytać użytkownika o adres i token przy włączeniu
  wtyczki (`sensitive: true`), a wartości są dostępne jako
  `${user_config.KEY}` w konfiguracji MCP i `CLAUDE_PLUGIN_OPTION_*` w hookach.
  Zastępuje zmienne środowiskowe ustawiane ręcznie.

**Dlaczego jedna droga zamiast dwóch:**

1. **Dwie drogi to dwa razy więcej kodu i dwa razy więcej miejsc na błąd** —
   przy identycznym efekcie końcowym.
2. **Kod i rozmowy nie opuszczają laptopa.** Nie ma już żadnej ścieżki, którą
   surowe źródła trafiają na serwer — nie trzeba jej zabezpieczać, bo jej nie ma.
3. **Serwer przestaje potrzebować dostępu do repozytoriów.** Znikają klucze do
   gita, konfiguracja źródeł i harmonogram — razem z klasą błędów „nocne
   mielenie zawiesiło wyszukiwanie".
4. **Mielenie obciąża maszynę tego, kto je zlecił**, więc nie ma potrzeby
   limitów, kolejek ani ochrony przed zajechaniem wspólnego serwera.
5. Pytanie „kto może zlecać mielenie" **przestaje istnieć** — każdy u siebie.

**Co znika z projektu:** `TODO-010` w całości, tabele `mining_jobs` i
`session_uploads`, wolumen na transkrypty, wariant `extract` w obrazie serwera,
endpoint przyjmujący transkrypty oraz hook wysyłający je na serwer.

**Co zostaje na serwerze:** kontener `mempalace` (wyszukiwanie semantyczne i
zapis publikowanych szuflad) oraz `embeddings` (wektory dla zapytań i dla
publikowanych treści). Obie usługi są nadal niezbędne — nie mielą, tylko
obsługują wspólną bazę.

**Znane ograniczenie:** osoba bez Claude Code nie ma jak wnieść pliku PDF czy
DOCX do bazy — zostaje jej pisanie w wiki. Obejście: ktoś z lokalnym pałacem
mieli katalog dokumentów (`mempalace mine ~/dokumenty --mode extract`,
wymaga wariantu `mempalace[extract]`) i publikuje wynik. Uznano za akceptowalne
w pierwszej wersji; przywrócenie ścieżki serwerowej byłoby osobną decyzją.

---

## D-013 — Przenośność między klientami AI: wartość w serwerze, powłoki cienkie

**Data:** 2026-09-12 17:30 · **Stan:** Przyjęta

Budujemy **najpierw dla Claude Code** (biuro przechodzi na Claude), ale tak,
żeby port do Codeksa, Cursora czy innego klienta MCP był przepisaniem
manifestów, a nie przepisywaniem systemu. Trzy zasady:

1. **Cała wartość mieszka po stronie serwera.** Narzędzia `ws_*`, uprawnienia,
   tokeny, audyt, wiki — nic z tego nie jest w wtyczce.
2. **Treść instrukcji ma jedno źródło** (`plugin/shared/`) i jest dodatkowo
   wystawiona jako **zasoby MCP** oraz w opisach narzędzi.
3. **Części nieprzenośne trzymamy minimalne**: jeden skrypt hooka z argumentem
   zdarzenia zamiast osobnego skryptu na zdarzenie.

**Co jest już przenośne bez żadnej pracy:** gateway to serwer MCP po HTTP.
`codex mcp add --transport http`, `mcp.json` Cursora, Zed, Antigravity,
Copilot w VS Code — wszystkie się z nim połączą. Użytkownik innego klienta
dostaje **identyczne gwarancje bezpieczeństwa**, bo model uprawnień nie jest
we wtyczce.

**Co jest nieprzenośne:** hooki, skille, subagenci, komendy — czyli opakowanie.

**Co ustalono, patrząc jak zrobił to MemPalace** (cztery pakowania w jednym
repozytorium):

- `.claude-plugin/` — commands, hooks (`hooks.json` + skrypty), skills;
- `.codex-plugin/` — **ten sam kształt `hooks.json`** (SessionStart / Stop /
  PreCompact), tylko ze zmienną `${CODEX_PLUGIN_ROOT}`, i **jeden** skrypt
  przyjmujący nazwę zdarzenia jako argument;
- `.cursor-plugin/` — sam `mcp.json`, bez hooków (Cursor używa reguł);
- `.antigravity-plugin/` — `hooks.json.tmpl`, `mcp_config.json`, reguły, skille;
- `integrations/shared/` — wspólna treść protokołów, niezależna od klienta.

Czyli: różnice sprowadzają się do manifestów i nazw zmiennych, a treść jest
wspólna. Kopiujemy ten układ.

**Dlaczego zasoby MCP, a nie tylko skille:** zasoby czyta każdy klient MCP
(w tym harness ma do tego narzędzia `ListMcpResources` / `ReadMcpResource`),
a opisy narzędzi dostaje z definicji. Instrukcja przeniesiona z pliku wtyczki
do zasobu serwera zyskuje jeszcze jedno: **zmiana instrukcji to deploy serwera,
a nie aktualizacja wtyczki u każdej osoby z osobna.**

> Nie zweryfikowano, jak poszczególne klienty wystawiają **prompty** MCP
> użytkownikowi (dokumentacja Claude Code o tym milczy), więc nie opieramy na
> nich niczego. Zasoby i opisy narzędzi wystarczą.

**Czego nie robimy teraz:** nie piszemy pakowania dla Codeksa ani Cursora,
dopóki nikt ich nie używa. Zasady 1–3 sprawiają, że będzie to zadanie na
godziny, nie na tygodnie — i o to chodzi.

**Uwaga o zależności:** pole `dependencies` w `plugin.json` istnieje tylko w
Claude Code. Dla innych klientów ten sam efekt daje polecenie instalacyjne
plus rejestracja lokalnego serwera MemPalace (`codex mcp add mempalace`);
MemPalace ma gotowe pakowania dla Codeksa, Cursora i Antigravity.

---

## D-014 — Wysyłka na serwer jest domyślna, tryb ręczny jest wyłącznikiem

**Data:** 2026-09-12 17:52 · **Stan:** Przyjęta
· **Zmienia domyślne zachowanie z D-010**

**Wszystko, co trafia do lokalnego pałaca, trafia też na serwer** — bez
klikania, bez pamiętania, bez komendy. Mielenie nadal dzieje się lokalnie;
zmienia się to, że jego wynik jedzie dalej automatycznie. Kto chce inaczej,
przełącza `auto_publish` w ustawieniach wtyczki i wraca do `/ws-publish`.

**Reguła lądowania** — bez niej domyślne „wszystko na serwer" byłoby wyciekiem:

| Skrzydło lokalnego pałaca | Ląduje w |
|---|---|
| **zmapowane** na przestrzeń zespołową | tej przestrzeni — widoczne dla zespołu |
| **niezmapowane** | **prywatnej przestrzeni użytkownika na serwerze** |

Wszystko jest więc na serwerze zawsze (kopia zapasowa, wyszukiwanie, dostęp
z drugiej maszyny), ale **nic nie staje się widoczne dla zespołu bez
mapowania**. Potwierdzenie człowieka przenosi się z publikacji na **mapowanie**
— bo to ono decyduje o widoczności, i robi się je raz.

**Dlaczego domyślne „wysyłaj":** baza wiedzy, do której trzeba pamiętać, żeby
coś wnieść, wypełnia się tym, co ktoś akurat uznał za warte kliknięcia — czyli
prawie niczym. Wartość powstaje z kompletności. Ręczna publikacja została jako
wyłącznik dla tych, którzy świadomie chcą trzymać wszystko u siebie.

**Konsekwencja, którą trzeba nazwać wprost:** tekst zmielonego kodu **trafia na
serwer** (jako szuflady, nie jako repozytorium). Wcześniejsza właściwość „kod
nie opuszcza laptopa" zmienia się w **„kod nie opuszcza serwera firmy"**.
Mielenie pozostaje lokalne, więc serwer nadal nie potrzebuje dostępu do
repozytoriów ani kluczy do gita (D-012 bez zmian).

**Odsiew powtórzeń:** gdy trzy osoby zmielą to samo repozytorium, ta sama treść
poleci trzy razy. Dlatego:

- `memory_entries` trzyma **skrót treści**; publikacja do przestrzeni, która ma
  już szufladę o tym samym skrócie, jest pomijana;
- odsiew działa **w obrębie przestrzeni docelowej**, więc trzy prywatne
  przestrzenie nadal będą miały trzy kopie — i właśnie dlatego wtyczka
  **proponuje mapowanie**, gdy nazwa lokalnego skrzydła odpowiada istniejącej
  przestrzeni zespołowej. Jedno potwierdzenie i kopia jest jedna.

**Skutki operacyjne do uwzględnienia:**

- Serwer liczy embeddingi dla **całego** strumienia z wszystkich maszyn, nie
  dla wybranych fragmentów. To główny czynnik przy doborze mocy usługi
  `embeddings` — patrz `docs/05-deployment.md`.
- Filtr sekretów leży teraz na **każdej** ścieżce, nie tylko na tej, którą ktoś
  świadomie uruchomił. Jego testy stają się krytyczne.
- Użytkownik musi w każdej chwili widzieć, **co i gdzie** poleciało: dziennik
  partii z filtrem po przestrzeni, oraz wycofanie partii jednym działaniem.
