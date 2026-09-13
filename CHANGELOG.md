---
noteId: "32101d30aeb011f1997d030a3cd38ca7"
tags: [ws-memory, changelog, historia-zmian]

---

# CHANGELOG — WS_Memory

Wszystkie istotne zmiany w projekcie, z datą i godziną. Najnowsze na górze.
Format: `## RRRR-MM-DD GG:MM — tytuł`.

**Czas wpisu to czas commita, który go wprowadził** — do sprawdzenia przez
`git log -S"<tytuł wpisu>" -- CHANGELOG.md`. Nie szacujemy go i nie zapisujemy
„z pamięci": daty pisane na wyczucie już raz rozjechały ten plik o kilka godzin
i umieściły dwa wpisy w przyszłości.

---
## 2026-09-13 11:17 — TODO-007 ukończone: baza wiedzy da się przeszukać

Zadanie rozliczone i przeniesione do `TODO/DONE/`. Wszystkie siedem kryteriów
ukończenia spełnione, z pomiarem na bazie 10 037 szuflad i 10 005 dokumentów.

Rozliczenie zawiera to, czego w kodzie nie widać: dlaczego próg słabych wyników
jest **względny, a nie stały** (zmierzone przedziały trafności zachodzą na
siebie, więc żadna stała ich nie rozdziela — stały jest odstęp od najlepszego
wyniku), oraz cztery usterki znalezione dopiero na ekranie albo w pomiarze,
z których żadnej nie było widać w kodzie.

Wypisane jest też, **czego w zadaniu nie ma**: grafu wiedzy na ekranie surowej
pamięci (fakt nie jest szufladą i nie wraca z listowania rejestru — potrzebuje
własnego widoku) oraz historii rewizji i edytora, które są w `TODO-008`.

---
## 2026-09-13 11:15 — Publikacja wiki ponawia push, CodeQL wyjaśniony

**Publikacja wiki padła na błędzie 500 GitHuba.** Nie po naszej stronie: commit
powstał poprawnie (5 plików), a odrzucił go serwer — `remote: Internal Server
Error`, z identyfikatorem żądania. Ponowienie bez żadnej zmiany treści przeszło,
co potwierdza, że to chwilowa awaria usługi wiki.

Workflow ponawia teraz push trzy razy, z rosnącą przerwą i `git pull --rebase`
między próbami (ktoś mógł w tym czasie zapisać stronę przez interfejs). Bez tego
chwilowa awaria zostawia wiki nieaktualną i czerwony przebieg, o którym po
tygodniu nikt już nie wie, czy coś znaczył.

**Czerwony CodeQL wyjaśniony i sam się rozwiązał.** Awaria wędrowała po
językach: najpierw padły `javascript-typescript` i `actions`, potem `python`,
a trzeci przebieg był w pełni zielony. Każdy język zapisuje nieudaną analizę
przyrostową w cache Actions osobno i przy kolejnym uruchomieniu leci bez niej —
więc po tym, jak wszystkie trzy ją odnotowały, problem zniknął. Nasz kod nie miał
z tym nic wspólnego.

**Sprzątnięte po pomiarze:** 10 000 dokumentów testowych, 10 037 wpisów rejestru
i przestrzeń „Test wydajności" usunięte, token agenta unieważniony, tymczasowe
podniesienie limitu tempa (`backend/.env.local`) skasowane. Szuflady z pałaca
kasowane przez jego własne API, nie po tabelach (D-004).

---
## 2026-09-13 11:10 — Surowa pamięć na własnym ekranie i pomiar wydajności

Ekran `/memory` z `GET /api/memory`: przeglądanie wszystkiego, co trafiło do
pamięci, z filtrami przestrzeni, klasy wiedzy i zakresu dat. **Osobno od wiki
i to jest cały sens tego ekranu** — wiki jest tym, co zespół postanowił zapisać,
a to jest tym, co zostało zauważone. Zmieszane w jednej liście, drugie
przykryłoby pierwsze i czytelnik straciłby różnicę między „ustaliliśmy tak"
a „agent zauważył coś takiego".

`MemoryEntryView` jest osobnym typem od `SearchHit`, choć kuszące było użycie
jednego: wynik wyszukiwania istnieje w odpowiedzi na pytanie i niesie trafność,
a listowanie na żadne pytanie nie odpowiada. Wymyślona liczba na ekranie jest
gorsza niż jej brak. Stronicowane od początku, bo rejestr rośnie najszybciej
w całym systemie — dokumenty właśnie pokazały, co się dzieje bez tego.

**Pomiar wydajności, kryterium ukończenia TODO-007.** Baza testowa: 10 037
szuflad w pałacu i 10 005 dokumentów w bazie.

| Co | Czas |
|---|---|
| Wyszukiwanie semantyczne (20 wyników) | **0,10 s** |
| Wyszukiwanie leksykalne, zapytanie wybiórcze | **0,02 s** |
| Wyszukiwanie leksykalne, słowo w każdym dokumencie | **0,40 s** |
| Przeglądanie pamięci (20 wpisów) | **0,23 s** |
| Lista dokumentów (strona po 100) | **0,25 s** |

Wszystko z narzutem trybu deweloperskiego Symfony (samo `/api/me` to 0,02–0,04 s).
Najgorszy przypadek leksykalny to 151 ms samego SQL-a — słowo występujące
w dziesięciu tysiącach dokumentów, czyli sytuacja, w której indeks nie ma czego
zawęzić.

Znacznik autora dopisany też w drzewie dokumentów. Wcześniej pokazywałem tam sam
znacznik AI, choć w wynikach wyszukiwania argumentowałem, że znacznik
pojawiający się tylko czasem czyta się jako „nie wiadomo". Teraz w obu miejscach
widać autora — w gęstym drzewie ikoną zamiast słowem.

---
## 2026-09-13 10:57 — Drzewo dokumentów zamiast płaskiej listy

Ekran przestrzeni pokazywał dokumenty jednym ciągiem. TODO-007 wymaga **drzewa**
i słusznie: adres dokumentu niesie ścieżkę (`umowy/najem`,
`procedury/kadry/urlopy`), a to jedyna struktura, jaką wiki ma. Spłaszczona jest
niewidoczna, więc przestrzeń z dwustoma dokumentami zamienia się w ścianę
tytułów, której nikt nie przegląda.

Foldery zwijalne, z licznikiem tego, co pod nimi leży, wcięcie rysowane
krawędzią (przy czwartym poziomie sam odstęp przestaje mówić, do którego rodzica
należy wiersz). Foldery alfabetycznie, dokumenty w środku od najnowszej zmiany —
i ta różnica jest celowa: folder to miejsce, a stałe uporządkowanie ułatwia
znajdowanie miejsc; dokument to zdarzenie, a przy zdarzeniach liczy się, które
było ostatnie.

Budowanie drzewa siedzi poza komponentem i ma dziewięć testów, bo ciekawe
przypadki nie są wizualne: adres bez folderu, dwie ścieżki różniące się dopiero
głębiej, pusty odcinek po podwójnym ukośniku i licznik, który musi sumować całe
poddrzewo, a nie tylko własny poziom.

**Czerwony przebieg CodeQL to nie nasz kod.** GitHub zgłasza: „attempted to run
with improved incremental analysis but it did not complete successfully… possible
reason is disk space constraints". Awaria maszyny GitHuba przy analizie
przyrostowej, zapisana w cache Actions — następny przebieg leci bez niej.

---
## 2026-09-13 10:45 — Dwie usterki znalezione bazą testową

Zasilanie bazy do pomiaru wydajności wywlokło dwa realne błędy. Oba są na
głównych ścieżkach i oba zdarzyłyby się bez żadnych danych testowych — po prostu
później i u kogoś innego.

**Lista dokumentów nie miała stronicowania i przewracała się na 10 tys. wpisów.**
`GET /api/spaces/{s}/documents` ładował wszystkie dokumenty przestrzeni jako
encje Doctrine. Przy dziesięciu tysiącach PHP wyczerpywał 128 MB, a błąd
krytyczny szedł jako HTML **po** wysłaniu nagłówka 200 — czyli klient dostawał
udaną odpowiedź zawierającą stronę błędu. Teraz strona po 100 pozycji, maksimum
500, z `hasMore`. Odpowiedź: 27 kB w 0,25 s zamiast błędu.

To Zod na granicy pokazał, po co tam jest: zamiast `undefined` trzy ekrany dalej
frontend napisał wprost, że odpowiedź nie zgadza się z kontraktem.

