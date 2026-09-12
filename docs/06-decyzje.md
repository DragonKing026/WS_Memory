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
