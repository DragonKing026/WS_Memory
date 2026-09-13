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

**Dlaczego:** zespół Web Systems utrzymuje kod w Symfony (w głównej aplikacji Symfony zespołu: Symfony 8,
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

> **Uzupełnienie po pomiarach z 2026-09-12 (TODO-000):** model wymaga
> **dostrojenia buforów i twardego limitu pamięci**. Przy domyślnych
> ustawieniach TEI (`--max-batch-tokens 16384`, tyle wątków tokenizacji ile
> rdzeni) `bge-m3` zajął **21 GB** i zdławił maszynę deweloperską. Po
> ustawieniu `--max-batch-tokens 2048`, `--tokenization-workers 2` i
> `--auto-truncate` zajmuje **2,2 GB** — te 19 GB było wyłącznie rezerwacją.
> Każda usługa ma teraz `mem_limit`: kontener bez limitu bierze całą pamięć
> maszyny, więc błąd konfiguracji zamienia się w awarię całego komputera.
>
> **Zmierzone alternatywy** (trzy polskie pary zdań, margines = różnica między
> parą trafną a kontrolną):
>
> | Model | Okno | Pamięć | Odpowiedź | Margines |
> |---|---|---|---|---|
> | `bge-m3` (wybrany) | 8192 | 2174 MB | 291 ms | 0,130 |
> | `paraphrase-multilingual-MiniLM` | **128** | 1113 MB | 21 ms | 0,281 |
> | `multilingual-e5-base` | 512 | 1961 MB | 72 ms | 0,055 |
> | `multilingual-e5-small` | 512 | 1204 MB | 24 ms | 0,034 |
>
> Rodzina **E5 wypadła najgorzej** i potwierdziła pierwotny argument tej
> decyzji: skoro MemPalace nie odróżnia zapytania od dokumentu, oba muszą
> dostać ten sam prefiks (`--default-prompt "query: "`), a wtedy podobieństwa
> ściskają się w paśmie wokół 0,8 — para kontrolna dostaje 0,804, czyli tyle
> samo co trafna.
>
> **MiniLM ma najlepszy margines, ale okno 128 tokenów** — każda szuflada
> zostałaby ucięta po ~90 słowach, cicho i bez błędu. To ta sama klasa wady,
> przed którą chroni test semantyki, więc został odrzucony.
>
> Odwrót, gdyby 291 ms okazało się wąskim gardłem: `multilingual-e5-base`.

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

**Odrzucono:** *TipTap* — używany w nowszym projekcie z frontendem Vue i dobry w swojej
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

---

## D-015 — Lokalny pałac jest pierwotny, serwer trzyma kopię

**Data:** 2026-09-12 18:10 · **Stan:** Przyjęta · **Doprecyzowuje D-014**

Praca dzieje się w **lokalnym pałacu**; serwer dostaje **kopię**. Kierunek jest
jednokierunkowy: lokalny → serwer. Nie ma ściągania w dół — wiedzę zespołu
agent czyta na żywo przez `ws_search`, a nie przez lustrzaną kopię u siebie.

**Wtyczka WS_Memory istnieje właśnie po to, żeby ta kopia powstawała.** Kto chce
pracować wyłącznie lokalnie, **instaluje samo MemPalace** i nie zakłada konta.
To jest właściwa droga rezygnacji — nie ustawienie, tylko wybór narzędzia.
Przełącznik `auto_publish` zostaje jako **hamulec awaryjny** (na przykład na
czas pracy nad czymś, czego świadomie nie chce się kopiować), a nie jako
główny sposób korzystania.

**Konsekwencja, która z tego wynika i jest wymaganiem, nie życzeniem:**

> **Zapis lokalny nigdy nie czeka na serwer i nigdy nie zawodzi z jego powodu.**

Brak sieci, padnięty serwer, praca w pociągu — mielenie i zapis do lokalnego
pałaca działają w pełni. Niewysłane szuflady czekają w **lokalnej kolejce
wyjściowej** ze znacznikiem czasu i dopinają się przy następnej okazji.
Ponowna wysyłka jest bezpieczna, bo odsiew po parze
`(source_replica, source_drawer_id)` i po `content_hash` już to obsługuje
(D-010, D-014).

**Co to daje poza wygodą:**

- **Awaria serwera nikogo nie blokuje.** Zespół pracuje dalej, kopie dopinają
  się po powrocie. Baza wiedzy przestaje być pojedynczym punktem awarii dla
  codziennej pracy.
- **Każdy ma pełną kopię swojej wiedzy u siebie**, niezależnie od losów
  serwera. Serwer jest miejscem spotkania, nie jedynym magazynem.
- **Aktualizacja serwera nie wymaga okna serwisowego** ogłaszanego zespołowi.

**Czego nie robimy:** synchronizacji w drugą stronę. Ściąganie wiedzy zespołu
do lokalnych pałaców oznaczałoby dwukierunkową synchronizację ze wszystkimi jej
konfliktami — odrzucone już w D-004 i to pozostaje aktualne.

---

## D-016 — Administrator globalny nie czyta cudzych przestrzeni po cichu

**Data:** 2026-09-12 21:05 · **Stan:** Przyjęta

`ROLE_ADMIN` pozwala zarządzać kontami, przestrzeniami i rolami. **Nie daje
dostępu do treści** przestrzeni, w której administrator nie jest członkiem —
łącznie z prywatnymi przestrzeniami użytkowników.

**Dlaczego:** administrator, który potrzebuje dostępu, może go sobie nadać.
Różnica jest w tym, że **nadanie roli zostaje w dzienniku audytu**, a ciche
czytanie nie zostawia śladu. Pierwsze jest czynnością, z której da się
rozliczyć; drugie jest niewidoczne dla właściciela treści.

To ma znaczenie praktyczne, nie tylko regulaminowe: do prywatnych przestrzeni
trafiają domyślnie transkrypty rozmów z agentami (D-014). Gdyby administrator
czytał je bez śladu, obietnica „twoje robocze rozmowy są twoje" byłaby pusta,
a ludzie zaczęliby wyłączać wysyłkę — czyli baza straciłaby to, po co powstaje.

**Konsekwencja w kodzie:** `SpaceAccessResolver` nie sprawdza `isGlobalAdmin`
przy liczeniu ról. Flaga służy wyłącznie warstwie administracyjnej. Pokryte
testem negatywnym `testGlobalAdminDoesNotSilentlyReadSpacesTheyAreNotMemberOf`.

**Odrzucono:** *administrator widzi wszystko* — wygodniejsze przy wsparciu
użytkowników („nie widzę swojego dokumentu, sprawdź"), ale kupione za cenę
zaufania do całego mechanizmu prywatnych przestrzeni. Wsparcie da się zrobić
inaczej: administrator nadaje sobie rolę na czas diagnozy, co widać w audycie.

---

## D-017 — Odświeżanie tokenów odłożone, nie pisane własnoręcznie

**Data:** 2026-09-12 21:45 · **Stan:** Przyjęta

Nie ma endpointu odświeżania tokena. Token wygasa i trzeba zalogować się
ponownie. Wrócimy do tego, gdy `gesdinet/jwt-refresh-token-bundle` obsłuży
Symfony 8 — dziś wymaga `symfony/console ^7`.

**Dlaczego nie napisać własnego:** rotacja tokenów odświeżających to kod
bezpieczeństwa z nieoczywistymi pułapkami — wykrywanie ponownego użycia
skradzionego tokena, unieważnianie całej rodziny tokenów po takim wykryciu,
wyścigi przy równoległych odświeżeniach z dwóch kart przeglądarki. Napisanie
tego samemu, żeby zaoszczędzić użytkownikom jednego logowania dziennie, to zła
wymiana. Utrzymywany bundle rozwiązał te przypadki i będzie je rozwiązywał
dalej; nasza implementacja zostałaby z nami na zawsze.

**Co robimy zamiast:** czas życia tokena dostępowego ustawiony na **8 godzin**,
czyli dzień pracy. Rano jedno logowanie i spokój.

**Dlaczego to nie osłabia bezpieczeństwa tak, jak mogłoby się wydawać:**
najgroźniejszy scenariusz przy długim tokenie to „zwolniona osoba nadal ma
dostęp". Ten scenariusz jest zamknięty osobno i mocniej — `ActiveAccountChecker`
sprawdza aktywność konta **przy każdym żądaniu**, nie tylko przy logowaniu,
a uprawnienia i tak są liczone z bazy za każdym razem (brak cache w
`SpaceAccessResolver`). Dezaktywacja konta i odebranie roli działają
natychmiast, niezależnie od tego, ile jeszcze token by żył.

---

## D-018 — CodeQL z AI findings plus PHPStan, bo szukają czego innego

**Data:** 2026-09-12 23:10 · **Stan:** Przyjęta

Skanowanie kodu stoi na trzech nogach:

1. **CodeQL w trybie domyślnym** — konfigurowany w ustawieniach repozytorium,
   nie plikiem workflow. Obejmuje Pythona (nasze skrypty) oraz workflowy
   Actions.
2. **AI findings** — funkcja zapoznawcza GitHuba, generująca zgłoszenia
   bezpieczeństwa dla języków, których CodeQL nie obsługuje. To ona pokrywa
   nasz backend w PHP.
3. **PHPStan na poziomie 8** — w szybkim przebiegu CI.

**Dlaczego trzy, a nie jedna:** CodeQL **nie obsługuje PHP** — wspiera C/C++,
C#, Go, Javę, JS/TS, Pythona, Ruby, Swift, Rust i Actions. Bez AI findings
backend Symfony byłby dla skanowania niewidoczny.

**Dlaczego PHPStan mimo AI findings:** szukają różnych rzeczy. CodeQL i AI
findings polują na **podatności** — wstrzyknięcia, wycieki, złe użycie
kryptografii. PHPStan łapie **błędy poprawności**: nieistniejącą metodę, zły
typ, warunek, który nigdy nie zachodzi. W kodzie uprawnień to drugie bywa
groźniejsze: reguła, która przez pomyłkę zawsze zwraca prawdę, nie jest
podatnością — jest cicho otwartymi drzwiami, których żaden skaner podatności
nie zgłosi.

Uruchomienie PHPStana na istniejącym kodzie potwierdziło to od razu: na
poziomie 8 znalazł **cztery realne usterki**, w tym opis przestrzeni przyjmujący
z JSON-a dowolny typ zamiast tekstu oraz identyfikator użytkownika mogący być
pustym łańcuchem — a to na nim opiera się cała warstwa bezpieczeństwa.

**Dlaczego tryb domyślny CodeQL, a nie własny `codeql.yml`:** AI findings
**wymaga trybu domyślnego**, a tryb domyślny i własny workflow wykluczają się
wzajemnie. Rezygnujemy więc z własnych zapytań CodeQL — przy tym projekcie
i tak byśmy ich nie pisali.

**Zastrzeżenie:** AI findings jest w wersji zapoznawczej i niedeterministyczny.
Potrafi zgłosić rzeczy, których nie ma, i przeoczyć te, które są. Traktujemy
jego wyniki jako podpowiedź do przejrzenia, nie jako bramkę blokującą scalenie.
Bramką jest PHPStan i testy — one dają ten sam wynik przy każdym uruchomieniu.

---

## D-019 — Dwie warstwy filtrowania: skrzydło przed pytaniem, rejestr po odpowiedzi

**Data:** 2026-09-12 21:10 · **Stan:** Przyjęta

Odczyt pamięci przechodzi przez **dwa** niezależne filtry. Pierwszy zawęża
pytanie: każde wywołanie `mempalace_search` niesie skrzydło jednej dozwolonej
przestrzeni. Drugi sprawdza odpowiedź: szuflada, której `ws.memory_entries`
nie umieszcza w dozwolonej przestrzeni, **nie wychodzi na zewnątrz** — również
wtedy, gdy przyszła ze skrzydła, o które sami zapytaliśmy.

**Dlaczego dwa, skoro pierwszy wystarcza:** pałac jest osobnym procesem z
własną historią. Można go zaktualizować, przywrócić z kopii starszej niż nasza
tabela, można w nim ręcznie coś poprawić. Jego odpowiedź nie jest dowodem
przynależności treści — jest tylko odpowiedzią. Pierwsza warstwa chroni przed
naszym błędem w zapytaniu, druga przed rozjazdem między dwoma magazynami.

Filtr drugi **nie zastępuje** pierwszego i nie wolno ich zamienić kolejnością.
Samo filtrowanie odpowiedzi to dokładnie to, czego zabrania reguła
nienaruszalna nr 3: treść zostałaby pobrana z niedozwolonej przestrzeni, a o
tym, czy wyjdzie dalej, decydowałby kod aplikacji.

**Co się dzieje z treścią nieznaną rejestrowi:** jest **pomijana**, nie
zgłaszana jako błąd. Szuflada bez wiersza w rejestrze to sygnał rozjazdu
(reguła integralności nr 5 w `docs/02-model-danych.md`) i raportuje go zadanie
cykliczne. Na ścieżce odczytu pominięcie jest bezpiecznym kierunkiem awarii:
nieznana treść jest niewidoczna, zamiast być widoczna bez sprawdzenia.

**Konsekwencja w kodzie:** `MemoryService::keepOnlyRegistered()`. Pokryte
testami `testDrawerThePalaceReturnsFromAnUnregisteredWingIsDropped`,
`testDrawerUnknownToTheRegistryIsDropped` oraz — na żywym pałacu —
`testDrawerFiledStraightIntoOurWingIsNotReturned`, który wstawia szufladę
wprost do naszego skrzydła, obchodząc rejestr.

**Odrzucono:** *zaufać skrzydłu i nie sprawdzać wyników* — o jedno zapytanie SQL
mniej na odczyt. Odrzucone, bo cena błędu jest niesymetryczna: oszczędzamy
milisekundy, a ryzykujemy pokazanie komuś treści z przestrzeni, do której nie
ma prawa. Tego rodzaju awaria nie zgłasza się sama.

---

## D-020 — Sierota w pałacu jest dopuszczalna, sierota w rejestrze nie

**Data:** 2026-09-12 21:15 · **Stan:** Przyjęta

Zapis do pamięci to dwa magazyny: pałac (HTTP, nie ma transakcji) i nasza baza
(transakcja jest). Rozproszonej transakcji między nimi nie ma i nie będzie,
więc trzeba **wybrać kierunek awarii**. Wybieramy ten: pałac może zostać z
szufladą, na którą nie wskazuje żaden wiersz; **odwrotnie nigdy**.

Kolejność jest więc taka: otwórz transakcję → zapisz do pałaca → zaksięguj
wiersz → zatwierdź. Błąd pałaca cofa transakcję, w której nic jeszcze nie
było. Błąd księgowania cofa wiersz i zostawia szufladę w pałacu.

**Dlaczego tak, a nie odwrotnie:** szuflada bez wiersza jest **niewidoczna** —
druga warstwa filtrowania (D-019) odrzuca wszystko, czego rejestr nie zna.
Wiersz bez szuflady byłby wynikiem wyszukiwania, którego nie da się otworzyć:
widać tytuł, klik daje błąd. Pierwsze jest stratą miejsca, drugie jest błędem,
który zgłasza użytkownik.

**Konsekwencje:** `MemoryRegistry::transactional()` wyznacza granicę, a limit
czasu na wywołanie pałaca (`MEMPALACE_TIMEOUT`, domyślnie 15 s) jest krótki
właśnie dlatego, że przez ten czas transakcja jest otwarta. Hojny limit nie
dawałby pewniejszego zapisu, tylko dłużej trzymany wiersz.

Zapisów **nie ponawiamy**. Powtórzony `mempalace_add_drawer` zakłada drugą
szufladę i nic później nie odróżni jej od treści zapisanej dwa razy celowo —
kontrola duplikatów w MemPalace porównuje treść, nie intencję. Przy błędzie
zapisu nie wiemy, czy dotarł, więc zgłaszamy awarię. Odczyty ponawiamy, bo
powtórzone szukanie kosztuje jedno zapytanie.

**Odrzucono:** *zapis do pałaca przed transakcją, rejestr po* — prostsze w
kodzie, bo transakcja nie obejmuje wywołania HTTP. Odrzucone, bo wtedy „w tej
samej transakcji" przestaje cokolwiek znaczyć, a błąd księgowania nadal
zostawia szufladę — zyskujemy krótszą transakcję i tracimy jedyną gwarancję,
jaką mamy.

**Odrzucono:** *rekompensata — przy błędzie księgowania usuń szufladę z pałaca* —
poprawne w teorii. Odrzucone na teraz, bo usuwanie też może zawieść i wtedy
trzeba kolejki rekompensat; a skoro sierota w pałacu jest niewidoczna,
rozwiązujemy problem, który nie boli. Zadanie cykliczne je raportuje.

---

## D-021 — Graf wiedzy zakresujemy kwalifikowaną nazwą encji, nie filtrem

**Data:** 2026-09-12 21:20 · **Stan:** Przyjęta

`mempalace_kg_query` przyjmuje **wyłącznie** encję — nie ma parametru skrzydła,
pokoju ani żadnej innej osi. Graf wiedzy w MemPalace 3.7.0 jest jeden i wspólny
dla całego pałaca. Reguła nienaruszalna nr 3 zabrania zaś pytać bez filtra
przestrzeni i filtrować wyniki po pobraniu.

Rozwiązanie: **zakres wchodzi do klucza**. Fakt zapisujemy pod nazwą
kwalifikowaną skrzydłem — `wing_alfa::WS_Memory` — i pod taką samą pytamy.
Zapytanie o cudzą przestrzeń nie zwraca faktów do odfiltrowania; ono ich **nie
dopasowuje**. Przedrostek zdejmujemy przed zwróceniem wyniku, więc dla agenta
encja nazywa się tak, jak ją napisał.

Kwalifikujemy **podmiot i dopełnienie**, bo `kg_query` dopasowuje encję w obu
pozycjach — kwalifikowanie samego podmiotu zostawiłoby fakty przychodzące
osiągalne z każdej przestrzeni. Orzeczenie zostaje nagie: to typ relacji, nie
encja, i nikt po nim nie pyta. Separatorem jest `::`, bo nazwy encji pochodzą
z prozy, a jeden dwukropek w nich występuje („Uwaga: termin").

**Skutek uboczny, nazwany wprost:** MemPalace nie połączy `wing_alfa::Symfony`
z `wing_beta::Symfony`. Przechodzenie grafu i wykrywanie encji działa w obrębie
przestrzeni, nie między nimi. **To jest zamierzone** — relacja przez granicę
przestrzeni byłaby wyciekiem, nie funkcją.

**Konsekwencja w kodzie:** `PalaceWing::qualify()` / `unqualify()` oraz
`KnowledgeFact::scopedTo()` / `unscopedFrom()`. Rejestr księguje fakt pod
odciskiem **niekwalifikowanym** plus przestrzenią — wing zakodowany dwa razy
uczyniłby wiersz nieosiągalnym z zapytania znającego samą nazwę encji.

**Odrzucono:** *pobrać fakty i odfiltrować po rejestrze* — działa i jest
prostsze. Odrzucone, bo to wprost reguła nr 3: fakt z cudzej przestrzeni
trafiałby do pamięci procesu, a o jego losie decydowałby `if`. Przy grafie jest
to groźniejsze niż przy szufladach, bo fakt jest krótki i mówi wprost („X
zarabia Y") — pomyłka nie wycieka akapitu, wycieka zdanie, które się pamięta.

**Odrzucono:** *osobny pałac na przestrzeń* — pełna izolacja grafu. Odrzucone:
kilkanaście pałaców to kilkanaście procesów i kilkanaście kopii modelu w
pamięci, a mamy jedną maszynę i jeden model (D-003). Dla przestrzeni naprawdę
wrażliwych zostaje osobny namespace pgvector (`docs/02-model-danych.md`).

**Do zweryfikowania przy aktualizacji MemPalace:** gdyby `kg_add` i `kg_query`
dostały parametr skrzydła, ta decyzja powinna zostać zastąpiona — filtr po
stronie pałaca jest czystszy niż kwalifikowanie nazw. Migracja wymagałaby
przepisania istniejących faktów.

---

## D-022 — Limit tempa w bazie, w tym samym wierszu co „ostatnio użyty"

**Data:** 2026-09-12 22:05 · **Stan:** Przyjęta

Ograniczenie tempa dla tokenów agentów liczymy w dwóch kolumnach tabeli
`ws.agent_tokens` (`calls_in_window`, `window_started_at`), aktualizowanych
**tym samym zapytaniem**, które zapisuje `last_used_at` i `last_used_ip`.
Okno stałe, minutowe.

**Dlaczego nie `symfony/rate-limiter`:** wymagałby nowej zależności (a to
decyzja — patrz AGENTS.md) i magazynu. Magazyn w cache plikowym jest lokalny
dla kontenera, więc przy dwóch kontenerach backendu limit przestaje
obowiązywać — a to jedyny scenariusz, w którym w ogóle jest potrzebny. Redis
oznaczałby kolejną usługę w stosie dla jednego licznika.

**Dlaczego to nic nie kosztuje:** wiersz i tak trzeba zapisać. „Kiedy ten token
był ostatnio użyty" to pole, bez którego nikt nie odważy się wycofać żadnego
tokena — więc zapis następuje przy każdym wywołaniu niezależnie od limitu.
Doliczenie licznika w tym samym `UPDATE ... RETURNING` jest darmowe, a wynik
wraca od razu.

**Dlaczego okno stałe, nie przesuwane:** przy minutowym oknie różnica dotyczy
skrajnego przypadku — agent może wykonać dwa razy limit na przełomie okien.
Przesuwane okno wymagałoby listy znaczników czasu zamiast licznika. Limit
istnieje, żeby pętla w agencie nie zajechała pałaca, a nie żeby rozliczać
kwoty; podwójna szybkość w jednej sekundzie tego celu nie psuje.

**Konsekwencja:** limit jest **per token**, nie per konto. Rozbiegana pętla
w jednym agencie nie zatrzymuje wszystkiego, co dana osoba ma uruchomione —
pokryte testem `testTheLimitIsPerTokenAndNotPerAccount`.

**Odrzucono:** *limit w nginxie* (`limit_req`) — działa na adresie IP, a wszyscy
agenci jednego zespołu mogą siedzieć za jednym adresem. Karałby wtedy niewinnych
i nie odróżniał tokenów. Zostaje jako druga linia obrony przed zalewem żądań,
nie jako limit dla tokena.

---

## D-023 — Błąd narzędzia MCP jest błędem JSON-RPC, nie treścią udanej odpowiedzi

**Data:** 2026-09-12 22:10 · **Stan:** Przyjęta

Gdy narzędzie zawiedzie, gateway odpowiada **błędem JSON-RPC** z kodem
(`-32003`, `-32010`, …). Nie odpowiada sukcesem, w którym błąd siedzi w treści.

**To świadome odstępstwo od specyfikacji MCP**, która zaleca `isError: true`
w wyniku. Powód jest empiryczny, nie estetyczny: MemPalace robi dokładnie to,
co zaleca specyfikacja, i **kosztowało nas to godziny** (TODO-000). Przy
zatrzymanym serwerze embeddingów odpowiedź była nie do odróżnienia od „nic nie
znalazłem": HTTP 200, koperta bez błędu, pusta lista wyników i przyczyna
schowana obok niej. Klient, który musi zajrzeć w treść, żeby dowiedzieć się,
czy wywołanie się udało, kiedyś tego nie zrobi.

Różnica między „nic nie ma" i „nie udało się sprawdzić" jest dla agenta
kluczowa: pierwsze prowadzi do zapisania wiedzy, drugie do ponowienia. Pomylenie
ich produkuje duplikaty obok treści, której agent nie zobaczył.

**Wyjątek, który potwierdza regułę:** brak uprawnień do odczytu **nie jest
błędem** — jest pustym wynikiem (reguła nienaruszalna 7). Bo tam koszt jest
odwrotny: komunikat „nie masz dostępu do przestrzeni Kadry" sam ujawnia, że taka
przestrzeń istnieje.

**Konsekwencja dla klientów:** klient MCP, który zakłada, że wynik zawsze jest
sukcesem, zobaczy błąd protokołu. To jest zamierzone — ma zobaczyć.

---

## D-024 — Wpis audytu zapisuje się od razu, nie czeka na cudzy `flush`

**Data:** 2026-09-12 22:15 · **Stan:** Przyjęta

`DoctrineAuditTrail` wykonuje `INSERT` przez DBAL w chwili wywołania. Wcześniej
robił `persist()` encji i zostawiał `flush` wołającemu.

**Dlaczego zmiana:** to działało, dopóki każdy wołający akurat flushował.
Wywołanie narzędzia MCP nie zmienia żadnej encji, więc **nic nie flushowało
i cała aktywność agentów przechodziła bez śladu** — bez żadnego błędu. Wykrył
to test w TODO-004, który poprosił o wpis i nie znalazł żadnego. Sprzężenie
„audyt zapisze się, jeśli ktoś inny później flushnie" jest dokładnie tym
rodzajem cichej zależności, której nie widać w przeglądzie kodu.

**Nazwany kompromis:** wpis wykonany wewnątrz transakcji, która się cofnie,
cofnie się razem z nią; a wpis zapisany chwilę przed niezwiązaną awarią może
zgłosić próbę, która się nie dokończyła. Kierunek jest wybrany świadomie:
dziennik tylko dopisywany, który czasem zapisze próbę, jest użyteczny;
dziennik, z którego wpisy po cichu znikają, jest **gorszy niż brak dziennika**,
bo czyta się jako dowód, że nic się nie stało.

Przy nieudanym wywołaniu MCP ślad zostaje niezależnie od transakcji: dekorator
`AuditedTool` zapisuje wpis z klasą wyjątku **po** wycofaniu transakcji.

**Odrzucono:** *`flush()` w `record()`* — `flush` w Doctrine jest globalny, więc
audyt w środku przypadku użycia zatwierdzałby też encje w połowie zbudowane.
To gorszy błąd niż ten, który naprawiamy.

**Odrzucono:** *druga połączenie tylko do audytu* — wpis przetrwałby wycofanie
transakcji, co jest poprawniejsze. Odrzucone na teraz: drugie połączenie to
własna konfiguracja, własny limit połączeń i własny tryb awarii, a zysk dotyczy
przypadku, w którym i tak mamy drugi wpis od dekoratora. Do rozważenia, gdy
audyt zacznie być używany do rozliczeń, nie do diagnozy.

---

## D-025 — Jedna szuflada na dokument, aktualizowana w miejscu; zlecenia nieaktualne porzucamy

**Data:** 2026-09-12 23:05 · **Stan:** Przyjęta

Publikacja dokumentu do pałaca **aktualizuje istniejącą szufladę**
(`mempalace_update_drawer`), a nie zakłada nowej. Zlecenie publikacji niesie
numer rewizji i jest **porzucane**, jeśli dokument ma już nowszą.

**Dlaczego nie nowa szuflada na rewizję:** pałac nie ma pojęcia wersji, więc
każda rewizja zostawiałaby wyszukiwalną kopię. Agent szukający „ile wynosi
czynsz" dostałby trzy odpowiedzi z trzech miesięcy i **nie miałby jak poznać,
która jest aktualna** — bo w wyniku wyszukiwania nie ma numeru rewizji, jest
tylko treść. Wiki byłaby wtedy gorsza niż jej brak: wyglądałaby na źródło
prawdy, podając nieprawdę.

**Dlaczego nie „dodaj nową, usuń starą":** dwie operacje sieciowe zamiast
jednej, a między nimi stan, w którym istnieją obie albo żadna. Aktualizacja
w miejscu jest jednym wywołaniem i zachowuje identyfikator, więc wiersz
w `memory_entries` pozostaje ważny.

**Sprawdzone empirycznie, nie założone:** `mempalace_update_drawer`
przelicza wektor. Test integracyjny zapisuje rewizję o innych słowach i sprawdza,
że **stara treść przestaje być znajdowalna** — gdyby aktualizacja zmieniała tylko
tekst bez wektora, wyszukiwanie nadal trafiałoby w poprzednią wersję.

**Strażnik kolejności.** Trzy szybkie zapisy wstawiają trzy zlecenia, a kolejka
nie obiecuje kolejności. Zlecenie, którego numer rewizji jest niższy niż bieżący,
jest porzucane z wpisem w dzienniku — bez tego spóźnione starsze zlecenie
nadpisałoby najnowszy tekst wersją wycofaną, a wiki i wyniki wyszukiwania
rozeszłyby się bez żadnego widocznego powodu. Test opróżnia kolejkę **od
najnowszego zlecenia**, bo tylko w tej kolejności strażnik jest sprawdzany.

**Gdy szuflada zniknęła** (przywrócona starsza kopia, ręczne usunięcie): adapter
zakłada nową i zwraca jej identyfikator, a serwis przestawia wiersz rejestru.
Alternatywa — awaria publikacji — zostawiłaby dokument niewidoczny dla
wyszukiwania z powodu, na który nikt nie ma wpływu.

**Odrzucono:** *trzymanie historii w pałacu i filtrowanie po numerze rewizji* —
wymagałoby, żeby wynik wyszukiwania niósł numer rewizji i żeby każdy wołający
o tym pamiętał. Źródłem prawdy dla wersji jest Postgres (D-004); pałac trzyma
kopię bieżącej treści i nic więcej.

---

## D-026 — Propozycję składa czytający, autorem przyjętej rewizji jest recenzent

**Data:** 2026-09-12 23:10 · **Stan:** Przyjęta

Złożenie propozycji (`ws_propose`) wymaga roli **czytającego**, nie piszącego.
Przyjęcie jest zapisem i wymaga roli piszącego; autorem powstałej rewizji jest
**recenzent**, a informacja, że treść napisał agent, zostaje w opisie zmiany.

**Dlaczego czytający wystarcza:** kolejka istnieje po to, żeby dało się coś
zaproponować tam, gdzie **nie wolno pisać wprost**. Wymaganie roli piszącego
udostępniłoby ją wyłącznie tym, którzy jej nie potrzebują — mogliby napisać
bezpośrednio. Ryzyko jest ograniczone: propozycja nie jest w wiki, nie jest
wyszukiwalna, a `ws_propose` zwraca `in_wiki: false`, żeby agent nie zameldował
publikacji, której nie było.

**Dlaczego recenzent jest autorem:** ktoś musi odpowiadać za to, co zostało
przyjęte. Zapisanie agenta jako autora rewizji oznaczałoby dokument, którego
nikt nie zatwierdził świadomie, choć przeszedł przez przegląd — czyli kolejkę
bez skutku. Jednocześnie ukrycie pochodzenia treści byłoby wprowadzaniem
w błąd, dlatego opis zmiany mówi wprost „treść od agenta AI".

**Dlaczego człowiek pisze w takiej przestrzeni wprost:** osoba pisząca
w przestrzeni z kolejką **jest** recenzentem. Wstawienie jej do własnej kolejki
oznaczałoby, że nie ma jej komu opróżnić.

**Nie ma narzędzia MCP do przyjmowania ani odrzucania.** Przegląd jest czynnością
człowieka w interfejsie (D-005); agent zatwierdzający własną propozycję czyniłby
kolejkę ozdobą.

**Odrzucono:** *automatyczne przyjmowanie po czasie* — kolejka, która sama się
opróżnia, nie jest przeglądem, tylko opóźnieniem.

---

## D-027 — Trasy wypisane jawnie, bez routingu plikowego

**Data:** 2026-09-12 23:20 · **Stan:** Przyjęta
· **Zmienia w tym zakresie** D-008 i `docs/07-frontend.md`

Trasy frontendu są wypisane w jednym pliku (`src/router/index.ts`), a nie
wyprowadzane ze struktury katalogów. `unplugin-vue-router` **nie wchodzi** do
zależności.

**Dlaczego:** `unplugin-vue-router` w wersji 0.19.2 — jedynej wydanej — wymaga
`vue-router ^4.6`, a stack projektu mówi **vue-router 5** i to jest wersja
bieżąca. Do wyboru były trzy rzeczy:

1. cofnąć router o major, żeby utrzymać konwencję budowania,
2. czekać, aż wtyczka nadrobi,
3. wypisać trasy.

Pierwsza opcja to dług migracyjny wzięty pierwszego dnia: nowa aplikacja
startowałaby na wersji, z której trzeba będzie wyjść, a wyjście będzie
wymagało jednoczesnej zmiany routera i wtyczki. Druga blokuje zadanie na
cudzym wydaniu.

**Co tracimy:** przy dodaniu strony trzeba dopisać wpis w tablicy tras. Przy
projekcie rzędu dwunastu ekranów (`docs/07-frontend.md`) to jedna linia na
ekran. **Co zyskujemy:** wszystkie adresy aplikacji widać w jednym czytelnym
pliku, razem z tym, które są publiczne i jak się nazywają — informacji, której
w drzewie katalogów nie ma, a która jest tu istotna, bo strażnik trasy opiera
się właśnie na `meta.public`.

**Do zweryfikowania:** gdy `unplugin-vue-router` zacznie wspierać vue-router 5,
tę decyzję można zastąpić. Migracja to przeniesienie plików do struktury
odpowiadającej adresom — bez zmian w logice.

**Odrzucono:** *własna wtyczka skanująca katalog `pages/`* — pisanie narzędzia
budowania, żeby nie pisać dwunastu linii konfiguracji, to zła wymiana.

---

## D-028 — Token JWT w `localStorage`, z nazwanym ryzykiem

**Data:** 2026-09-12 23:25 · **Stan:** Przyjęta

Token dostępowy frontendu mieszka w `localStorage`. Nie w ciasteczku `httpOnly`,
nie w `sessionStorage`, nie tylko w pamięci.

**Ryzyko wypowiedziane wprost:** przy udanym XSS napastnik odczyta token i ma
dostęp na cały jego czas życia (8 godzin, D-017). Ciasteczko `httpOnly` byłoby
na to odporne.

**Dlaczego mimo to:**

- **`httpOnly` wymaga zmiany backendu**, nie frontendu: serwer musiałby
  ustawiać ciasteczko i pilnować CSRF-a, a API jest świadomie bezstanowe
  (D-008) i obsługuje dwie powierzchnie, z których jedna — `/mcp` — ciasteczek
  nie używa w ogóle. To osobne zadanie, nie decyzja frontendu.
- **`sessionStorage` zawodzi przy otwarciu odsyłacza w nowej karcie** —
  użytkownik zostaje wylogowany w środku pracy, bez powodu, który da się
  wyjaśnić.
- **Tylko pamięć** oznacza wylogowanie przy każdym odświeżeniu strony, czyli
  przy `F5` w trakcie czytania dokumentu.

**Co to zmniejsza ryzyko:** token żyje 8 godzin, nie bezterminowo; unieważnienie
konta odcina dostęp przy następnym żądaniu (`ActiveAccountChecker`); a każdy
dostęp do magazynu jest owinięty w `try/catch`, więc prywatne okno i wyczyszczone
dane witryny nie wywracają aplikacji.

**Gdzie to jest zapisane poza tą decyzją:** `SECURITY.md`, w sekcji ryzyk
przyjętych świadomie. Ryzyko, o którym wie tylko autor kodu, nie jest przyjęte —
jest przeoczone.

**Odrzucono:** *token w pamięci plus „odświeżanie" przez ciasteczko* — to jest
poprawna architektura i wymaga endpointu odświeżania, którego nie ma (D-017).
Do rozważenia razem z nim.

---

## D-029 — Tryb leksykalny działa na naszych danych, nie w pałacu

**Data:** 2026-09-13 00:12 · **Stan:** Przyjęta

TODO-007 wymaga dwóch trybów wyszukiwania: semantycznego („czy ktoś coś o tym
wie") i leksykalnego („gdzie dokładnie występuje ta nazwa"). Okazało się, że
**pałac nie umie tego drugiego**: `mempalace_search` przyjmuje `query`, `wing`,
`room`, `since`, `before` i `max_distance` — i nic więcej. Nie ma parametru
trybu.

**Decyzja:** tryb leksykalny realizujemy po naszej stronie, w PostgreSQL, na
danych, które i tak trzymamy:

| Źródło | Co obejmuje | Zakres |
|---|---|---|
| `ws.document_revisions.content` | pełna treść bieżącej rewizji | cały tekst |
| `ws.memory_entries.title` + `tags` | notatki, dziennik, transkrypty | tytuł i tagi |

**Konsekwencja wypowiedziana wprost:** wyszukiwanie leksykalne **nie przeszukuje
treści szuflad innych niż dokumenty**. Treść notatki czy wpisu dziennika mieszka
wyłącznie w pałacu, a pałac oferuje do niej tylko dostęp semantyczny. Interfejs
ma to mówić, a nie udawać pełne pokrycie — wynik wyszukiwania, który po cichu
pomija połowę bazy, jest gorszy niż brak trybu.

**Odrzucono:** *pytanie tabel `palace.*` bezpośrednio* — łamie regułę
nienaruszalną (pałac jest zależnością, nie naszą bazą; `schema_filter` celowo go
wycina) i wiąże nas z jego schematem, który przy aktualizacji może się zmienić
bez ostrzeżenia. Cała wartość D-001 polega na tym, że aktualizacja pałacu dotyka
jednego pliku.

**Odrzucono:** *duplikowanie treści szuflad do `ws.memory_entries`* — podwaja
zajętość i tworzy problem synchronizacji dwóch kopii tej samej treści. Kopia,
która może się rozjechać z oryginałem, rozjedzie się.

**Do rozważenia później:** gdyby pałac dodał tryb leksykalny, ta decyzja
zostaje wyparta, a adapter jest jedynym miejscem do zmiany.

---

## D-030 — Leksykalnie szukamy `simple`, bez rdzeniowania

**Data:** 2026-09-13 00:12 · **Stan:** Przyjęta

PostgreSQL **nie ma polskiej konfiguracji wyszukiwania tekstowego** —
sprawdzone przez `\dF` na naszym obrazie: jest angielski, niemiecki, węgierski
i dwadzieścia innych, polskiego nie ma.

**Decyzja:** tryb leksykalny używa konfiguracji `simple` (tokenizacja bez
rdzeniowania) z **dopasowaniem przedrostkowym** (`to_tsquery('simple', 'palace:*')`),
na indeksach GIN. Bez żadnego rozszerzenia.

**Dlaczego to nie jest obejście, tylko właściwy wybór:** tryb leksykalny
odpowiada na pytanie „gdzie dokładnie występuje ta nazwa". Przy takim pytaniu
rdzeniowanie **szkodzi** — szukając `Version20260912000003` albo `PalaceWing`
nie chcemy trafień na coś o wspólnym rdzeniu. Odmiana polska jest problemem
wyszukiwania znaczeniowego, a to zadanie ma już swój tryb: semantyczny, który
działa na wektorach i odmiany nie zauważa.

**Odrzucono:** *konfiguracja `english` na polskim tekście* — rdzeniuje według
reguł innego języka, więc dokłada trafienia błędne, a poprawnych nie dokłada.
Gorsze niż brak rdzeniowania, bo wygląda na działające.

**Odrzucono:** *słownik `ispell` z polskim `hunspell`* — wymaga plików słownika
w obrazie bazy, czyli własnego obrazu Postgresa zamiast `pgvector/pgvector`, i
utrzymywania go przy każdej aktualizacji. Koszt nieproporcjonalny do zysku,
skoro odmianę obsługuje tryb semantyczny.

**Odrzucono:** *`pg_trgm` dla dopasowań w środku słowa* — założenie rozszerzenia
wymaga uprawnienia `CREATE` na bazie, którego rola `ws_app` **celowo nie ma**
(zakłada je `postgres` w skrypcie inicjującym). Migracja uruchamiana jako `ws_app`
nie mogłaby go dodać, a rozwiązaniem byłoby albo poszerzenie uprawnień roli
aplikacji, albo ręczny krok administratora przy każdej istniejącej bazie. Jedno
i drugie to zła cena za szukanie fragmentu w środku słowa, skoro przedrostek
pokrywa realne użycie („wpisuję `Palace`, chcę `PalaceWing`"). Do dodania, gdy
ktoś tego naprawdę potrzebuje — wtedy świadomie, z krokiem administracyjnym.

---

## D-031 — Gałąź na zadanie; sprawdzenia zawężone ścieżkami; pełny przebieg na main

**Data:** 2026-09-13 12:55 · **Stan:** Przyjęta

Zmiany wchodzą przez **gałąź na zadanie** (`todo-NNN-krótka-nazwa`) i pull
request, a nie prosto na `main`. Szybkie sprawdzenie jest **zawężone
ścieżkami**, a wymaganym sprawdzeniem jest **jedno zadanie-bramka**
(„Wynik sprawdzenia"). Pełne sprawdzenie — cały stos, model embeddingów,
testy E2E — rusza **po scaleniu na `main`**, a nie na każdym pull requeście.

**Co było dotąd:** wszystko szło prosto na `main`. Ruleset „Ochrona gałęzi
głównej" wymagał pull requesta i zielonych sprawdzeń, ale skrypt wypychający
omijał go rolą administratora. W jego wyjściu widniało to wprost:
`Bypassed rule violations for refs/heads/main: Changes must be made through
a pull request.` Reguła istniała i była łamana przy każdym commicie. Reguła
obchodzona przy każdym użyciu nie jest zabezpieczeniem — jest wpisem
w ustawieniach, który wygląda jak zabezpieczenie.

**Odrzucono:** *stałe gałęzie warstwowe* (`frontend`, `backend`, `docs`) —
kuszące, bo sprawdzenia dałoby się przypiąć do gałęzi raz na zawsze. Trzy
powody przeciw, wszystkie z tego repozytorium:

1. **Zmiany nie dzielą się po warstwach, bo własne reguły projektu to
   wymuszają.** Każda zmiana musi mieć wpis w `CHANGELOG.md`, a dokumentacja
   idzie w tym samym commicie w dwóch językach. Poprawka cache'u modelu
   z 13 września dotknęła `docker-compose.yml`, `.env.example`, workflowu,
   `docs/09-ci.md`, `docs/en/09-ci.md` i `CHANGELOG.md`. TODO-007 dotknęło
   backendu, frontendu i dokumentacji naraz. Gałąź warstwowa wymagałaby
   rozcięcia takiej zmiany na trzy, z których żadna nie jest sama w sobie
   kompletna.
2. **`CHANGELOG.md` dopisuje się NA GÓRZE pliku.** To najgorszy możliwy plik
   dla równolegle żyjących gałęzi: konflikt jest przy każdym scaleniu, zawsze
   i w tym samym miejscu.
3. **Stałe gałęzie opóźniają integrację.** Błąd z kodowaniem ukośnika
   w adresie dokumentu (`procedury%2Fpierwsza`) znalazł test E2E dokładnie
   w momencie, gdy frontend spotkał backend. Im dłużej gałąź warstwowa żyje,
   tym później przychodzi ten moment — a wtedy jest już droższy.

**Dlaczego gałąź na zadanie:** struktura `TODO-NNN` już istnieje, więc
mapowanie jest naturalne i nie wprowadza nowej konwencji do zapamiętania —
`todo-015-aktualizacja-mempalace`. Gałąź żyje godziny, nie tygodnie, więc
konflikt na `CHANGELOG.md` jest drobnym rebasem, a nie stanem trwałym.

**Dlaczego jedno zadanie-bramka, a nie lista wymaganych zadań** — to pułapka,
która kosztowałaby pół dnia szukania, więc zapisujemy ją wprost. Zmiana
wyłącznie we frontendzie nie ma uruchamiać PHPUnita ani PHPStana, czyli
zadania backendu mają być pomijane. Ale **pominięte zadanie nie zgłasza się
jako zielone** — dla reguły ochrony jest wiecznie oczekujące, więc wymaganie
go wprost zablokowałoby każdy pull request, którego ono nie dotyczy. Dlatego
ruleset wymaga jednego zadania („Wynik sprawdzenia"), które wykonuje się
zawsze, zbiera wyniki pozostałych i traktuje **pominięcie jako w porządku,
a porażkę jako błąd**.

**Koszt, który trzeba nazwać wprost:** usterka integracyjna trafia na `main`
i dowiadujemy się o niej **kilka minut po scaleniu, a nie przed nim**. To
świadomy kompromis — pełny stos odpowiada na pytanie „czy to wszystko razem
wstaje", a to pytanie ma sens dla stanu, który faktycznie obowiązuje, nie dla
każdej gałęzi zadania z osobna. Kto chce odpowiedzi wcześniej, uruchamia
przebieg ręcznie: `gh workflow run pelne.yml --ref <gałąź>`.

**Dlaczego harmonogram dobowy zostaje** mimo pełnego przebiegu po każdym
scaleniu: łapie to, czego push nie złapie — zależność zewnętrzną, która psuje
się bez naszego commita. Obraz znika, model przestaje być dostępny, PyPI się
zmienia. O takiej awarii lepiej wiedzieć rano niż przy najbliższej zmianie.

---

## D-032 — Aktualizacja MemPalace przez agenta na hoście, nie przez gniazdo Dockera

**Data:** 2026-09-13 14:05 · **Stan:** Przyjęta

Panel administratora pozwala **zlecić** aktualizację MemPalace. Samo zlecenie
trafia do tabeli w bazie; wykonuje je skrypt uruchamiany cyklicznie **na hoście**
(timer systemd). Żaden kontener nie dostaje dostępu do Dockera.

### Skąd w ogóle potrzeba

MemPalace jest przypięty na sztywno (`MEMPALACE_VERSION`, `pip install
mempalace==...`) i słusznie — aktualizacja pałaca dotyka wektorów, więc nie ma
być przypadkiem. Skutek uboczny jest jednak taki, że **nikt nie wie, kiedy wyszło
coś nowego**. Przy pisaniu tego zadania okazało się, że działa 3.7.0, a na PyPI
jest 3.9.0 — dwie wersje mniejsze w tyle, i dowiedzieliśmy się o tym tylko
dlatego, że ktoś ręcznie zapytał. To jest dług, który rośnie po cichu, aż
aktualizacja przestaje być krokiem i staje się projektem.

### Odrzucone: gniazdo Dockera w kontenerze backendu

Najprostsze do zrobienia i najgorsze z możliwych. Kontener z `/var/run/docker.sock`
może uruchomić dowolny obraz z dowolnym montowaniem, czyli ma władzę **równoważną
rootowi na hoście**. Backend obsługuje ruch z sieci i ma konta użytkowników, więc
dowolne zdalne wykonanie kodu w Symfony albo przejęcie konta administratora
kończyłoby się przejęciem maszyny. Wygoda nie jest tego warta.

### Odrzucone: osobna usługa-aktualizator z gniazdem Dockera

Powierzchnia mniejsza — usługa bez portu na hoście, osiągalna tylko z sieci
compose, o wąskim API. Ale backend nadal może ją wywołać, więc włamanie do
backendu nadal daje Dockera. Przesuwa granicę o jeden krok, nie stawia jej.

### Wybrane: agent na hoście, komunikacja przez bazę

Backend zapisuje **zlecenie**, agent je podejmuje. Kompromitacja aplikacji
webowej pozwala co najwyżej zlecić aktualizację do wersji, która istnieje na
PyPI — nie uruchomić dowolnego kodu na hoście.

Agent rozmawia z aplikacją przez `docker compose exec backend php bin/console`,
a nie przez HTTP. Dzięki temu nie trzeba wymyślać uwierzytelniania dla agenta ani
wystawiać endpointu, który musiałby być chroniony inaczej niż sesją użytkownika.

**Wersja docelowa jest walidowana wzorcem po obu stronach** — przy zapisie
zlecenia i w agencie. Nie z nieufności do backendu, tylko dlatego, że jedna
warstwa walidacji to zero warstw, gdy akurat ta jedna ma błąd. Jest to jedyne
miejsce, w którym dane z aplikacji wpływają na polecenie wykonywane na hoście.

### Co agent robi obowiązkowo

Kopia zapasowa schematu `palace` **przed** przebudową i `test/semantyka.sh`
**po** niej, z wycofaniem przy porażce. Powód jest w D-003: zepsuta trafność
wyszukiwania jest **cicha** — wyszukiwarka nadal odpowiada, tylko przestaje
trafiać. Aktualizacja bez tego testu byłaby aktualizacją, po której nie wiadomo,
czy coś się zepsuło.

### Koszt, który świadomie bierzemy

Instalacja przestaje być samym `docker compose up`: trzeba jeszcze wgrać jednostkę
systemd. Dopóki tego nie zrobiono, panel **mówi wprost, że aktualizator jest
niedostępny** i nie pokazuje przycisku, który nic nie robi. Przycisk bez skutku
jest gorszy niż jego brak, bo uczy nie ufać interfejsowi.

Drugi koszt: kliknięcie nie daje natychmiastowego wyniku, tylko zlecenie
podejmowane w ciągu minuty. Panel pokazuje stan i dziennik, więc oczekiwanie jest
widoczne, a nie zagadkowe.

---

## D-033 — Wtyczka mieszka w tym repozytorium, marketplace wskazuje podkatalog

**Data:** 2026-09-13 17:12 · **Stan:** Przyjęta

`plugin/` jest podkatalogiem WS_Memory, a `.claude-plugin/marketplace.json`
w korzeniu repozytorium wskazuje go wpisem `"source": "./plugin"`. **Bez
submodułu i bez drugiego repozytorium.**

**Co sprawdzono, zanim to rozstrzygnięto** (w zainstalowanym katalogu wtyczek,
nie w dokumentacji): większość wpisów w oficjalnym katalogu Anthropica to
podkatalogi jednego repozytorium (`"./plugins/agent-sdk-dev"`). Obsługiwane
formy źródła to poza tym `git-subdir` (podkatalog **cudzego** repozytorium),
`url`, `github`, `npm`, `archive` i `command`. Do tego `claude plugin
marketplace add` ma flagę `--sparse <ścieżki>`, opisaną wprost jako „for
monorepos" — ogranicza pobieranie do wskazanych katalogów.

Czyli obie obawy, które motywowały osobne repozytorium, są bezprzedmiotowe:
wtyczka **da się** trzymać w podkatalogu, a instalujący **nie musi** pobierać
całej aplikacji.

**Dlaczego nie submoduł:**

1. **Reguły tego repozytorium robią z niego podwójną pracę.** Każda zmiana ma
   wpis w `CHANGELOG.md` i dokumentację w dwóch językach w tym samym commicie.
   Zmiana we wtyczce byłaby więc zawsze commitem we wtyczce **plus** commitem
   w głównym repo (changelog, `docs/04-plugin.md`, `docs/en/04-plugin.md`,
   podbicie wskaźnika). Dwa pull requesty na jedną myśl — dokładnie ten koszt,
   przez który odrzuciliśmy stałe gałęzie warstwowe w D-031.
2. **Backend czyta `plugin/shared/` przy budowaniu obrazu**, bo wystawia tę
   treść jako zasoby MCP (D-013). Z submodułem CI musiałby pobierać go
   rekurencyjnie, a **nieprzestawiony wskaźnik oznaczałby serwer serwujący
   nieaktualne instrukcje** — awaria cicha, widoczna dopiero po tym, jak agent
   zachowa się według starego protokołu.
3. Dowiązania symboliczne z `plugin/skills/` i `plugin/agents/` do
   `plugin/shared/` (D-013: treść istnieje raz) muszą wskazywać **wewnątrz
   pobranego drzewa**. Z `plugin/` jako całością to działa również przy
   pobieraniu rzadkim.

**Co odrzucono:**

- **Osobne repozytorium od początku** — wtyczka jest dziś cienką powłoką nad
  gatewayem i zmienia się razem z nim. Osobne repozytorium ma sens, gdy zaczną
  żyć w różnym tempie; dziś dokładałoby synchronizacji bez żadnej korzyści.
- **Submoduł** — powody wyżej.

**Wyjście, gdyby wtyczka miała kiedyś pójść na zewnątrz:** `git subtree split
--prefix=plugin` zachowuje historię katalogu, a wpis marketplace zamienia
`"./plugin"` na `git-subdir` albo osobne repozytorium. To zmiana manifestu,
nie przeprowadzka — i właśnie dlatego można tę decyzję odłożyć.

---

## D-034 — Mechanikę wtyczki sprawdza się narzędziem, nie lekturą

**Data:** 2026-09-13 17:12 · **Stan:** Przyjęta · **Koryguje D-012**

D-012 wymieniła **trzy** mechanizmy Claude Code jako „sprawdzone w dokumentacji",
a `TODO-009` dołożyło do nich **czwarte** założenie: hook `pre-compact`. Przy
implementacji sprawdziliśmy wszystkie cztery narzędziem — `claude plugin
validate`, zainstalowany katalog wtyczek, dokumentacja zdarzeń.

**Dwa się potwierdziły. Dwa nie.**

| Założenie | Skąd | Jak jest naprawdę |
|---|---|---|
| `dependencies: ["mempalace"]` | D-012 | **Pole potwierdzone**, dopuszcza też `{"name": …, "version": "~2.1.0"}`. Sama nazwa okazała się jednak niewystarczająca — patrz punkt 2 niżej |
| `userConfig` z `sensitive: true`, dostępne jako `${user_config.KEY}` w MCP i `CLAUDE_PLUGIN_OPTION_*` w hookach | D-012 | **Potwierdzone**, łącznie z podstawianiem wewnątrz obiektu `headers` |
| `source: {"type": "command"}` — polecenie **przed instalacją**, miejsce na `pip install mempalace[extract]` i `mempalace init` | D-012 | **Błędne dwukrotnie.** Klucz nazywa się `source`, nie `type`, a samo źródło **nie jest hakiem instalacyjnym**: to polecenie, które **wypisuje ścieżkę do katalogu wtyczki**. Nie ma tam miejsca na instalowanie cudzego pakietu |
| hook `pre-compact` zapisujący podsumowanie | TODO-009 | **Zdarzenie `PreCompact` nie występuje w udokumentowanej liście zdarzeń** (są m.in. `SessionStart`, `SessionEnd`, `UserPromptSubmit`, `Stop`). Hooki `PreCompact` faktycznie się uruchamiają — robi to wtyczka MemPalace — ale nie ma udokumentowanego sposobu, żeby taki hook **dołożył cokolwiek do kontekstu** |

**Co z tego wynika dla wtyczki:**

1. **Instalacja pakietu `mempalace` zostaje po stronie człowieka**, opisana
   w skillu `ws-memory-setup` i w `docs/04-plugin.md`. Wtyczka MemPalace sama
   też tego nie robi — jej wpis w marketplace nie ma żadnego polecenia. Kroku
   instalacyjnego, którego nie da się wyrazić, nie udajemy.
2. **Nie ma hooka `pre-compact`.** I nawet gdyby zdarzenie było udokumentowane,
   nasz hook mógłby wysłać na serwer wyłącznie **surową rozmowę** — skrypt
   powłoki nie streszcza. To jest wprost zakazane (D-012: żaden bajt surowej
   rozmowy nie idzie na serwer). Streszczenie ma napisać model, i mówi mu to
   protokół recall. Mieleniem transkryptu **do lokalnego pałaca** zajmuje się
   hook MemPalace, który przychodzi z zależnością.
3. **Nie ma hooka `session-end`.** Publikacja lustra to `TODO-012` i jeszcze nie
   istnieje. Hook, który nic nie robi, jest gorszy niż jego brak: wygląda jak
   działająca funkcja.
4. **Nie ma przełącznika `auto_publish` w `userConfig`.** Sterowałby czymś,
   czego nie ma. Wejdzie razem z publikacją.

**Dlaczego to jest osobna decyzja, a nie poprawka w D-012:** starych decyzji
nie edytujemy. Ale przede wszystkim ta rozbieżność jest sama w sobie wnioskiem.

D-012 opisała mechanizmy jako „sprawdzone w dokumentacji, nie założone" —
i **potwierdzenie polegało na przeczytaniu ich opisu, nie na uruchomieniu
czegokolwiek**. Połowa nie przetrwała pierwszego kontaktu z walidatorem, przy
czym akurat ten punkt, który miał zdjąć pracę z użytkownika (automatyczna
instalacja pakietu), okazał się mechanizmem o zupełnie innym przeznaczeniu.

Reguła na przyszłość: **mechanizm zewnętrznego narzędzia wpisujemy do decyzji
dopiero po tym, jak go uruchomiliśmy.** „Sprawdzone w dokumentacji" znaczy
teraz „przeczytane" i tak ma być zapisywane.

**Reguła potwierdziła się w tej samej godzinie, w której powstała.** Wtyczka
przechodziła `claude plugin validate` bez zastrzeżeń. Prawdziwa instalacja
(`claude plugin install`) wyłapała trzy rzeczy, których walidator nie widzi:

1. `"hooks": "./hooks/hooks.json"` w manifeście to **błąd ładowania** —
   `hooks/hooks.json` ładuje się sam, a pole służy do plików dodatkowych;
2. `dependencies: ["mempalace"]` szuka zależności we **własnym** marketplace;
   trzeba `["mempalace@mempalace"]`;
3. dowiązania symboliczne do **plików** w `agents/` **nie ładują się w ogóle** —
   bez błędu, bez ostrzeżenia, po prostu `Agents (0)`. Działa dowiązanie do
   **katalogu**. W `skills/` dowiązania do plików działają normalnie.

Trzecia jest najgorszego rodzaju: nic nie pada, po prostu połowa wtyczki nie
istnieje. Dlatego sprawdzeniem końcowym jest **policzenie składników**
w `claude plugin details`, a nie zielony walidator.

---

## D-035 — Jedna wspólna przestrzeń, do której każde konto należy od początku

**Data:** 2026-09-13 18:05 · **Stan:** Przyjęta

Nowe konto trafia od razu do **jednej przestrzeni zespołowej** (domyślnie
`wiedza` / „Baza wiedzy"), z rolą `writer`. Przestrzeń powstaje sama przy
pierwszym koncie. Pusty `WS_DEFAULT_SPACE_SLUG` wyłącza mechanizm.

**Dlaczego:** wcześniej konto powstawało z samą przestrzenią prywatną, więc
pierwszą rzeczą, jaką widziała nowa osoba, było zdanie **„nie należysz jeszcze
do żadnej przestrzeni zespołowej"** — przy bazie wiedzy, która stała obok
i była dla niej niewidoczna. Ktoś z rolą administratora musiał dopisać ją
ręcznie. Jedna osoba to drobiazg; jako reguła to zaproszenie do sytuacji, w
której baza wiedzy jest domyślnie niedostępna dla ludzi, którzy właśnie zostali
do niej zaproszeni.

Wyszło to na jaw dokładnie tak: świeżo utworzone konto administratora globalnego
zalogowało się i nie widziało istniejącej przestrzeni `wiedza`, mimo że miało
najwyższe uprawnienia w systemie.

**Czy to nie kłóci się z D-016?** Nie, i warto powiedzieć czemu. D-016 zabrania
administratorowi **cichego** sięgania do przestrzeni — chodzi o wyjątek zrobiony
bez śladu. Tutaj jest odwrotnie: to jawna reguła stosowana do wszystkich tak
samo, zapisywana w dzienniku audytu jak każde inne nadanie dostępu. Różnica jest
między niewidocznym wyjątkiem a widoczną regułą.

**Wpis audytu nie ma aktora.** Nikt tego nie nadał. Wpisanie nowego konta jako
aktora czytałoby się jak „sam się wpuścił", a wpisanie zapraszającego jest
niemożliwe: zaproszenie z konsoli **nie ma zapraszającego** (pole `invited_by`
jest puste, co osobno wywróciło ekran zaproszeń tego samego dnia). W `target`
stoi więc `reason: default_space` — regułą wpuściła.

**Dlaczego `writer`, a nie `reader`:** sens jest taki, żeby ktoś mógł dorzucić
coś do bazy w dniu, w którym przyszedł. Zawężenie jednej osoby administrator
zrobi później; wymaganie administratora, **zanim ktokolwiek cokolwiek napisze**,
jest dokładnie tym, co ta decyzja usuwa. Niepoprawna wartość w konfiguracji
degraduje się do `reader`, nie do `writer` — pomyłka ma zabierać dostęp, nie
rozdawać go.

**Dlaczego przestrzeń powstaje przy pierwszym koncie, a nie migracją:** migracja
musiałaby zaszyć slug na sztywno, a ten jest konfigurowalny. Instancja, która
nigdy nie przyjmie zaproszenia, nie potrzebuje tej przestrzeni.

**Co odrzucono:**

- **Automatyczne dołączanie do *wszystkich* przestrzeni zespołowych** — to nie
  jest domyślność, tylko zniesienie uprawnień. Przestrzeń istnieje po to, żeby
  dało się coś trzymać osobno.
- **Rola `admin` w domyślnej przestrzeni** — każdy mógłby wtedy rozdawać dostęp
  do wspólnej bazy, więc reguła „dostęp nadaje administrator" przestałaby
  cokolwiek znaczyć.
- **Zakładanie przestrzeni migracją danych** — powód wyżej.

**Koszt, który trzeba nazwać:** własność „świeże konto ma dokładnie swoją
przestrzeń prywatną" przestała obowiązywać, a była wprost zapisana w testach.
Zostały przepisane tak, żeby mówiły prawdę o nowym stanie, a nie żeby przestały
cokolwiek znaczyć.

---

## D-037 — Testy integracyjne kasują z pałaca to, co zapisały, po rejestrze

**Data:** 2026-09-13 18:56 · **Stan:** Przyjęta

Trzy klasy z grupy `integracja` piszą do **prawdziwego** pałaca i nie kasowały
po sobie niczego. Stan zmierzony 2026-09-13: pałac serwera trzymał **ponad 1500
szuflad**, z czego **872 skrzydła `test-integracja-*`** i **128 osieroconych
skrzydeł `priv_<uuid>`** po użytkownikach testowych. Prawdziwej treści: zero.
Narosło w kilka dni, a każdy przebieg dokładał kilkanaście szuflad.

**Rozstrzygnięcie.** Sprzątanie stoi raz, we wspólnej cesze
`RequiresLivePalace`, jako metoda wołana z `tearDown()` każdej z trzech klas.
Listę szuflad bierze z **rejestru `ws.memory_entries`**, pomijając wiersze
`kg_fact`, i kasuje je **przez API pałaca** (`mempalace_delete_drawer`).
Porażka sprzątania nie wywraca testu, ale idzie na stderr z nazwą skrzydła.

**Dlaczego po rejestrze, a nie po skrzydle przebiegu.** Każdy przebieg tworzy
własne skrzydło `test-…-<hex>`, więc „usuń wszystko z mojego skrzydła" wygląda na
rozwiązanie prostsze. Nie jest pełne: **zapis bez wskazanej przestrzeni ląduje
w prywatnej przestrzeni autora** (reguła nienaruszalna 6), a testy takie zapisy
robią celowo — właśnie tego dowodzą. Stąd te 128 skrzydeł `priv_<uuid>`.
`ws.memory_entries` wymienia szuflady niezależnie od tego, gdzie wylądowały,
a w `tearDown` jest jeszcze nietknięty, bo czyści go `setUp` **następnego** testu.

Wiersze `kg_fact` są pomijane, bo ich `drawer_id` jest wyliczany z faktu
(`DrawerId::forFact`) i nie nazywa żadnej szuflady w pałacu — fakty żyją w grafie.
Szufladę wstawioną **wprost przez klienta pałaca**, bez wiersza w rejestrze,
test księguje jawnie (`alsoDeleteDrawer`); bez tego jedna szuflada na przebieg
zostawałaby na zawsze.

**Odrzucone alternatywy:**

1. **`TRUNCATE` w schemacie `palace`** — najszybsze i wprost zabronione przez
   D-004. Schemat pałaca należy do MemPalace; jego kształt to szczegół
   implementacyjny zależności, a nie nasz kontrakt. Reguła nie ma wyjątku dla
   testów: test, który obchodzi jedyną dopuszczalną drogę zapisu, przestaje
   sprawdzać tę drogę.
2. **Osobny pałac dla testów** (druga usługa, własny wolumen) — rozwiązuje
   problem, ale podwaja pamięć i czas startu stosu, a przede wszystkim
   **przestaje sprawdzać to, co jest sprawdzane**: wartość tych testów polega na
   rozmowie z tą samą instancją i tą samą wersją MemPalace, którą ma produkcja.
   Do rozważenia, jeśli kiedyś zaczną chodzić równolegle.
3. **Kasowanie po nazwie skrzydła** (`test-…-<hex>`) — patrz wyżej: pomija
   wszystko, co wylądowało w przestrzeni prywatnej. Do tego pałac nie ma
   narzędzia „usuń skrzydło", więc i tak trzeba mieć listę szuflad.
4. **Osobne sprzątanie w każdej z trzech klas** — tak wyglądał prototyp.
   Po całym przebiegu grupy pałac i tak rósł, bo dwie klasy nie sprzątały nic.
   Trzy kopie to trzy miejsca, w których można zapomnieć.
5. **Porażka sprzątania wywraca test** — mieszałaby dwie różne informacje.
   Niedostępny pałac na końcu przebiegu nic nie mówi o sprawdzanym kodzie,
   a czerwone światło, które nie znaczy „kod jest zepsuty", uczy ignorowania
   czerwonych świateł. Dlatego **głośno na stderr, ale bez porażki testu** —
   i z nazwą skrzydła, bo bez niej nie wiadomo, co pozostało do ręcznego
   usunięcia.

**Jak to się sprawdza.** `mempalace_status` przed przebiegiem grupy i po nim ma
podać tę samą liczbę `total_drawers`. Zmierzone: 1689 → 1689 (przed zmianą ten
sam przebieg dokładał 14 szuflad).