**Zapisanie tej samej treści dwa razy kończyło się błędem 500.** Pałac scala
identyczną treść w obrębie skrzydła i oddaje szufladę, którą już ma; nasz rejestr
odrzucał ją przez indeks unikalny `uniq_entries_drawer`. Agent ponawiający
wywołanie albo dwóch agentów zapisujących to samo ustalenie dostawali nieczytelne
500. Teraz to wynik poprawny i idempotentny — treść **jest** w pamięci, o to
chodziło — a wpis w audycie niesie `duplicate: true`, bo „nic nowego nie
zapisano" to dokładnie ta informacja, której ktoś czytający ślad potrzebuje.
Ta sama szuflada zaksięgowana w **innej** przestrzeni nadal jest błędem: to
rozjazd rejestru z pałacem (reguła 5), a nie duplikat.

**Poprawka wydajności wyszukiwania leksykalnego, też z pomiaru.** Zapytanie
napisane „od dokumentów" nie używało indeksu GIN — planista czytał wszystkie
rewizje i liczył `to_tsvector` dla każdej. Oba zbiory trafień startują teraz od
predykatu tekstowego: **157 ms → 2 ms** przy 10 tys. dokumentów. `ts_headline`
liczy się po obcięciu do limitu, a nie na wszystkich trafieniach.

---
## 2026-09-13 10:26 — TODO-007: ekrany wyszukiwania, przestrzeni i dokumentu

Ekran `/` to teraz wyszukiwarka: przełącznik trybu z jednozdaniowym wyjaśnieniem
różnicy, filtry (przestrzeń, klasa, zakres dat), wyniki z przestrzenią,
trafnością, autorem i znacznikiem weryfikacji. Słabe wyniki w zwiniętej sekcji.
Stan pusty nazywa szukaną frazę i podpowiada trzy realne przyczyny: zły tryb,
za wąskie filtry, albo luka w dokumentacji.

Do tego lista dokumentów przestrzeni (najnowsze na górze — ludzie przychodzą
z pytaniem „co się zmieniło", nie „co istnieje") i ekran dokumentu z Markdownem,
autorstwem i weryfikacją nad treścią, nie w stopce.

**Trzy rzeczy naprawione po zobaczeniu ich na ekranie, nie w kodzie:**

*Procent trafności w trybie dokładnym wprowadzał w błąd.* `ts_rank` ma inną
skalę niż podobieństwo kosinusowe: trafienie idealne pokazywało się jako „14%".
Sam to udokumentowałem w `SearchHit`, po czym i tak wyrenderowałem jako procent.
W trybie leksykalnym nie ma już żadnej liczby — wynik jest dokładny albo go nie
ma, a `ts_rank` służy tylko do porządkowania.

*Layout na telefonie był zepsuty.* Panel boczny zabierał 256 z 390 pikseli,
zostawiając 134 na treść. Teraz poniżej `md` menu chowa się za przyciskiem,
wyszukiwarka dostaje własny wiersz, a treść całą szerokość. Sprawdzone przy
390 px: brak przewijania poziomego.

*Wyłączone przyciski nie wyglądały na wyłączone* — przezroczystość 0,75
i podpowiedź widoczna dopiero po najechaniu. Dopisane zdanie mówiące wprost,
że edycja i historia jeszcze nie działają.

**Pułapka wolumenu `node_modules`, na którą wpadłem i którą opisałem.**
Przysłonięcie `/app/node_modules` obowiązuje **od utworzenia kontenera**.
Skasowanie katalogu po stronie hosta je rozbija — kontener zaczyna wtedy pisać
prosto do repozytorium, zostawiając pliki roota i binaria musl w drzewie hosta.
Objawy mylą, bo wszystko nadal działa. Procedura naprawy i sposób sprawdzenia
(`mount | grep /app` musi dać **dwie** linie) są w `docs/07-frontend.md`.

Podświetlenie renderuje się jako węzły tekstowe z części, które przysyła API —
nigdzie nie ma `v-html` na treści z bazy. Markdown dokumentu idzie przez
`markdown-it` z `html: false`, więc surowe znaczniki są ekranowane, a nie
wykonywane.

---
## 2026-09-13 10:08 — TODO-007: wyszukiwanie działa po stronie API

Endpoint `GET /api/search`, oba tryby, z filtrami (przestrzeń, klasa wiedzy,
zakres dat, limit). Sprawdzone na żywym stosie, na pięciu prawdziwych polskich
dokumentach — nie tylko w testach.

**Tryb semantyczny znajduje bez wspólnych słów:** „wolne dni" → *Zasady
urlopów*, „jak rozliczyć hotel" → *Zwrot kosztów podróży* (nocleg), „nowy
pracownik pierwszy dzień" → *Wdrożenie nowej osoby*. Za każdym razem właściwy
dokument pierwszy.

**Znaleziona i naprawiona usterka, która cicho psułaby cały tryb leksykalny.**
Tokenizowałem zapytanie w PHP wyrażeniem `[\p{L}\p{N}_]+`. Postgres tokenizuje
`D-029` jako `d` **oraz `-029`** — z minusem, bo czyta to jako liczbę ze znakiem.
Zapytanie o `029` nie pasowało do niczego, więc szukanie identyfikatora decyzji
albo zgłoszenia zwracało pustkę. Teraz zapytanie tokenizuje **ten sam parser**,
co treść, a `quote_literal` pilnuje bezpieczeństwa. Dwa testy padają, jeśli ktoś
wróci do tokenizacji ręcznej — sprawdzone przez cofnięcie poprawki.

**Przedrostek tylko na ostatnim słowie.** Na wszystkich robił szum: `D-029` →
`d:*` łapało „do", „dokument", „dostęp" w trybie, którego całym zadaniem jest
dokładność. Ostatnie słowo to to, które użytkownik właśnie pisze.

**Próg słabych wyników jest względny, nie bezwzględny** — i to wynik pomiaru,
nie preferencja. Trafność właściwego dokumentu to 0,477–0,611, a szumu
0,29–0,44: przedziały zachodzą na siebie, więc żadna stała nie rozdziela ich
poprawnie. Stały jest *odstęp*, dlatego mocny wynik to „w granicach jednej
czwartej od najlepszego w tej odpowiedzi".

**Podświetlenie jedzie jako struktura, nigdy jako HTML z bazy** — treść pisana
przez ludzi i agentów może zawierać wszystko, a znacznik `<script>` z bazy
wyrenderowany jako HTML to trwały XSS. Test pilnuje tego wprost.

Odpowiedź niesie też **własne pokrycie**: tryb leksykalny mówi, że pełną treść
przeszukuje tylko w dokumentach. Limit z D-029 jedzie razem z wynikami, żeby
interfejs nie musiał go powtarzać ani zgadywać.

Testy: 242 (było 215), PHPStan czysty.

---
## 2026-09-13 09:48 — TODO-007: fundament wyszukiwania i dwie decyzje

Początek TODO-007. Zanim powstał choć jeden ekran, trafiły się trzy ustalenia,
które zmieniają kształt zadania — dlatego są zapisane, a nie obchodzone.

**REST API nie miało wyszukiwania w ogóle.** Istniało wyłącznie przez bramkę
MCP, czyli dla agentów. TODO-007 jest opisane jako zadanie frontendowe, ale bez
endpointu nie ma czego wyświetlać — backend wchodzi w zakres.

**Pałac nie umie trybu leksykalnego** (D-029). `mempalace_search` przyjmuje
`query`, `wing`, `room`, `since`, `before` i `max_distance` — parametru trybu
nie ma. Tryb leksykalny realizujemy więc u siebie, w PostgreSQL, na treści
rewizji dokumentów oraz tytułach i tagach wpisów. **Konsekwencja nazwana
wprost:** nie sięga treści szuflad innych niż dokumenty, bo ta mieszka wyłącznie
w pałacu. Interfejs ma to mówić, a nie udawać pełne pokrycie.

**Postgres nie ma polskiej konfiguracji tekstowej** (D-030) — sprawdzone przez
`\dF`. Używamy `simple` z dopasowaniem przedrostkowym, bez rdzeniowania, i to
nie jest ustępstwo: tryb leksykalny odpowiada na „gdzie dokładnie występuje ta
nazwa", a przy `PalaceWing` rdzeniowanie dokłada trafienia błędne. Odmianę
obsługuje tryb semantyczny. `pg_trgm` odrzucone świadomie — założenie
rozszerzenia wymaga uprawnienia `CREATE` na bazie, którego rola `ws_app`
celowo nie ma.

Migracja z indeksami GIN sprawdzona w obie strony (`up`, `down`, ponowne `up`).
Typy domenowe: `SearchMode`, port `LexicalIndex` i `SearchHit` — osobny typ
wyniku, bo `MemoryFragment` nie niesie autora ani weryfikacji, których zadanie
wymaga, a świeżo zapisany dokument nie ma jeszcze identyfikatora szuflady.

---
## 2026-09-13 00:11 — Edytor odzyskuje typy, `baseUrl` znika

**Edytor nie widział żadnych typów.** Zgłaszał brak `vite/client`, a w praktyce
nie podpowiadał niczego w całym projekcie. Przyczyna: kontener trzyma zależności
w anonimowym wolumenie przysłaniającym `/app/node_modules`, więc
`frontend/node_modules` na hoście był **pustym punktem montowania**. Testy
i build przechodziły, bo biegną w kontenerze — problem widział tylko człowiek
patrzący na kod.

Lekarstwo: jednorazowe `npx pnpm@10.20.0 install --frozen-lockfile` w `frontend/`
na hoście. To nie koliduje z kontenerem i nie jest obejściem — na tym polega
anonimowy wolumen: każda strona ma własne drzewo z binariami dla swojej
biblioteki C (host glibc, kontener musl). Opisane w `docs/07-frontend.md`, bo
inaczej następna osoba straci na tym ten sam wieczór.

**`baseUrl` usunięte z `tsconfig.json`** — jest wycofane i przestanie działać
w TypeScripcie 7. Ścieżki w `paths` liczą się teraz względem pliku
konfiguracyjnego (`./src/*`). Sprawdzone: typecheck, testy i build przechodzą
zarówno w kontenerze, jak i na hoście.

---
## 2026-09-13 00:00 — esbuild podbity do 0.28.2, zgłoszenie bezpieczeństwa zamknięte

**Poprzedni wpis podawał złą przyczynę.** Napisałem tam, że zakres esbuilda
przypina Vite 8 — nieprawda. Vite 8.3.0 deklaruje `^0.27.0 || ^0.28.0`,
czyli 0.28 dopuszcza od dawna. Jedyną twardą zależnością był `fontless@0.2.1`
(przez `@nuxt/fonts`, przez `@nuxt/ui`) z zakresem `^0.27.0`. Dependabot nie
podnosi wersji przechodniej wbrew zakresowi pakietu pośredniego i dlatego
raportował `security_update_not_possible` — a nie dlatego, że podbicie było
niemożliwe.

**Rozwiązanie: `pnpm.overrides` na `esbuild: ^0.28.2`.** Nadpisanie zakresu
zadeklarowanego przez zależność to decyzja, którą trzeba udowodnić, a nie
założyć, więc sprawdzone zostały wszystkie cztery drogi: `typecheck`, `24/24`
testów, `pnpm build` (901 modułów) i **budowa obrazu produkcyjnego od zera**
z `--frozen-lockfile`. Dodatkowo serwer deweloperski wstaje, strona się
renderuje, a konsola przeglądarki jest pusta. Nowszy `fontless` (0.3+) w ogóle
porzucił esbuilda, więc nadpisanie zniknie samo, gdy `@nuxt/fonts` podniesie
zależność ponad `^0.2.1`.

**Przy okazji dwa ostrzeżenia Vite w `vite.docker.config.mts`**, obie w kodzie
z tej samej sesji: `server.hmr.clientPort` jest wycofane na rzecz
`server.ws.clientPort`, a import `./vite.config` bez rozszerzenia nie przejdzie
przez `configLoader: 'native'`, który ma być domyślny. Poprawione i sprawdzone
na żywym HMR — websocket łączy się przez nginxa (`[vite] connected`).

---
## 2026-09-12 23:45 — Porządek: zrzuty mają swoje miejsce, pałac kończy czysto

**Zrzut ekranu wylądował w korzeniu repozytorium** i tak został wypchnięty.
Przeniesiony do `TODO/zrzuty/`, a konwencja dopisana do `AGENTS.md`,
`TODO/README.md` i osobnego `TODO/zrzuty/README.md`: nazwa `NNN-krotki-opis.png`,
nigdy w korzeniu, i **obejrzeć przed dodaniem** — obrazu z tokenem nie da się
usunąć z historii publicznego repozytorium. Ten akurat był czysty: token
w kadrze był ucięty przez przewijanie poziome.

**MemPalace kończył się kodem 137**, bo nie reaguje na SIGTERM — Docker czekał
dziesięć sekund i zabijał go SIGKILL-em. Na SIGINT kończy się czysto w 0,2
sekundy (sprawdzone), więc `stop_signal: SIGINT` w compose. Danych to nie
dotyczyło, ale każde zatrzymanie stosu trwało dziesięć sekund dłużej, a kod
wyjścia wyglądał w interfejsie Dockera jak awaria.

**Szybki przebieg CI padał na frontendzie.** `pnpm/action-setup` szuka pola
„packageManager" w `package.json` w **korzeniu** repozytorium, a nasz frontend
jest w podkatalogu — przebieg kończył się „No pnpm version is specified", mimo
że wersja jest zapisana tam, gdzie należy. Wskazanie pliku naprawia sprawę.

**Dependabot objął `/frontend`** (ekosystem npm, z grupowaniem Vite/Vue/UI
i pominięciem głównych wersji Vue, Nuxt UI i TypeScriptu).

**Otwarte zgłoszenie bezpieczeństwa, którego nie da się teraz zamknąć:**
`esbuild` poniżej 0.28.1 pozwala odczytać dowolny plik, gdy serwer deweloperski
działa **na Windowsie**. Dependabot zgłasza `security_update_not_possible` —
najwyższa wersja osiągalna w naszym drzewie to 0.27.7, bo Vite 8 przypina
zakres. Faktyczna ekspozycja: **żadna** — serwer deweloperski chodzi w kontenerze
Linuksa, wystawiony wyłącznie przez nginxa na pętli zwrotnej, a produkcja nie ma
serwera deweloperskiego w ogóle. Zniknie, gdy Vite podniesie zakres.

---
## 2026-09-12 23:22 — TODO-006 ukończone: aplikacja dla ludzi stoi

Frontend działa pod `http://127.0.0.1:8080`. Logowanie, akceptacja zaproszenia,
layout z przestrzeniami, zarządzanie tokenami agentów. Jeden origin przez
nginxa, więc przeglądarka nigdy nie dotyka CORS-a; HMR przez websocket na
porcie nginxa.

**Sprawdzone w przeglądarce, nie tylko w testach:** niezalogowany na `/` trafia
na `/login` z zapamiętanym celem, logowanie działa, token jest odtwarzany po
przeładowaniu, lista i wystawianie tokenów agentów idą przez prawdziwe API,
a edycja pliku na hoście zmienia stronę bez przeładowania. Konsola czysta.

**Jedno kryterium było niewykonalne i jest to zapisane wprost.** Zadanie
wymagało przezroczystego odświeżania tokena — endpointu odświeżania nie ma
(D-017). Zrealizowana jest intencja, która za tym stała: 401 kończy sesję
jawnie, klient zapamiętuje, **gdzie** był użytkownik, i wraca tam po
zalogowaniu. Test pilnuje, że po 401 **nie ma ponowienia** — bez odświeżania
byłoby to drugie identyczne 401, a „na wszelki wypadek" ktoś takie dopisze.

**Decyzje:** D-027 (trasy wypisane jawnie — `unplugin-vue-router` wymaga
`vue-router ^4.6`, a stack mówi 5; cofanie routera o major to dług migracyjny
wzięty pierwszego dnia), D-028 (token w `localStorage`, z ryzykiem nazwanym
w `SECURITY.md`, bo ryzyko znane tylko autorowi kodu nie jest przyjęte).

**Cztery rzeczy warte zapamiętania:**

1. **Polski cudzysłów zamknięty znakiem `"` zamyka atrybut HTML.** Kompilacja
   strony wywracała się komunikatem o `trim` w kompilatorze szablonu, który nie
   wskazywał przyczyny.
2. **`vue-tsc` przeszedł, choć był błąd typu** — gdy jeden plik nie daje się
   sparsować, sprawdzenie po cichu zawęża zakres i mówi „czysto". Wyszło dopiero
   przy `pnpm build`, dlatego CI ma oba kroki.
3. **`instanceof AxiosError` zawodzi** przy dwóch kopiach axiosa w grafie: każdy
   błąd stawał się „nieoczekiwanym błędem przeglądarki". Zamienione na
   `axios.isAxiosError()`, złapane przez test.
4. **Nie było pliku blokady pnpm** — build nie był powtarzalny. Wygenerowany
   i commitowany, a `--frozen-lockfile` jest teraz bez awarii do zwykłego
   `install`.

**Wersje:** Vite 8 i Vitest 5 zamiast zapisanych 7 i 4 (to wersje bieżące),
TypeScript celowo 5.9 zamiast 7 — TS 7 to przepisany rdzeń, a `vue-tsc` stoi na
API poprzedniej generacji.

**Testy:** 24 w Vitest, `vue-tsc` czysty, obraz produkcyjny serwuje `dist/`
i **nie zawiera `node`**. Szybki przebieg CI ma teraz zadanie frontendu: typy,
testy, build.

---
## 2026-09-12 22:52 — TODO-005 ukończone: wiki z rewizjami, cofaniem i kolejką

Dokumentacja kanoniczna działa. Dokument, pełna historia rewizji, różnica między
dowolnymi dwiema, cofnięcie i kolejka propozycji dla przestrzeni, które jej
chcą. Zestaw narzędzi MCP jest kompletny: **jedenaście**.

**Rewizje trzymają pełne treści, nie diffy.** Łańcuch diffów jest tak
wiarygodny jak jego najsłabsze ogniwo — jedno uszkodzone unieważnia całą historię
od tego miejsca. Różnicę liczymy na żądanie (własny LCS po wierszach).

**Cofnięcie idzie do przodu.** Przywrócenie rewizji 1 tworzy rewizję 4 o jej
treści; rewizje 2 i 3 zostają. Historia, która może się skrócić, nie jest
historią.

**Nowa rewizja czyści weryfikację.** „Anna to sprawdziła" przestaje być prawdą
w chwili zmiany tekstu, a nieaktualna odznaka jest gorsza niż jej brak. To
czyszczenie siedzi w `Document::addRevision()` razem z numerem i tytułem — bo
rozniesione po serwisie jest tym, o czym ktoś zapomni.

**`verify()` przyjmuje `User`, nie `Actor`.** Agenta nie da się przekazać, więc
„agent potwierdza własny wpis" jest niewyrażalne, a nie tylko zabronione (D-005).

**Trzy rzeczy, które zmieniły projekt zadania:**

1. **`mempalace_update_drawer` istnieje.** Zadanie zakładało wypychanie nowej
   szuflady przy każdej rewizji — a to zostawiłoby każdą starą wersję
   wyszukiwalną, przy czym wynik wyszukiwania niesie treść, nie numer rewizji.
   Agent nie miałby jak poznać aktualnej. Jedna szuflada na dokument,
   aktualizowana w miejscu (D-025). Sprawdzone, nie założone: test potwierdza,
   że po aktualizacji **stara treść przestaje być znajdowalna**.
2. **Klucz obcy złożony zamiast `CHECK`.** Model danych obiecywał `CHECK`
   pilnujący, że bieżąca rewizja należy do tego dokumentu — `CHECK` nie sięga do
   innej tabeli. Klucz na `(current_revision_id, id)` robi to deklaratywnie
   i czyni błąd niewyrażalnym.
3. **„Dokładnie jeden autor" ma konsekwencję**, której nie było w analizie:
   rewizja agenta nie zapisuje właściciela tokena, a rejestr wymaga człowieka.
   Dołożone `ownerOf()`, odpowiadające także dla tokenów unieważnionych — kto coś
   napisał, nie zmienia się, gdy jego poświadczenie zostaje wycofane.

**Strażnik kolejności:** zlecenie publikacji niesie numer rewizji i jest
porzucane, jeśli dokument ma nowszą. Test opróżnia kolejkę **od najnowszego
zlecenia**, bo tylko w tej kolejności strażnik jest sprawdzany — przy dostarczaniu
FIFO nie dowiódłby niczego.

**Decyzje:** D-025 (szuflada aktualizowana w miejscu, zlecenia nieaktualne
porzucane), D-026 (propozycję składa czytający, autorem przyjętej rewizji jest
recenzent).

**Testy:** 215 (było 153), 889 asercji. `WikiTest` (25) nie potrzebuje pałaca
i chodzi przy każdym commicie; wyłączenie czyszczenia weryfikacji przewraca
jeden test, a odpowiadanie 403 zamiast 404 — cztery.
`WikiOnLivePalaceTest` (6) sprawdza zastępowanie treści w pałacu i wyścig.

**Przy okazji:** testy integracyjne spędzały 2,5 minuty na decyzji o pominięciu,
bo Docker nie odrzuca nazwy zatrzymanego kontenera, tylko przekracza limit czasu.
Sprawdzenie jest teraz jedno na uruchomienie — zestaw skrócił się do 42 sekund.

---
## 2026-09-12 22:12 — TODO-004 ukończone: agent AI może się podłączyć

Gateway MCP działa. Agent dostaje siedem narzędzi `ws_*`, własny token
i uprawnienia właściciela — nigdy więcej niż on.

```
claude mcp add --transport http ws_memory http://…/mcp \
  --header "Authorization: Bearer wsm_…"
```

Polecenie wypisuje `ws:agent:token`, razem z tokenem. Sprawdzone **przez
nginxa**, nie tylko w testach: `initialize` uzgadnia `2025-06-18`, `tools/list`
zwraca siedem narzędzi, `ws_remember` bez wskazanej przestrzeni ląduje
w prywatnej, a `ws_search` znajduje to innymi słowami (podobieństwo 0,603).

**Zestaw narzędzi JEST granicą uprawnień** (D-007). Żadne nie ma parametru
autora ani skrzydła, więc podszycie się i obejście filtra przestrzeni są
**niewyrażalne**, a nie tylko zabronione — test przechodzi wszystkie schematy
z `tools/list`, żeby tak zostało po dodaniu ósmego narzędzia.

**Nieznany parametr jest błędem, nie rzeczą do zignorowania.** Parametrem, który
agent wymyśli najczęściej, jest `wing` — nauczony od lokalnego MemPalace,
podłączonego w tej samej sesji. Zignorowany `wing` znaczyłby, że agent uwierzy,
iż zawęził wyszukiwanie, choć go nie zawęził.

**Powstało:** `Presentation\Mcp\` (kontroler, serwer JSON-RPC, port narzędzia,
rejestr tagowany, dekorator audytu, walidacja argumentów, kody błędów, siedem
narzędzi), `AgentToken\{Issue,Revoke}`, `DoctrineAgentTokenDirectory`,
`AgentTokenAuthenticator`, `Api\AgentTokenController`, komenda
`ws:agent:token`, encja i migracja `Version20260912000004`.

**Decyzje:** D-022 (limit tempa w bazie, w tym samym wierszu co „ostatnio
użyty"), D-023 (błąd narzędzia jako błąd JSON-RPC — wbrew zaleceniu
specyfikacji MCP, bo MemPalace robi to zgodnie z zaleceniem i kosztowało nas
to godziny), D-024 (audyt zapisuje się od razu).

**Usterka, którą wykryły testy — poważna:** wpisy audytu **nigdy** nie
zapisywały się przy wywołaniach MCP. `DoctrineAuditTrail` robił `persist()`
i zostawiał `flush` wołającemu; wywołanie narzędzia nie zmienia żadnej encji,
więc nic nie flushowało i cała aktywność agentów przechodziła bez śladu — bez
żadnego błędu. Naprawione natychmiastowym `INSERT`-em.

**Trzy rzeczy, które wyszły dopiero przy sprawdzaniu przez nginxa:**
`capabilities.tools` i puste `properties` serializowały się jako `[]` zamiast
`{}` (pierwsze łamie uzgodnienie MCP, drugie nie jest poprawnym JSON Schema);
`ws_remember` odpowiadało `space: null` przy zapisie do prywatnej przestrzeni;
a dekorator audytu, implementując interfejs narzędzia, został przez kontener
otagowany jako narzędzie i wstrzyknięty do rejestru.

**Testy:** 153 (było 115), 461 asercji. `McpGatewayTest` celowo **nie
potrzebuje pałaca** — wszystko, co sprawdza, dzieje się przed pamięcią, więc
chodzi przy każdym commicie. Pełny obieg przez `/mcp` na żywym pałacu jest
w `Integration/McpOnLivePalaceTest`. PHPStan poziom 8 bez błędów.

---
## 2026-09-12 21:31 — TODO-003 ukończone: backend czyta i zapisuje pamięć

Powstała jedyna droga między naszymi uprawnieniami a pałacem. Od teraz każde
zapytanie do pamięci niesie filtr przestrzeni, a każdy zapis jest zaksięgowany
w `ws.memory_entries` w tej samej transakcji.

**Reguła nr 3 przestała być zabroniona i stała się niewyrażalna.**
`MemoryStore::search()` przyjmuje `PalaceWing` jako pierwszy, nieopcjonalny
argument, a ten typ odrzuca wartość pustą. Zapytania bez filtra przestrzeni nie
trzeba pilnować w przeglądzie kodu — nie da się go napisać.

**Trzy rzeczy, których projekt zadania nie przewidział:**

1. **`mempalace_search` przyjmuje jedno skrzydło, nie listę.** `wing IN (...)`
   z dokumentacji nie jest wyrażalne jednym wywołaniem. Odczyt rozsyła więc po
   jednym zapytaniu na dozwoloną przestrzeń i przerankowuje wyniki; puste
   przecięcie uprawnień nie odpytuje pałaca wcale.
2. **Graf wiedzy nie ma osi skrzydła w ogóle.** Zakres wszedł do klucza: fakty
   żyją pod nazwą kwalifikowaną skrzydłem (`wing_alfa::Encja`), więc zapytanie
   o cudzą przestrzeń ich nie dopasowuje, zamiast dopasować i odfiltrować.
3. **Sierota przy dwóch magazynach jest nieunikniona** — HTTP nie da się
   wycofać. Wybraliśmy kierunek: szuflada bez wiersza wolna (jest niewidoczna),
   wiersz bez szuflady nigdy (byłby wynikiem, którego nie da się otworzyć).

**Powstało:** `src/Domain/Memory/` (11 plików — obiekty wartości i dwa porty),
`Application/Memory/MemoryService.php`, `Infrastructure/MemPalace/`
(klient JSON-RPC, adapter portu, `CallOutcome`, wyjątek),
`Infrastructure/Doctrine/` (rejestr i katalog przestrzeni), migracja
`Version20260912000003` z tabelą `ws.memory_entries`.

**Decyzje:** D-019 (dwie warstwy filtrowania), D-020 (kierunek awarii i brak
ponawiania zapisów), D-021 (zakresowanie grafu wiedzy).

**Co wykrył test na żywym pałacu — i nic innego nie mogło:**

- MemPalace odrzuca etykietę autora ze znakami ścieżki (`ws:user/token`),
  bo używa jej jako segmentu ścieżki przy zapisie do dziennika,
- `mempalace_diary_write` odpowiada polem `entry_id`, nie `drawer_id`. Bez tego
  wpis zostałby zapisany i **nigdy zaksięgowany**, czyli nieczytelny na zawsze.

**Testy:** 115 (było 82), 238 asercji. Z tego 27 jednostkowych na uprawnieniach
bez bazy i bez pałaca, 22 na przewodzie JSON-RPC, 12 bazodanowych i 8 na żywym
pałacu. PHPStan poziom 8 bez błędów. Wyłączenie drugiej warstwy filtrowania
przewraca 5 testów — sprawdzone celowo.

**Dokumentacja:** `docs/02`, `docs/03`, `docs/05`, `docs/08`, `docs/09`
i `docs/06` (trzy decyzje) w obu wersjach językowych. W `docs/03` poprawione
mapowanie, które obiecywało filtr niewyrażalny w protokole.

---

## 2026-09-12 20:45 — Wiki generowana z dokumentacji

Wiki GitHuba jako **renderowane lustro** katalogu `docs/`: 23 strony plus pasek
boczny, obie wersje językowe, publikowane po każdym scaleniu do `main`, które
dotknęło dokumentacji.

Świadomie **nie** jest drugim źródłem prawdy. Ręczna edycja zostaje nadpisana,
o czym informuje nagłówek każdej strony. Powód jest ten sam, dla którego
powstał `make sprawdz-dokumentacje`: dwa miejsca z tą samą treścią rozjeżdżają
się zawsze, a nieaktualny opis bywa traktowany jako fakt — także przez modele
AI, które go czytają.

Generator przepisuje odnośniki ze ścieżek plików na nazwy stron wiki; bez tego
nawigacja prowadziłaby donikąd. Podgląd lokalny przez `make wiki`.

---

## 2026-09-12 20:32 — Ochrona gałęzi, Dependabot i pierwsze zielone przebiegi

- **Ruleset na `main`**: zakaz usunięcia i przepisania historii, wymagane
  przejście dwóch sprawdzeń, wymagany pull request z zerem akceptacji.
  Administrator omija — przy jednej osobie wymuszanie PR-ów na literówkę
  byłoby ceremonią bez treści; wyjątek znika, gdy dojdą kolejne osoby.
- **Dependabot** dla PHP, akcji i obrazów, z aktualizacjami grupowanymi.
  Włączone alerty i automatyczne poprawki bezpieczeństwa.
- **CodeQL w trybie domyślnym** z AI findings (D-018) — pierwszy przebieg
  zielony w minutę.

**Pięć własnych usterek w CI, wyłapanych przez samo CI:** kontrola YAML
przewracała się na znacznikach Symfony; PHPStan wymagał skompilowanego
kontenera; nocny startował workera przed migracjami; brakowało `composer
install`, bo obraz deweloperski celowo nie zawiera zależności; brakowało
kluczy JWT, bo są w `.gitignore`. Wszystkie poprawione, wszystkie przebiegi
zielone.

**Dependabot od razu udowodnił sens nocnego przebiegu:** zaproponował podbicie
Pythona w obrazie pamięci z 3.12 na 3.14. Nocny uruchomił na tej gałęzi test
polskiej semantyki i dopiero jego wynik uzasadnił scalenie — bez tej bramki
byłby to skok w ciemno, a awaria objawiłaby się cicho, jako gorsze wyniki
wyszukiwania.

---

## 2026-09-12 19:51 — TODO-014: ciągła integracja i skanowanie kodu

- **Szybki przebieg** na każdym pushu i pull requeście: testy backendu
  z Postgresem, spójność dokumentacji, rozliczenia zadań, składnia, PHPStan.
  Celowo bez serwera embeddingów.
- **Przebieg nocny** z pełnym stosem i testem polskiej semantyki, uruchamiany
  też natychmiast przy zmianie konfiguracji embeddingów — to jedyne miejsce,
  w którym da się po cichu zepsuć trafność wyszukiwania.
- **CodeQL w trybie domyślnym + AI findings** (D-018). CodeQL nie obsługuje
  PHP, więc bez AI findings backend byłby niewidoczny dla skanowania.
- **PHPStan poziom 8** jako uzupełnienie, bo skanery podatności nie zgłaszają
  błędów poprawności. Przy pierwszym uruchomieniu znalazł cztery realne
  usterki, w tym identyfikator użytkownika mogący być pustym łańcuchem.
- Nowy `scripts/sprawdz-zadania.py` egzekwuje regułę o rozliczeniach zadań.

**Repozytorium stało się publiczne w trakcie tego zadania.** Skutki:
zanonimizowano nazwy klientów w dokumentacji **i w historii gita** (przepisanie
uzgodnione, kopia zapasowa zrobiona), usunięto wygenerowany `APP_SECRET`
z commitowanego pliku, wycofano sugestię self-hosted runnera. Uzasadnienie
podziału CI na dwie prędkości zmieniło się z kosztu minut na czas odpowiedzi —
wniosek ten sam, powód inny.

---

## 2026-09-12 19:17 — TODO-002: konta, przestrzenie, role i audyt

Pierwsze zadanie z prawdziwą logiką uprawnień, więc testy negatywne przed
kodem: **44 testy, 84 asercje**, z tego 12 negatywnych.

- `SpaceAccessResolver` jako **jedyne miejsce liczące uprawnienia**, z portem
  repozytorium — reguły testowane bez bazy, więc chodzą przy każdym commicie.
  Bez cache: odebranie roli działa natychmiast.
- Zaproszenia z tokenem przechowywanym wyłącznie jako skrót; konto i jego
  prywatna przestrzeń powstają w jednej transakcji.
- Logowanie JWT, `/api/me`, przestrzenie, nadawanie ról, `ws:user:invite`.
- Audyt: `invitation.issued`, `invitation.accepted`, `user.login`,
  `user.login_failed` (bez aktora — mamy wtedy tożsamość deklarowaną, nie
  potwierdzoną), `space.created`, `space.member_added`, `space.read`.
- **Przestrzeń poza uprawnieniami odpowiada bajt w bajt jak nieistniejąca**,
  a uprawnienie sprawdzane jest przed istnieniem, żeby nie różnicować czasu
  odpowiedzi.
- **D-016** — administrator globalny nie czyta cudzych przestrzeni po cichu.
- **D-017** — odświeżania tokenów nie piszemy własnoręcznie; TTL 8 godzin.

**Luka znaleziona przy pisaniu dokumentacji backendu:** dezaktywacja konta nie
odcinała dostępu, bo JWT zostaje ważny do wygaśnięcia. Naprawione
`ActiveAccountCheckerem` działającym przy każdym żądaniu.

Nowy dokument `docs/08-backend.md` (+ angielski): mapa wszystkich plików z rolą
każdego, przepływy żądań, uprawnienia od końca do końca, instrukcja dodawania
nowych rzeczy.

---

## 2026-09-12 18:48 — TODO-013: dokumentacja dwujęzyczna

Wykonane poza kolejnością, na wniosek: każde kolejne zadanie dokłada treści do
przetłumaczenia, więc zwlekanie kosztuje liniowo.

- `AGENTS.en.md`, `README.en.md`, `TODO/README.en.md` oraz `docs/en/` z sześcioma
  dokumentami i specem — około 1700 wierszy. Polska wersja pozostaje wiodąca;
  każdy plik angielski nosi w nagłówku wskazanie oryginału i datę synchronizacji.
- **Definicja ukończenia** w `AGENTS.md`: siedmiopunktowa lista przechodzona
  przed commitem. Reguła „aktualizuj dokumentację" była opisowa i przez to
  niesprawdzalna.
- `make sprawdz-dokumentacje` — wychwytuje brak odpowiednika, rozjazd numerów
  decyzji, brak nagłówka i plik bez wpisu w mapie. Sprawdzony przeciwko obu
  rodzajom usterki, bo skrypt zawsze przechodzący jest gorszy od jego braku.
- Zasada commitowania **natychmiast po zamknięciu zmiany**, nie na koniec
  zadania — historia gita ma pokazywać przebieg pracy, nie tylko wynik.

**Tłumaczenie okazało się przeglądem dokumentacji** i ujawniło pięć rozjazdów,
wszystkie poprawione: `README` twierdził, że implementacja jest nierozpoczęta;
`docs/05` że nie ma `docker-compose.yml` i używał nieistniejącej roli `ws`
w poleceniu backupu; `AGENTS.md` pisał, że mempalace mieli (wbrew D-012), miał
nieaktualną datę i mówił o siedmiu działających usługach zamiast sześciu; graf
zadań nie znał `TODO-012` ani `TODO-013`.

Spec projektowy przetłumaczono, ale oznaczono jako **zapis historyczny** —
zamyka się na D-009, a późniejsze decyzje zmieniły trzy opisane w nim rzeczy.

---

## 2026-09-12 18:29 — TODO-001: fundament backendu

Symfony 8.0 na PHP 8.4 jako **czyste API** (D-008): API Platform 4.3, Doctrine
ORM 3.6, Messenger z transportem w bazie, LexikJWT. Zero Twiga — Swagger UI
wyłączone, kontrakt wystawiony maszynowo pod `/api/docs.json`. Doszły trzy
usługi: `backend`, `worker` i `nginx`; wszystkie sześć jest `healthy`.

**Warstwy i porty od pierwszej klasy**, nie „później, jak urośnie":
`Domain/` → `Application/` → `Infrastructure/` → `Presentation/`. Endpoint
zdrowia jest tego przykładem — `HealthProbe` to port, sondy bazy i pamięci to
adaptery zbierane po tagu. Monitorowanie kolejnej zależności to dodanie klasy,
nie edycja kontrolera.

**Ustalenia językowe:** w kodzie wszystko po angielsku, łącznie z komentarzami
(konwencja głównej aplikacji Symfony zespołu); dokumentacja dwujęzyczna z polskim jako wersją wiodącą.
Pierwsza wersja kontrolera miała polskie nazwy — przepisana, zanim urosło.
Angielskie odpowiedniki dokumentacji to nowe `TODO-013`.

**Zasady struktury dopisane do AGENTS.md:** warstwy, kierunek zależności oraz
tabela wzorców z uzasadnieniem — każdy wzorzec przypisany do konkretnej
przyszłej zmiany, nie dodany „na wszelki wypadek".

**Pięć rzeczy, które wyszły dopiero w działaniu** (szczegóły w
`TODO/DONE/001-backend-fundament.md`):

- `doctrine:schema:validate` i `migrations:diff` **nie działają** — wymagają
  DBAL ^4.5, a stabilne jest 4.4.4. Sprawdzone, że to nie nasza konfiguracja:
  błąd występuje także bez `schema_filter`. Migracje piszemy ręcznie,
  walidujemy `--skip-sync`.
- `monolog-bundle` nie wspiera jeszcze Symfony 8 przy jawnym pinowaniu.
- `localhost` w kontenerze rozwiązuje się najpierw na IPv6 — healthcheck
  nginxa dostawał odmowę, bo `listen 80` wiąże tylko IPv4.
- Symfony cache'uje skompilowany kontener w zamontowanym wolumenie: poprawka
  konfiguracji nie działa, dopóki nie usunie się `backend/var/cache`.
- Healthcheck workera nie może używać `pgrep` (brak `procps` w obrazie PHP) —
  pytamy `/proc/1/cmdline`.

---

## 2026-09-12 18:01 — Pomiary modeli embeddingów i limity zasobów

Domknięcie incydentu z TODO-000: serwer embeddingów zajął 21 GB i zdławił
maszynę deweloperską. Zamiast zgadywać lżejszy model, zmierzono cztery
kandydaty na trzech polskich parach zdań (`test/porownaj-modele.py`, każdy z
twardym limitem pamięci, sprzątanie po każdym).

**Wynik obalił dwie pozorne oczywistości:**

- **`bge-m3` po dostrojeniu zajmuje 2,2 GB, nie 21 GB.** Te 19 GB to była
  wyłącznie rezerwacja buforów TEI (`--max-batch-tokens 16384` i tyle wątków
  tokenizacji, ile rdzeni) przy modelu z oknem 8192 tokenów. Model zostaje.
- **Najlepszy margines miał `paraphrase-multilingual-MiniLM` (0,281) — i to
  pułapka.** Ma okno **128 tokenów**, więc ucinałby każdą szufladę po ~90
  słowach, cicho i bez błędu. Odrzucony.
- Rodzina **E5 wypadła najgorzej** (margines 0,034–0,055) i potwierdziła
  pierwotny argument D-003: wymuszony wspólny prefiks `query: ` po obu
  stronach ściska podobieństwa w paśmie wokół 0,8 — para kontrolna dostaje
  0,804, dokładnie tyle co trafna.

**Prawdziwą przyczyną awarii był brak `mem_limit` w compose**, nie wybór
modelu. Kontener bez limitu bierze całą pamięć maszyny, więc pomyłka w
konfiguracji zamieniła się w zdławienie komputera. Limity są teraz na
wszystkich usługach i zostają także na produkcji jako druga linia obrony.

- Zmierzony stan pod limitami: embeddingi 2,12 GB z 4 GB, mempalace 66 MB,
  Postgres 31 MB — cały stos poniżej 2,3 GB.
- D-003 uzupełniona o tabelę pomiarów i wymóg strojenia buforów.
- `docs/05-deployment.md`: realne liczby zamiast szacunków, uzasadnienie limitów.
- Nowe narzędzie `test/porownaj-modele.py` — stanowisko do porównywania modeli
  na polskich parach zdań, przydatne przy każdej przyszłej zmianie modelu.

---

## 2026-09-12 17:46 — TODO-000 ukończone: fundament działa, polska semantyka potwierdzona

**Pierwszy kod w projekcie.** Trzy usługi w Dockerze stoją, a założenie, na
którym stoi cały projekt, jest potwierdzone w działaniu, nie na papierze:

```
zapisano: „Umowa najmu lokalu wymaga aneksu przy zmianie stawki czynszu"
szukano:  „zmiana opłaty za wynajem — jakie dokumenty"
wynik:    podobieństwo 0.743, dopasowanie słów (BM25) 0.0
```

`bm25_score = 0.0` jest tu istotą dowodu: wyszukiwarka nie miała ani jednego
wspólnego słowa, więc trafienie jest wyłącznie semantyczne. Wymiar wektora w
bazie to **1024**, czyli pracuje `bge-m3`, a nie domyślny 384-wymiarowy
`minilm`.

**Powstało:** `docker-compose.yml` (postgres 18 + pgvector, TEI z bge-m3,
mempalace 3.7.0), `docker/mempalace/` (obraz z `psycopg` i oczekiwaniem na
embeddingi), `docker/postgres/init/` (schematy `palace` i `ws`, dwie role z
rozdzielonymi uprawnieniami), `docker/wygeneruj-sekrety.sh`, `.env.example`,
`README.docker.md`, `test/semantyka.sh` z `test/sprawdz_odpowiedz.py`.

**Cztery rzeczy wyszły dopiero w działaniu:**

1. **Postgres 18 zmienił konwencję montowania** — wolumen na
   `/var/lib/postgresql`, nie na `/var/lib/postgresql/data`. Stara ścieżka
   kończy się odmową startu.
2. **Obraz TEI jest distroless** — brak `curl`, `wget`, `nc` i powłoki, więc
   healthcheck Dockera jest tam niewykonalny. Zastąpiony oczekiwaniem w
   entrypoincie `mempalace` i udokumentowanym poleceniem diagnostycznym.
   Pierwotny plan zadania zakładał healthcheck przez `/health` — nie dało się.
3. **Wyszukiwanie degraduje się cicho.** Przy niedostępnym serwerze
   embeddingów MemPalace zwraca **poprawną** odpowiedź JSON-RPC z błędem
   ukrytym w treści narzędzia i pustą listą wyników. Pierwsza wersja testu
   postawiła z tego powodu fałszywą diagnozę. Rozróżnienie trafiło do testu
   i do monitorowania w `docs/05-deployment.md` — alert patrzący tylko na kod
   HTTP tej awarii nie zobaczy.
4. **`bge-m3` potrzebuje ~2–3 GB RAM** po załadowaniu i 1–3 minut na start
   z cache'u. Przy braku pamięci kontener się przeładowuje, a wyszukiwanie
   w tym czasie zwraca puste wyniki.

Zadanie przeniesione do `TODO/DONE/` z pełnym zapisem weryfikacji.

---

## 2026-09-12 17:10 — Lokalny pałac pierwotny, serwer trzyma kopię

Doprecyzowanie kierunku: praca dzieje się lokalnie, serwer dostaje **kopię**.
Wtyczka WS_Memory istnieje właśnie po to, żeby ta kopia powstawała — kto chce
pracować wyłącznie lokalnie, instaluje samo MemPalace i nie zakłada konta.
Przełącznik `auto_publish` schodzi do roli hamulca awaryjnego.

**D-015** dokłada wymaganie, którego wcześniej nie było i które wynika wprost
z tego ujęcia:

> Zapis lokalny nigdy nie czeka na serwer i nigdy nie zawodzi z jego powodu.

- **Lokalna kolejka wyjściowa** (`~/.ws-memory/outbox/`): brak sieci, padnięty
  serwer czy jego aktualizacja nie przerywają pracy; szuflady czekają i
  dopinają się przy następnej okazji. Ponowna wysyłka jest bezpieczna dzięki
  odsiewowi, który już mamy.
- Znacznik przesuwa się **dopiero po potwierdzeniu przez serwer**, więc
  przerwanie w połowie partii niczego nie gubi.
- Skutek szerszy: baza wiedzy przestaje być pojedynczym punktem awarii dla
  codziennej pracy, a aktualizacja serwera nie wymaga okna serwisowego.
- Bez zmian: **nie ma synchronizacji w drugą stronę.** Wiedzę zespołu agent
  czyta na żywo przez `ws_search`; ściąganie jej do lokalnych pałaców
  oznaczałoby dwukierunkową synchronizację, odrzuconą w D-004.
- Reguła nienaruszalna nr 11 (numeracja przesunięta): zapis lokalny nigdy nie
  czeka na serwer.

---

## 2026-09-12 17:08 — Wysyłka na serwer domyślna, tryb ręczny jako wyłącznik

Odwrócone domyślne zachowanie. Wcześniej publikacja była czynnością, którą
trzeba pamiętać; teraz **wszystko, co trafia do lokalnego pałaca, jedzie na
serwer samo**. Powód: baza wiedzy wypełniana tym, co ktoś akurat uznał za warte
kliknięcia, wypełnia się prawie niczym.

**D-014** wprowadza **regułę lądowania**, bez której domyślna wysyłka byłaby
wyciekiem: skrzydło zmapowane → przestrzeń zespołowa, skrzydło niezmapowane →
prywatna przestrzeń właściciela na serwerze. Potwierdzenie człowieka przenosi
się z publikacji na **mapowanie**, bo to ono decyduje o widoczności dla innych.

- Nazwane wprost: **tekst zmielonego kodu trafia na serwer** (jako szuflady).
  Właściwość „kod nie opuszcza laptopa" zmienia się w „kod nie opuszcza serwera
  firmy". Mielenie pozostaje lokalne, więc serwer nadal nie ma dostępu do
  repozytoriów ani kluczy do gita.
- Odsiew powtórzeń: `content_hash` w `memory_entries` z indeksem
  `(space_id, content_hash)`. Gdy nazwa lokalnego skrzydła odpowiada
  przestrzeni zespołowej, wtyczka proponuje mapowanie — wtedy trzy osoby
  mielące to samo repozytorium nie tworzą trzech kopii.
- Nowa tabela `publish_settings` z `auto_publish` domyślnie `true`;
  przełącznik również w `userConfig` wtyczki.
- Filtr sekretów leży teraz na **każdej** ścieżce, nie tylko na świadomie
  uruchomionej — jego testy stają się krytyczne.
- Dobór mocy usługi `embeddings`: serwer liczy wektory dla całego strumienia
  wszystkich maszyn, nie dla wybranych fragmentów.
- `TODO-012` rozszerzone o wysyłkę automatyczną, regułę lądowania, odsiew
  powtórzeń i testy negatywne widoczności.

---

## 2026-09-12 16:55 — Przenośność na inne klienty AI

Pytanie z biura: skoro MemPalace działa nie tylko z Claude, czy struktura
wtyczek nie zablokuje nas później przy Codeksie? Sprawdzono, jak zrobił to
MemPalace — utrzymuje cztery pakowania naraz (`.claude-plugin`,
`.codex-plugin`, `.cursor-plugin`, `.antigravity-plugin`) plus wspólną treść
protokołów w `integrations/shared/`. Codex ma **ten sam kształt `hooks.json`**
co Claude i jeden skrypt przyjmujący nazwę zdarzenia; Cursor nie ma hooków
wcale, tylko `mcp.json` i reguły.

**D-013** — budujemy najpierw dla Claude Code, ale trzy zasady utrzymują
przenośność tanim kosztem:

1. Cała wartość mieszka na serwerze. Gateway to zwykły serwer MCP po HTTP,
   więc Codex, Cursor, Zed czy Antigravity połączą się z nim **bez zmian po
   naszej stronie**, z identycznymi gwarancjami bezpieczeństwa — bo model
   uprawnień nie jest we wtyczce.
2. Treść instrukcji ma jedno źródło (`plugin/shared/`) i jest wystawiona także
   jako **zasoby MCP**. Skutek uboczny: zmiana instrukcji to deploy serwera,
   a nie aktualizacja wtyczki u dwunastu osób.
3. Części nieprzenośne trzymamy minimalne — **jeden** skrypt hooka z argumentem
   zdarzenia zamiast trzech osobnych.

- Reguła nienaruszalna nr 11: nic wartościowego nie mieszka we wtyczce.
- Struktura `plugin/` przebudowana: `shared/` (treść) + `.claude-plugin/`
  (powłoka); `.codex-plugin/` powstanie dopiero, gdy ktoś użyje Codeksa.
- Nie oparto niczego na promptach MCP — nie zweryfikowano, jak klienty je
  wystawiają. Zasoby i opisy narzędzi wystarczają.

---

## 2026-09-12 16:52 — Jedna droga: mielenie wyłącznie lokalne

Konsekwencja hybrydy, doprowadzona do końca. Skoro każdy może mielić u siebie,
druga — serwerowa — droga wnoszenia wiedzy jest zbędna.

Zweryfikowano w dokumentacji Claude Code, że da się to zrobić czysto:

- **`plugin.json` ma pole `dependencies`** — wtyczka WS_Memory deklaruje
  wymaganie wtyczki `mempalace`, więc każdy użytkownik dostaje lokalny pałac,
  instalując jedną rzecz.
- **Marketplace obsługuje `source: {"type": "command"}`** — polecenie przed
  instalacją, czyli miejsce na `mempalace[extract]` i pierwsze `init`.
- **`userConfig`** — adres i token pytane przy włączeniu wtyczki, token
  `sensitive`, dostępny jako `${user_config.KEY}` w MCP i
  `CLAUDE_PLUGIN_OPTION_*` w hookach. Zastępuje ręczne zmienne środowiskowe.

**D-012** — serwer nie mieli niczego. Znika: klonowanie repozytoriów, klucze
do gita, harmonogram nocny, wariant `extract` w obrazie serwera, endpoint
przyjmujący transkrypty, tabele `mining_jobs` i `session_uploads`, wolumen na
transkrypty oraz cała wysyłka surowych rozmów z D-006. Prywatność przestaje
być ustawieniem, a staje się właściwością architektury: **nie ma ścieżki,
którą surowa rozmowa wychodzi na serwer**.

- `TODO-010` **anulowane**; plik zostaje na miejscu ze statusem i wyjaśnieniem,
  żeby numeracja się nie przesunęła, a analiza pozostała dostępna. Jego zakres
  przejęły `TODO-009` i `TODO-012`.
- Na serwerze zostają `mempalace` (wyszukiwanie i zapis publikowanych szuflad)
  oraz `embeddings` — obie usługi nadal niezbędne, żadna nie mieli.
- **Znane ograniczenie:** osoba bez Claude Code nie wniesie PDF-a do bazy;
  zostaje jej wiki. Obejście opisane w D-012.

---

## 2026-09-12 16:39 — Hybryda: lokalny pałac plus wspólna baza

Na pytanie „czy ktoś może mieć MemPalace lokalnie i zapisywać do wspólnego"
przeprowadzono rozpoznanie podsystemu replikacji. **Ustalono, że replikacji
pałac↔pałac w 3.7.0 nie ma**: `logstream sync` synchronizuje zdarzenia
koordynacyjne i artefakty (w `logsync.py` nie ma ani jednego odwołania do
szuflad), `mempalace sync` to sprzątanie po usuniętych plikach, a
`replica.json` i `patch_submit` to fundament pod przyszły mesh.

Mostek zbudowano więc z tego, co jest — i wyszedł lepszy niż wersja
w pełni serwerowa:

- **D-010** — hybryda. Deweloper może mieć własny lokalny MemPalace (własne
  `init` i `mine`, **kod nie opuszcza laptopa**) i publikować wybraną wiedzę
  do wspólnej bazy **przez API**. Agent ma dwa serwery MCP: lokalny i wspólny.
  Publikacja selektywna (`/ws-publish`) oraz **lustro** — cykliczne mapowanie
  skrzydła na przestrzeń.
- Zabezpieczenia lustrzenia, bo lustro pracuje bez nadzoru: pierwszy przebieg
  jest podglądem wymagającym potwierdzenia, wykluczenia pokoi, filtr sekretów
  po obu stronach, partie z możliwością wycofania, przyrostowość.
- **Odrzucono** wariant z `MEMPALACE_PGVECTOR_DSN` na centralną bazę: prostszy,
  ale omija token, role i audyt — czyli unieważnia powód istnienia WS_Memory.
  Zapisano jako regułę nienaruszalną nr 10.
- **D-011** — `MEMPALACE_ENTITY_LANGUAGES=pl,en` i `init --no-llm`. Wykrywanie
  encji działa domyślnie po angielsku; to ta sama cicha wada co domyślny
  `minilm`, tylko dotyczy grafu wiedzy.
- **D-003 uzupełniona**: wymóg jednego modelu embeddingów dotyczy wyłącznie
  serwera. Hybryda współdzieli tekst, nie wektory, więc laptopy mogą mieć
  dowolny model.
- **D-006 uzupełniona**: wysyłka transkryptów to droga domyślna, nie jedyna —
  kto ma lokalny pałac, mieli rozmowy u siebie.
- Model danych: `mirrors`, `publish_batches`, oraz `source_replica` +
  `source_drawer_id` w `memory_entries` z unikalnością na parze źródłowej
  (idempotencja publikacji).
- Nowe zadanie **TODO-012** — mostek hybrydowy.
- Nagłówki zadań ujednolicone do formatu **`TODO-NNN`**, żeby nie myliły się
  z numerami decyzji (`D-NNN`).
- Uzupełniono `tags` w nagłówkach YAML wszystkich 25 dokumentów.

---

## 2026-09-12 16:03 — Spec projektowy i dwanaście zadań wdrożeniowych

- `docs/superpowers/specs/2026-09-12-ws-memory-design.md` — spec utrwalający
  decyzje, kryteria ukończenia projektu i ryzyka wraz z ich obsługą.
- `TODO/000` … `TODO/011` — **dwanaście** zadań, każde z sekcjami Powód,
  Analiza, Rozwiązanie i sprawdzalnymi Kryteriami ukończenia.
- `TODO/README.md` — zasady prowadzenia zadań, przenoszenia do `DONE/`
  i graf zależności między zadaniami.
- `.gitignore` — wykluczenia dla Symfony, Node, Dockera i sekretów.

Kolejność wynika z jednej zasady: zadanie `000` nie zawiera ani linii Symfony,
bo najpierw trzeba udowodnić, że polskie zapytanie znajduje polską treść przez
centralny serwer embeddingów. Budowanie interfejsu nad nieudowodnionym
fundamentem byłoby marnotrawstwem.

---

## 2026-09-12 15:57 — Rozdzielenie backendu i frontendu

Na wniosek zmieniono warstwę prezentacji: zamiast monolitu z Twigiem — czyste
API Symfony plus osobna aplikacja Vue 3. Wzorzec przeniesiony z Precision
Telemed 2.0 (sprawdzony w zespole), potwierdzony wyszukiwaniem w pałacu.

- **D-008** — rozdzielenie backendu i frontendu; backend wystawia REST `/api`
  i MCP `/mcp` nad wspólnymi serwisami domenowymi, frontend to osobna
  aplikacja Vue 3 + Vite 7 + Nuxt UI 4 + Tailwind 4 + Pinia + Zod.
  `nginx` trzyma oba na jednym origin, więc przeglądarka nie dotyka CORS-a.
- **D-009** — edytor wiki na CodeMirror 6, **nie** WYSIWYG. Uzasadnienie:
  dokumenty krążą między ludźmi i AI, a każdy obieg przez model dokumentu
  WYSIWYG gubi to, czego ten model nie obsługuje. Markdown jako jedyna
  reprezentacja usuwa tę klasę błędów.
- **D-001** oznaczona jako zmieniona w zakresie warstwy prezentacji.
- Dodano regułę nienaruszalną nr 9: backend nie renderuje interfejsu,
  frontend nie zna bazy; jedyny kontrakt to OpenAPI.
- Liczba usług w Compose: 6 → 7 (doszedł `frontend`); `app` przemianowany
  na `backend`.
- Nowy dokument `docs/07-frontend.md` — stack, struktura katalogów, ekrany.
- Dopisano do `AGENTS.md` konwencje gita: jeden commit = jedna zamknięta myśl,
  commit obejmuje kod razem z dokumentacją i wpisem w CHANGELOG.

---

## 2026-09-12 15:57 — Projekt zatwierdzony, dokumentacja założycielska

Etap projektowania zakończony. Kodu jeszcze nie ma.

**Rozpoznanie MemPalace 3.7.0 (jako zależności):**
- Ustalono, że `mempalace serve` wystawia HTTP MCP pod `POST /mcp` z **jednym
  wspólnym tokenem** dla wszystkich klientów — brak tożsamości per użytkownik.
  To wyznaczyło właściwy zakres WS_Memory.
- Odkryto, że MemPalace ma pełnoprawny backend **pgvector** z izolacją przez
  namespace i bezpieczeństwem przy wielu pisarzach. Pozwoliło to trzymać pałac
  i dane aplikacji w jednym Postgresie.
- Ustalono, że mechanizm hub-forward działa wyłącznie w obrębie jednej maszyny
  (wykrywanie huba przez plik w katalogu pałaca) — nie nadaje się do pracy
  zdalnej zespołu.
- Ustalono, że domyślny model embeddingów `minilm` jest trenowany **tylko na
  angielskim**, co dyskwalifikuje go dla bazy pisanej po polsku.

**Podjęte decyzje** (szczegóły w `docs/06-decyzje.md`):
- D-001 — Symfony 8 + MemPalace jako sidecar
- D-002 — PostgreSQL 18 + pgvector zamiast firmowego MariaDB
- D-003 — centralny serwer embeddingów, model `BAAI/bge-m3`
- D-004 — Postgres źródłem prawdy dla wiki, pałac warstwą wyszukiwania
- D-005 — agent zapisuje bez bramki; wersjonowanie jako siatka bezpieczeństwa
- D-006 — zamknięta sieć; hooki wysyłają transkrypty przez HTTPS
- D-007 — kurowany zestaw narzędzi MCP zamiast przepuszczania 36 narzędzi

**Dodane pliki:**
- `AGENTS.md` — kontrakt projektu: reguły nienaruszalne, stack, workflow, git
- `README.md` — opis projektu i spis dokumentacji
- `CHANGELOG.md` — ten plik
- `docs/01-architektura.md` … `docs/06-decyzje.md` — dokumentacja działania
