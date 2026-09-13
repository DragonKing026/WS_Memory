---
tags: [ws-memory, dokumentacja, backend, symfony, warstwy, api]
---

# Backend — co do czego służy

Stan: **działa** (2026-09-12). Zaimplementowane: zdrowie, zaproszenia, konta,
logowanie, przestrzenie i role, audyt, dostęp do pamięci (szukanie, zapis, graf
wiedzy, dziennik), gateway MCP z tokenami agentów oraz **wiki z rewizjami,
cofaniem i kolejką propozycji**. Brakuje: frontend (TODO-006…008), publikacja
z lokalnych pałaców (TODO-012).

Ten dokument opisuje **każdy element backendu i powód jego istnienia**. Jeśli
nie wiesz, gdzie dopisać nową rzecz — zacznij tutaj.

## Warstwy i kierunek zależności

```
Presentation/    ← wejścia: HTTP i konsola. Tłumaczą żądanie na wywołanie.
Application/     ← przypadki użycia: „wystaw zaproszenie", „przyjmij je".
Domain/          ← reguły. BEZ Symfony, BEZ Doctrine, BEZ MemPalace.
Infrastructure/  ← implementacje portów: Doctrine, HTTP, MemPalace.
Entity/          ← model trwałości (Doctrine). Odwzorowanie tabel, nie reguły.
```

Zależności idą **wyłącznie do środka**. `Domain` nie importuje niczego z
pozostałych warstw — dzięki temu reguły uprawnień da się przetestować bez bazy
i bez kontenera, a testy negatywne chodzą przy każdym commicie w ułamku sekundy.

Sprawdzian: *gdyby jutro trzeba było wymienić Doctrine albo MemPalace, ile
plików w `Domain/` trzeba tknąć?* Odpowiedź ma brzmieć „zero".

**Dlaczego `Entity/` jest osobno, a nie w `Domain/`:** encje Doctrine noszą
atrybuty mapowania, czyli wiedzą o bazie. Trzymanie ich w `Domain/` zabrudziłoby
warstwę, która ma być czysta. Trzymanie osobnego modelu domenowego obok encji
oznaczałoby ręczne przepisywanie w obie strony — koszt nieproporcjonalny do
skali tego projektu. Kompromis: encje są **modelem trwałości**, a reguły
mieszkają w `Domain/`.

## Mapa plików

### `Domain/` — reguły, bez frameworka

| Plik | Rola |
|---|---|
| `Identity/Actor.php` | Kto wykonuje operację: człowiek albo token agenta. **Jeden typ dla obu**, bo REST i MCP wołają te same serwisy — gdyby tożsamość była modelowana dwa razy, obie powierzchnie kiedyś różniłyby się w uprawnieniach, a różnica ujawniłaby się jako wyciek, nie jako błąd. |
| `Space/SpaceId.php` | Identyfikator przestrzeni jako obiekt wartości. String da się pomylić z innym stringiem; ten typ nie. |
| `Space/SpaceRole.php` | Rola w przestrzeni: `reader` / `writer` / `admin`. Uporządkowana siłą, więc „co najmniej piszący" to porównanie, a nie lista przypadków, którą ktoś zapomni rozszerzyć przy czwartej roli. |
| `Space/SpaceMembershipRepository.php` | **Port**: skąd biorą się członkostwa. Zadeklarowany w domenie, zaimplementowany w infrastrukturze. |
| `Space/SpaceAccessResolver.php` | **Jedyne miejsce liczące uprawnienia.** Każdy odczyt i zapis przechodzi tędy. Nie ma cache — odebrana rola przestaje działać natychmiast, nie po wygaśnięciu czegokolwiek. |
| `Space/SpaceCatalog.php` | **Port**: czym jest przestrzeń (skrzydło w pałacu, która jest prywatna) — w odróżnieniu od tego, kto ma do niej prawo. Osobno od członkostw, bo jedno czyta się na ścieżce uprawnień każdego żądania, a drugie dopiero wtedy, gdy przestrzeń już jest dozwolona. |
| `Memory/PalaceWing.php` | Nazwa skrzydła — **jedyna oś, po której da się filtrować pamięć**. Typ nie przyjmuje wartości pustej, więc zapytanie bez filtra przestrzeni (reguła 3) jest niewyrażalne, a nie tylko zabronione. Zna też zasadę kwalifikowania nazw encji w grafie (D-021). |
| `Memory/DrawerId.php` | Identyfikator treści w pałacu. `forFact()` wylicza stabilny odcisk dla faktu grafu, bo pałac nie zwraca dla faktów żadnego identyfikatora — wyliczany w jednym miejscu, bo zapis i odczyt muszą się na nim zgadzać co do znaku. |
| `Memory/MemoryKind.php` | Klasa wiedzy (`note` / `document` / `diary` / `kg_fact` / `transcript`) i **jedyne miejsce mapujące ją na pokój pałaca**. Rozjazd między zapisem a odczytem nie dałby błędu, tylko treść zapisaną tam, gdzie nikt jej nie szuka. |
| `Memory/MemoryQuery.php` | Czego szukamy — i celowo **nie** gdzie. Obiekt, który mógłby nieść skrzydło, pozwoliłby podać je z ciała żądania. |
| `Memory/MemoryFragment.php` | Jedna treść wracająca z pamięci. `space` puste znaczy „jeszcze nieuwierzytelnione" — serwis nie wydaje fragmentu w tym stanie. |
| `Memory/KnowledgeFact.php` | Trójka grafu wiedzy z okresem ważności. Odcisk nie zależy od okresu: przedłużenie ważności nie może zmieniać tożsamości faktu. |
| `Memory/MemoryStore.php` | **Port**: silnik pamięci. `search()` przyjmuje `PalaceWing` jako pierwszy, nieopcjonalny argument — stąd gwarancja filtra. Jedno skrzydło na wywołanie, bo pałac filtruje po jednym. |
| `Memory/MemoryRegistry.php` | **Port**: nasz rejestr treści w pałacu. Wyznacza też granicę transakcji — zapis jest realny, gdy jest zaksięgowany (D-020). |
| `Memory/MemoryWrite.php` | Wiersz do zaksięgowania. Obiekt parametrów, bo lista będzie rosła przy publikacji z lokalnych pałaców (TODO-012). Tytuł i skrót treści wylicza w jednym miejscu. |
| `Memory/SearchMode.php` | Semantycznie czy leksykalnie. Nie „lepiej i gorzej", tylko dwa różne pytania — a że pałac trybu leksykalnego nie ma (D-029), ten typ jest zarazem granicą między dwoma źródłami danych o różnym pokryciu. |
| `Memory/LexicalIndex.php` | **Port**: szukanie dokładnych słów w tekście, który trzymamy u siebie. Świadomie **nie** drugi `MemoryStore`: odpowiada z naszych tabel i widzi mniej — pełną treść tylko dokumentów. Lista przestrzeni jest argumentem obowiązkowym, tak jak skrzydło w `MemoryStore`. |
| `Memory/MemoryBrowser.php` | **Port**: co jest w pamięci, od najnowszego — bez zadawania pytania. Osobny od wyszukiwania, bo odpowiada na co innego: nie „czy ktoś wie o X”, tylko „co ostatnio wpada do pamięci”. Po tym poznaje się agenta zapisującego bzdury. Odpowiada wyłącznie z naszego rejestru; pytanie pałaca oznaczałoby pobieranie treści po to, żeby pokazać tytuł. |
| `Memory/MemoryEntryView.php` | Wiersz surowej pamięci w listowaniu. Świadomie **nie** `SearchHit`: wynik wyszukiwania istnieje w odpowiedzi na pytanie i niesie trafność, a listowanie na żadne pytanie nie odpowiada. Wymyślona liczba na ekranie jest gorsza niż jej brak. |
| `Memory/EntryFacts.php` | Co wiemy o szufladzie, czego pałac nie wie: kto pisał (człowiek czy agent) i czy ktoś to sprawdził. W bazie pisanej w połowie przez agentów to różnica między wynikiem, który da się ocenić, a takim, który trzeba wziąć na wiarę. |
| `Search/SearchHit.php` | Jeden wynik tak, jak czyta go człowiek. Osobny typ od `MemoryFragment`, bo niesie autorstwo i weryfikację, a jego identyfikator jest **opcjonalny**: dokument jest znajdowalny od zapisu, a szufladę zakłada pracownik chwilę później. `score` porównywalny wyłącznie w obrębie jednego trybu — skale są różne. |
| `Search/Snippet.php`, `Search/SnippetPart.php` | Fragment z zaznaczonymi trafieniami **jako struktura, nie jako znaczniki**. Treść pisana przez ludzi i agentów może zawierać dowolny HTML; string ze znacznikami wyrenderowany w przeglądarce to trwały XSS. W strukturę nie da się wstrzyknąć. |
| `Memory/MemoryUnavailable.php` | „Nie mogłem sprawdzić" — odrębne od „nic nie znalazłem". Agent, któremu powiemy „nic nie ma", zapisze tę wiedzę drugi raz obok kopii, której nie zobaczył. |
| `Document/DocumentSlug.php` | Adres dokumentu jako obiekt wartości. **Odrzuca** niepoprawny, nie sprząta go: kto linkuje do „Umowy Najmu" i dostanie dokument pod „umowy-najmu", ma zepsuty odsyłacz, którego nie widzi. `fromTitle()` daje podpowiedź, gdy ktoś o nią prosi. |
| `Document/DocumentStatus.php` | `draft` / `published`. **Nie** jest bramką przeglądu — dokument agenta jest widoczny od razu (D-005); szkic to stan człowieka, który jeszcze nie skończył. |
| `Document/ProposalStatus.php` | Stan wpisu w kolejce. Kolejka działa tylko tam, gdzie przestrzeń o nią prosi. |
| `Document/RevisionDiff.php` | Różnica dwóch rewizji, liczona na żądanie (LCS po wierszach). Nie jest przechowywana, bo rewizje trzymają pełne treści — zapisany diff byłby drugą reprezentacją tego samego faktu, a dwie reprezentacje jednego faktu kiedyś się rozejdą. Limit 5000 wierszy: tablica LCS jest O(n·m). |
| `Memory/StoredMemory.php` | Gdzie wylądował zapis: szuflada, przestrzeń, klasa wiedzy. Zapis zwraca to, a nie sam identyfikator, bo wołający, który nie wskazał przestrzeni, inaczej nie wie, gdzie trafiła jego treść (reguła 6). |
| `Identity/AgentIdentity.php` | Kim okazuje się przedstawiony token: aktor plus etykieta. Dwie odpowiedzi naraz, bo żądanie MCP potrzebuje obu, a jedno zapytanie jest tańsze niż dwa na ścieżce każdego wywołania. |
| `Identity/AgentTokenDirectory.php` | **Port**: zamiana sekretu na tożsamość i odnotowanie użycia. W domenie, bo obie połowy są regułami: unieważniony token musi przestać działać przy **następnym** wywołaniu, a każde wywołanie musi zostawić ślad. |
| `Memory/MemoryAccessDenied.php` | Odmowa **zapisu**. Odczyt poza uprawnieniami odpowiada pusto, bo komunikat „nie masz dostępu do Kadr" sam jest wyciekiem. |
| `Audit/AuditTrail.php` | **Port**: zapis, kto co zrobił. W domenie, bo audyt jest regułą tego systemu, nie wygodą infrastruktury (D-016). |
| `Health/*` | Port `HealthProbe` + `HealthChecker` składający raport. Monitorowanie kolejnej zależności to dodanie klasy, nie edycja kontrolera. |

### `Application/` — przypadki użycia

| Plik | Rola |
|---|---|
| `Invitation/IssueInvitation.php` | Wystawia zaproszenie. Token losowy, w bazie **wyłącznie skrót**; jawna wartość wraca raz i nigdzie nie jest zapisywana. |
| `Invitation/AcceptInvitation.php` | Zamienia zaproszenie w konto **razem z prywatną przestrzenią, w jednej transakcji**. Zapis bez wskazanej przestrzeni ląduje właśnie tam (reguła 6), więc konto bez niej wywracałoby pierwszy zapis agenta. |
| `Invitation/IssuedInvitation.php` | Obiekt wyniku z jawnym tokenem. **Nie jest usługą** — wykluczony z kontenera. |
| `AgentToken/IssueAgentToken.php` | Wystawia poświadczenie agenta. Sekret losowy, w bazie skrót, jawna wartość raz. Sprawdza żądany zakres wobec **bieżących** uprawnień właściciela — nie dlatego, że to egzekwuje regułę 4 (to robi resolver na każdym żądaniu), ale żeby pomyłka zgłosiła się teraz, człowiekowi, a nie zamieniła w token, który nic nie czyta i nie da się tego zdiagnozować od strony agenta. |
| `AgentToken/RevokeAgentToken.php` | Unieważnia — natychmiast i tylko własny, również dla administratora globalnego. Cudzy token odpowiada identycznie jak nieistniejący, inaczej dałoby się enumerować cudze poświadczenia. |
| `AgentToken/IssuedAgentToken.php` | Wynik z jawnym tokenem i gotowym `claude mcp add`. **Nie jest usługą.** |
| `Document/DocumentService.php` | **Jedyne wejście do wiki.** Dokument w przestrzeni bez prawa odczytu jest NIEZNALEZIONY, nigdy zabroniony (reguła 7). `verify()` przyjmuje `User`, nie `Actor` — agenta nie da się przekazać (D-005). `rollback()` **dopisuje** rewizję o starej treści; historia nigdy się nie skraca. Publikacja jest wysyłana do kolejki po `flush`, nie przed: zlecenie dla rewizji, która się nie zapisała, kazałoby pracownikowi opublikować treść, której nikt nie przeczyta. |
| `Document/ProposalService.php` | Kolejka propozycji. Złożenie wymaga roli **czytającego** (D-026), przyjęcie idzie **przez `DocumentService`** — druga droga do wiki kiedyś ominęłaby sprawdzenie uprawnień, publikację albo wpis w audycie. |
| `Document/PublishDocument.php` | Zlecenie „opublikuj rewizję N". Niesie numer rewizji i to właśnie czyni je bezpiecznym przy dowolnej kolejności dostarczenia. |
| `Document/PublishDocumentHandler.php` | Idempotentny i odporny na kolejność: zlecenie starsze niż bieżąca rewizja jest **porzucane** (D-025). Autorem kopii w pałacu jest autor rewizji, nie „pracownik" — dla agenta właściciela tokena ustala `AgentTokenDirectory::ownerOf()`. |
| `Document/DocumentNotFound.php`, `Document/ProposalRequired.php` | Jeden wyjątek na „nie ma" i „nie twoje"; drugi **nazywa drogę dalej**, bo błąd mówiący tylko „odmowa" kazałby agentowi powtarzać to samo wywołanie. |
| `Memory/MemoryService.php` | **Jedyne wejście do pamięci.** REST i MCP wołają tę klasę i nic pod nią, więc wybór drzwi nie zmienia odpowiedzi (D-008). Tu mieszka rozsyłanie zapytania po skrzydłach, przerankowanie wyników, druga warstwa filtrowania (D-019), domyślna przestrzeń prywatna (reguła 6) i etykieta autora. Nic powyżej tej warstwy nie ma prawa trzymać `MemoryStore`. |
| `Search/SearchService.php` | Wyszukiwanie tak, jak czyta je człowiek: jedno wywołanie, dwa tryby. Uprawnień **nie liczy** — woła o nie `MemoryService` w obu trybach, żeby nie istniało drugie miejsce wyznaczające dozwolone przestrzenie. Dokłada to, co widzi tylko człowiek: autora, weryfikację i **próg słabych wyników**, liczony względem najlepszego trafienia w tej odpowiedzi, bo stała nie rozdziela zmierzonych przedziałów. |

### `Infrastructure/` — adaptery portów

| Plik | Rola |
|---|---|
| `Doctrine/DoctrineSpaceMembershipRepository.php` | Czyta członkostwa **przez DBAL, nie przez ORM**. To zapytanie leży na ścieżce uprawnień każdego żądania, a hydracja encji wstawiłaby identity map między odebranie roli a jego skutek — czyli dokładnie ten cache, którego resolver obiecuje nie mieć. |
| `Doctrine/DoctrineAuditTrail.php` | Zapisuje wpisy audytu. IP i przeglądarkę bierze z bieżącego żądania, nie z parametrów — gdyby były parametrem, część wywołań by o nich zapomniała, a wpis bez pochodzenia odpowiada na połowę pytania, po co istnieje. |
| `Doctrine/DatabaseHealthProbe.php` | Sonda: czy baza odpowiada. |
| `Doctrine/DoctrineMemoryRegistry.php` | Rejestr na DBAL. Przestrzeń rozwiązuje **wewnątrz INSERT-a** po slugu — osobny SELECT otwierałby okno, w którym przestrzeń znika między sprawdzeniem a zapisem. Trzyma też granicę transakcji. |
| `Doctrine/DoctrineSpaceCatalog.php` | Skrzydło przestrzeni i przestrzeń prywatna użytkownika. Prywatną sprawdza **po konwencji slugu ORAZ po fladze** — przestrzeń nazwana ręcznie `priv_<uuid>` bez flagi nie może stać się miejscem, gdzie lądują cudze zapisy. |
| `Doctrine/DoctrineMemoryBrowser.php` | Rejestr czytany jako lista. Porządek `(space_id, created_at)` — indeks z Version20260912000003 — czytany wstecz; btree skanuje w tył tak samo tanio, dlatego kopii malejącej nie ma. |
| `Doctrine/DoctrineLexicalIndex.php` | Szukanie dokładnych słów na pełnym tekście PostgreSQL. Dwie rzeczy są tu nieoczywiste i obie zmierzone: zapytanie tokenizuje **ten sam parser** co treść (`simple` czyta `D-029` jako `d` i `-029`, więc ręczna tokenizacja nie znajduje niczego), a oba zbiory trafień startują **od predykatu tekstowego** — napisane „od dokumentów" zapytanie nie używa indeksu GIN i czyta wszystkie rewizje: 157 ms wobec 2 ms na 10 tys. dokumentów. |
| `MemPalace/MemPalaceClient.php` | Cienki klient JSON-RPC. Dwie rzeczy nieoczywiste: **ponawia wyłącznie odczyty** (powtórzony zapis zakłada drugą szufladę) i **nigdy nie wpuszcza tokena do komunikatu błędu** — tak sekrety najczęściej wyciekają. |
| `MemPalace/CallOutcome.php` | Wynik jednego wywołania narzędzia. Istnieje, bo MemPalace zgłasza awarię **wewnątrz** payloadu: HTTP 200, koperta bez błędu, a przyczyna obok pustej listy wyników. Rozróżnia też „nie ma takiej szuflady" od awarii. |
| `MemPalace/McpMemoryStore.php` | Adapter portu pamięci — **jedyne miejsce znające nazwy narzędzi MemPalace** i kształt ich odpowiedzi. Aktualizacja pałaca (D-001) dotyka tego pliku i żadnego innego. |
| `MemPalace/MemPalaceUnavailable.php` | Jedna awaria na wiele przyczyn: odmowa połączenia, limit czasu, HTTP 500, niezrozumiała koperta, błąd narzędzia. Wołający ma w każdym z tych przypadków te same możliwości. |
| `Doctrine/DoctrineAgentTokenDirectory.php` | Tokeny na DBAL. Unieważnienie, wygaśnięcie **i aktywność właściciela** sprawdzane w JEDNYM zapytaniu — nie ma okna, w którym trzy osobne sprawdzenia mogłyby się nie zgadzać, ani ścieżki, na której ktoś dopisze wołającego zapominającego o trzecim. |
| `Doctrine/DoctrineAuditTrail.php` → patrz wyżej | Zapisuje **od razu**, przez DBAL. Wcześniej robił `persist()` i czekał na cudzy `flush` — a wywołanie MCP nie zmienia encji, więc cała aktywność agentów przechodziła bez śladu (D-024). |
| `Security/AgentTokenAuthenticator.php` | Uwierzytelnia `/mcp` tokenem agenta. Rozwiązaną tożsamość kładzie na żądaniu jako atrybut: token Symfony niesie właściciela, a właściciel to nie cała odpowiedź — agent to właściciel **zawężony**, a zawężenie mieszka na poświadczeniu. |
| `MemPalace/MemPalaceHealthProbe.php` | Sonda: czy pamięć odpowiada. Pyta `/healthz` z krótkim limitem czasu — zawieszony healthcheck jest gorszy od negatywnego. |
| `Security/ActiveAccountChecker.php` | Odrzuca konta nieaktywne — przy logowaniu **i przy każdym kolejnym żądaniu**. JWT jest ważny kryptograficznie aż do wygaśnięcia, więc bez tego zwolniona osoba czytałaby bazę jeszcze przez cały czas życia ostatniego tokena. |
| `Security/LoginAuditSubscriber.php` | Audyt logowań. Wisi na zdarzeniach bezpieczeństwa, bo kontroler logowania **nigdy się nie wykonuje** — firewall odpowiada pierwszy. |

### `Presentation/` — wejścia

| Trasa / komenda | Plik | Uwaga |
|---|---|---|
| `GET /api/health` | `Api/HealthController.php` | Bez uwierzytelniania: monitoring nie ma tokena i mieć nie powinien. `200` gdy zdrowy, `503` gdy nie — Docker czyta kod, nie treść. |
| `POST /api/login` | `Api/LoginController.php` | **Ciało nigdy się nie wykonuje.** Trasa istnieje, bo Symfony musi rozwiązać `check_path`; odpowiada firewall `json_login`. |
| `GET /api/me` | `Api/MeController.php` | Wejście frontendu do modelu uprawnień. Listę przestrzeni bierze z `SpaceAccessResolver`, nie z własnego zapytania. |
| `GET /api/spaces`, `GET /api/spaces/{slug}` | `Api/SpaceController.php` | Przestrzeń poza uprawnieniami odpowiada **bajt w bajt** tak samo jak nieistniejąca. |
| `POST /api/spaces`, `POST /api/spaces/{slug}/members` | `Api/SpaceAdministrationController.php` | Tworzenie przestrzeni i nadawanie ról. Twórca od razu zostaje administratorem; prefiks `priv_` zarezerwowany; przestrzeni prywatnej nie da się udostępnić. |
| `POST /api/invitations/accept` | `Api/AcceptInvitationController.php` | Publiczna z konieczności — wołający nie ma jeszcze konta. Polityka haseł egzekwowana tutaj, nie w przeglądarce. |
| `GET /api/memory` | `Api/MemoryController.php` | Surowa pamięć do przeglądania, osobno od wiki: wiki to, co zespół **postanowił** zapisać, a to — co zostało **zauważone**. Zmieszane w jednej liście, drugie przykryłoby pierwsze. Stronicowane od początku: rejestr rośnie najszybciej w całym systemie. |
| `GET /api/search` | `Api/SearchController.php` | Oba tryby, filtry, i dwie rzeczy, których zwykła lista wyników by nie zrobiła: **słabe trafienia jadą osobno** (semantyka zawsze coś zwraca, tylko coraz gorszego) i odpowiedź **mówi o własnym pokryciu** — tryb leksykalny przyznaje, że pełną treść przeszukuje tylko w dokumentach. |
| `GET /api/spaces/{s}/documents`, `GET/PUT .../{slug}`, `.../history`, `.../diff`, `.../rollback`, `.../verify`, `.../archive` | `Api/DocumentController.php` | Trasy używają `{slug<.+>}`, bo adres może zawierać ukośnik — bez tego „umowy/najem" byłoby nieosiągalne. Mapowanie odmów na HTTP jest w jednym miejscu, bo tam błąd zamienia się w ujawnienie. |
| `GET/POST /api/spaces/{s}/proposals`, `POST /api/proposals/{id}/accept`, `/reject` | `Api/ProposalController.php` | Przegląd jest czynnością człowieka, więc nie ma odpowiednika MCP (D-005). |
| `GET/POST /api/agent-tokens`, `DELETE /api/agent-tokens/{id}` | `Api/AgentTokenController.php` | Wyłącznie **własne** tokeny, również dla administratora globalnego (D-016). Jawna wartość w jednej odpowiedzi — tej, która token utworzyła. |
| `ws:user:invite` | `Console/InviteUserCommand.php` | Jedyna droga do pierwszego konta. Wypisuje link, bo pierwsze zaproszenie powstaje zwykle przed konfiguracją poczty. |
| `ws:agent:token` | `Console/IssueAgentTokenCommand.php` | Jedyna droga do podłączenia agenta, dopóki nie ma ekranów (TODO-008). Wypisuje gotowe `claude mcp add`, bo alternatywą jest odtwarzanie polecenia z dokumentacji i mylenie nagłówka. |

### `Presentation/Mcp/` — gateway dla agentów

| Plik | Rola |
|---|---|
| `McpController.php` | `POST /mcp`. Wyłącznie HTTP: ciało, kod odpowiedzi, odnotowanie użycia tokena i **limit tempa**. Limit jest tutaj, a nie w łańcuchu dekoratorów, bo jest per token i per żądanie — dekorator musiałby usłyszeć to samo siedem razy. |
| `McpServer.php` | Strona JSON-RPC: `initialize`, `tools/list`, `tools/call`, `ping`, notyfikacje. Tłumaczy każdą awarię na kod z `docs/03`. Testowalny przez podanie tablicy. |
| `McpTool.php` | **Port narzędzia.** Zestaw narzędzi JEST granicą uprawnień (D-007). Dodanie narzędzia to dodanie klasy — tag zbiera je do rejestru. **Żadne nie ma parametru autora**, a test przechodzi wszystkie schematy, żeby tak zostało. |
| `McpToolRegistry.php` | Zbiera narzędzia po tagu i owija każde w audyt **na wejściu**. Cały łańcuch wokół wywołania widać w jednej klasie, zamiast szukać dekoratorów po kontenerze. Odrzuca dwie nazwy takie same i nazwę bez przedrostka `ws_`. |
| `AuditedTool.php` + `AuditedToolFactory.php` | Dekorator: każde wywołanie zostawia ślad, udane i nieudane. Celowo nakłada się na wpis z `MemoryService` — ten mówi, które **narzędzie** wywołał który **token**, i istnieje też dla wywołań, które nigdy nie docierają do pamięci albo padają przed nią. |
| `ToolArguments.php` | Typowany dostęp do tego, co przysłał agent. Najważniejsza metoda to `rejectUnknown()`: nieznany parametr jest **błędem**, bo parametrem, który agent wymyśli najczęściej, jest `wing` — a zignorowany znaczy, że agent uwierzy, iż zawęził wyszukiwanie. |
| `McpError.php` | Kody JSON-RPC, nasze powyżej `-32000`. Czego tu **nie ma**: kodu „brak dostępu do przestrzeni" — odczyt poza uprawnieniami zwraca pusty wynik (reguła 7). |
| `Tool/StatusTool.php` | `ws_status` — kim jest token, co widzi, **gdzie trafi zapis bez wskazanej przestrzeni**. Liczby z naszego rejestru, nigdy z pałaca: orientacja agenta nie może kosztować zapytania semantycznego. |
| `Tool/SearchTool.php` | `ws_search`. Schemat nie ma żadnego sposobu wskazania skrzydła; `spaces` może tylko zawężać. |
| `Tool/GetTool.php` | `ws_get`. `found: false` dla treści zabronionej **i** nieistniejącej. |
| `Tool/KgQueryTool.php` | `ws_kg_query`. Nazwy encji kwalifikowane skrzydłem w magazynie, nagie tutaj (D-021). |
| `Tool/RememberTool.php` | `ws_remember`. Bez parametru autora **i bez `kind`** — patrz `docs/03`. |
| `Tool/KgAddTool.php` | `ws_kg_add`. Fakt należy do jednej przestrzeni i nie jest widoczny z innych; opis narzędzia mówi to wprost, żeby agent nie zapisywał go dwa razy. |
| `Tool/DocListTool.php` | `ws_doc_list`. Osobne od `ws_search`, bo pytania są inne: „co wiemy o X" a „jakie są dokumenty". Każdy wiersz niesie `verified` i `authored_by_ai`. |
| `Tool/DocReadTool.php` | `ws_doc_read`. `found: false` dla dokumentu poza uprawnieniami i nieistniejącego. |
| `Tool/DocWriteTool.php` | `ws_doc_write`. Pisze wprost (D-005); w przestrzeni z kolejką odpowiada `-32004` i **nazywa** `ws_propose`. |
| `Tool/ProposeTool.php` | `ws_propose`. Wymaga roli czytającego; odpowiada `in_wiki: false`, żeby agent nie zameldował publikacji, której nie było. |
| `Tool/DiaryWriteTool.php` | `ws_diary_write`. Domyślnie prywatnie — notatki z sesji to treść, której ludzie najbardziej oczekują jako swojej. |

### `Entity/` — model trwałości

| Encja | Uwagi konstrukcyjne |
|---|---|
| `User` | Konta się **dezaktywuje, nie usuwa** — rewizje i wpisy audytu wskazują autora, a historia bez autora przestaje być dowodem. `ROLE_USER` jest domyślna i nieprzechowywana. |
| `Space` | `Space::privateFor()` tworzy przestrzeń prywatną `priv_<uuid>` — konwencję slugu trzyma `SpaceId::privateFor()`, żeby zapis i wyszukanie nie mogły się rozjechać. `palace_namespace` niepuste = osobne tabele pgvector dla przestrzeni wrażliwych. |
| `SpaceMember` | **Klucz złożony** `(space, user)`: dwie role jednej osoby w jednej przestrzeni są niereprezentowalne, więc pytanie „która wygrywa" nie może paść. |
| `Invitation` | Tylko skrót tokena. `isUsable()` łączy „niewykorzystane" i „nieprzeterminowane" w jednym miejscu, żeby drugie wejście nie zapomniało o jednym z warunków. |
| `Document` | `addRevision()` robi wszystko, co musi się stać razem: numer, wskaźnik bieżącej rewizji, tytuł, flagę AI i **wyczyszczenie weryfikacji**. Rozniesione po serwisie to ostatnie jest tym, o którym ktoś zapomni — a skutkiem jest dokument oznaczony jako sprawdzony przez osobę, która tego tekstu nie widziała. `verify()` przyjmuje `User`, więc agent jest niewyrażalny. |
| `DocumentRevision` | **Niezmienna** — nie ma settera. Jedyną drogą zmiany jest dopisanie rewizji. Tytuł jest kopiowany, nie czytany z dokumentu: historia z dzisiejszym tytułem na starych rewizjach kłamałaby o tym, co dokument wtedy mówił. |
| `Proposal` | `accept()` i `reject()` są nieodwracalne w jedną stronę: rozpatrzona propozycja nie wraca do kolejki, więc dwóch recenzentów klikających naraz nie zrobi z jednej propozycji dwóch dokumentów. |
| `AuditLog` | Dopisywany, nigdy nie zmieniany. Aktor zapisany **zwykłym identyfikatorem, nie kluczem obcym** — dezaktywacja konta nie rusza zapisu tego, co zrobiło. |

## Jak przechodzi żądanie

### Logowanie

1. `POST /api/login` → firewall `json_login` przechwytuje, kontroler się nie wykonuje.
2. Provider `app_users` znajduje konto po adresie, hasło weryfikowane hasherem.
3. Sukces → `LoginSuccessEvent` → `LoginAuditSubscriber` zapisuje `user.login`
   i znacznik ostatniego logowania; Lexik zwraca token JWT.

   **Subskrybent sprawdza, z którego firewalla przyszło zdarzenie**, i to nie jest
   ostrożność na zapas. Wszystkie firewalle są bezstanowe, więc `api` uwierzytelnia
   token przy **każdym żądaniu** i za każdym razem wysyła to samo zdarzenie; `mcp`
   robi to samo dla tokenów agentów. Bez tego strażnika jedna osoba klikająca po
   aplikacji dopisywała wiersz „logowanie" na każde żądanie HTTP — przy pierwszym
   otwarciu ekranu audytu było tam 40 889 wpisów, z czego 20 335 opisywało
   logowania, których nie było. Dziennik audytu, w którym większość wpisów to
   fikcja, jest gorszy od krótkiego: prawdziwe wpisy w nim są, tylko nikt ich nie
   znajdzie.
4. Porażka → `LoginFailureEvent` → wpis `user.login_failed` **bez aktora**:
   mamy wtedy deklarowaną tożsamość, nie potwierdzoną, więc zapisanie jej
   pozwalałoby podrabiać wpisy audytu cudzym adresem.

Nieznane konto i złe hasło odpowiadają identycznie — inaczej formularz
logowania służyłby do sprawdzania, kto tu pracuje.

### Odczyt przestrzeni

1. Firewall `api` weryfikuje JWT i ładuje użytkownika **z bazy przy każdym żądaniu**.
2. Kontroler buduje `Actor` i pyta `SpaceAccessResolver`.
3. Resolver czyta role przez DBAL — bez cache, więc odebranie roli działa od razu.
4. **Uprawnienie sprawdzane przed istnieniem.** Odwrotna kolejność
   różnicowałaby czas odpowiedzi między „nie ma" a „nie wolno", a wolniejsza
   odpowiedź to też ujawnienie.

### Zaproszenie

`ws:user:invite` albo endpoint administracyjny → `IssueInvitation` (skrót w
bazie, jawny token raz na wyjściu) → osoba otwiera link →
`POST /api/invitations/accept` → walidacja hasła → `AcceptInvitation` tworzy
konto, prywatną przestrzeń i członkostwo w jednej transakcji → wpis
`invitation.accepted`.

### Szukanie w pamięci

1. Wołający (REST albo MCP) buduje `Actor` i `MemoryQuery`. **Zapytanie nie ma
   pola „przestrzeń" po stronie pałaca** — może jedynie zawęzić listę, którą i
   tak przecinamy z uprawnieniami.
2. `MemoryService` liczy dozwolone przestrzenie przez `SpaceAccessResolver`.
   Puste przecięcie → **pusta odpowiedź i zero wywołań pałaca**. To nie jest
   optymalizacja: właśnie tutaj skrót „nie ma przestrzeni, więc bez filtra"
   zamieniłby się w zapytanie po całej bazie.
3. Dla każdej dozwolonej przestrzeni `SpaceCatalog` podaje skrzydło, a
   `McpMemoryStore` wykonuje **jedno `mempalace_search` na skrzydło**. Pałac
   filtruje po jednym skrzydle, nie po liście — stąd rozsyłanie.
4. Wyniki są **przerankowane** i ucięte do limitu. Sklejenie odpowiedzi bez
   przerankowania dałoby najlepsze N z przestrzeni, która trafiła pierwsza.
5. **Druga warstwa:** rejestr mówi, w której przestrzeni siedzi każda szuflada.
   Czego nie zna albo co umieszcza gdzie indziej — wypada (D-019).
6. Wpis w audycie: zapytanie, przestrzenie, liczba wyników.

### Szukanie leksykalne

1. `SearchService` rozgałęzia po trybie i dla leksykalnego woła
   `MemoryService::searchLexically()` — **tą samą drogą przez uprawnienia**, co
   semantyczne. Różni się, gdzie leży tekst; kto może go zobaczyć, liczone jest
   identycznie.
2. `DoctrineLexicalIndex` dostaje **niepustą** listę przestrzeni. Pusta byłaby
   nieodróżnialna od „bez filtra", więc port zabrania jej typem, a adapter
   sprawdza w czasie działania — adnotacja nie jest egzekwowana.
3. Zapytanie użytkownika tokenizuje `to_tsvector`, po czym z powstałych leksemów
   budowany jest `to_tsquery`. **Przedrostek `:*` dostaje tylko ostatnie słowo**
   — to, które użytkownik właśnie pisze. `quote_literal` sprawia, że nic
   z wpisanego tekstu nie da się odczytać jako składni zapytania.
4. Dwa zbiory trafień: dokumenty po pełnej treści bieżącej rewizji, wszystko inne
   po tytule i tagach. Oba **startują od predykatu tekstowego**, żeby planista
   sięgnął po indeks GIN.
5. `ts_headline` liczy się **po** obcięciu do limitu — przeparsowuje cały
   dokument, więc na wszystkich trafieniach byłby marnotrawstwem.
6. Wpis w audycie: zapytanie, tryb, przestrzenie, liczba wyników.

### Zapis do pamięci

1. Przestrzeń docelowa: wskazana albo **prywatna właściciela** (reguła 6).
   Brak prywatnej przestrzeni to zepsute konto, nie brakujący argument —
   `AcceptInvitation` tworzy ją w tej samej transakcji co konto.
2. `SpaceAccessResolver` sprawdza prawo **zapisu**. Odmowa jest wyjątkiem, nie
   pustą odpowiedzią: agent sam wskazał przestrzeń, więc i tak wie, że istnieje.
3. Otwiera się transakcja rejestru. W niej: `mempalace_add_drawer` → wiersz w
   `ws.memory_entries` → wpis audytu → zatwierdzenie.
4. Błąd pałaca cofa transakcję, w której nic jeszcze nie było. Błąd księgowania
   cofa wiersz i **zostawia szufladę w pałacu** — kierunek awarii wybrany
   świadomie (D-020), bo szuflada bez wiersza jest niewidoczna, a wiersz bez
   szuflady byłby wynikiem, którego nie da się otworzyć.

### Wywołanie narzędzia MCP

1. Firewall `mcp` czyta `Authorization: Bearer`, rozwiązuje token **jednym
   zapytaniem** (unieważnienie, wygaśnięcie, aktywność właściciela) i kładzie
   tożsamość na żądaniu. Każda porażka daje to samo `401` — różnica przydaje się
   tylko komuś, kto zgaduje tokeny.
2. Kontroler odnotowuje użycie i dostaje w odpowiedzi liczbę wywołań w oknie.
   Ponad limit → `429` i `-32005`, bez dotykania narzędzia.
3. `McpServer` waliduje kopertę JSON-RPC i wybiera metodę. Żądanie wsadowe
   odrzuca: liczyłoby się jako jedno wywołanie, robiąc dwadzieścia.
4. `McpToolRegistry` podaje narzędzie **już owinięte w audyt**.
5. Narzędzie waliduje argumenty (`ToolArguments`) i woła `MemoryService` — nigdy
   pałac wprost. Obejście serwisu omijałoby oba filtry (D-019) i księgowanie (D-020).
6. Wynik wraca jako część tekstowa MCP z JSON-em w środku. Awaria — jako **błąd
   JSON-RPC**, nie jako udana odpowiedź z błędem w treści (D-023).

### Zapis dokumentu w wiki

1. `DocumentService` sprawdza prawo **zapisu**. Przestrzeń z kolejką odrzuca zapis
   agenta i **nazywa** `ws_propose` (`-32004`); człowiek pisze tam wprost, bo
   jest recenzentem — wstawienie go do własnej kolejki oznaczałoby, że nie ma
   jej komu opróżnić.
2. `Document::addRevision()` nadaje numer, przestawia wskaźnik bieżącej rewizji,
   kopiuje tytuł, ustawia flagę autorstwa AI i **czyści weryfikację**.
3. Wpis w audycie, `flush`, a **potem** zlecenie publikacji do kolejki. W tej
   kolejności, bo zlecenie dla rewizji, która się nie zapisała, kazałoby
   pracownikowi opublikować treść, której nikt nie przeczyta.
4. `worker` bierze zlecenie. Jeśli dokument ma już nowszą rewizję — **porzuca je**
   (D-025). Inaczej aktualizuje jedną szufladę dokumentu w miejscu.
5. Od tej chwili dokument jest znajdowalny semantycznie, a stara treść — nie.

## Uprawnienia od końca do końca

Sześć warstw, każda pokryta testem negatywnym:

1. **Firewall** — bez ważnego JWT nie ma dostępu do `/api` poza zdrowiem,
   logowaniem, akceptacją zaproszenia i dokumentacją kontraktu. `/mcp` ma własny
   firewall na tokeny agentów, przed tym z JWT.
2. **`SpaceAccessResolver`** — jedyne miejsce liczące role. Zakres tokena
   agenta **zawęża i nigdy nie poszerza** uprawnień właściciela.
3. **Kontroler** — sprawdza uprawnienie przed istnieniem i zwraca `404` tam,
   gdzie `403` zdradzałoby istnienie zasobu.
4. **Audyt** — każda zmiana stanu zostawia wpis z aktorem, IP i przeglądarką.
5. **Pamięć — dwa filtry, nie jeden.** Skrzydło zawęża pytanie do pałaca,
   rejestr sprawdza odpowiedź (D-019). Pierwszy chroni przed naszym błędem w
   zapytaniu, drugi przed rozjazdem między dwoma magazynami.
6. **Zestaw narzędzi MCP** — czego nie ma w `tools/list`, tego agent nie zrobi.
   Żadne narzędzie nie przyjmuje autora ani skrzydła, więc podszycie się
   i obejście filtra są **niewyrażalne**, a nie tylko zabronione.

Administrator globalny **nie czyta cudzych przestrzeni po cichu** (D-016).
Może nadać sobie rolę — i to zostaje w dzienniku.

## Migracje

Pisane **ręcznie**, nie generowane: przy włączonym `schema_filter` generator
różnic wymaga DBAL `^4.5`, a stabilne jest 4.4.4. Mapowanie sprawdzamy przez
`doctrine:schema:validate --skip-sync`.

| Migracja | Zawartość |
|---|---|
| `Version20260912000001` | Kolejka Messengera w `ws`, z wyzwalaczem `LISTEN/NOTIFY`. |
| `Version20260912000002` | Konta, zaproszenia, przestrzenie, role, dziennik audytu. |
| `Version20260912000003` | Rejestr treści w pałacu (`ws.memory_entries`). |
| `Version20260912000004` | Tokeny agentów AI (`ws.agent_tokens`) z licznikiem limitu tempa. |
| `Version20260912000005` | Wiki: dokumenty, rewizje, kolejka propozycji; klucz obcy `memory_entries.document_id` odłożony z TODO-003. |

**Pułapka `schema_filter`** — opisana w `docs/05-deployment.md`. W skrócie: filtr
`~^ws\.~` odrzuca własne tabele, bo przy `search_path = ws` DBAL zwraca je bez
kwalifikacji schematem. Poprawny wzorzec **wyklucza** `palace`.

## Testy

| Plik | Co sprawdza |
|---|---|
| `Domain/Space/SpaceAccessResolverTest.php` | Reguły uprawnień **bez bazy** — najszybsze i najważniejsze. Pięć z dziewięciu przypadków to negatywne. |
| `Application/InvitationFlowTest.php` | Cały przepływ zaproszenia na realnej bazie: prywatna przestrzeń, token jednorazowy, wygasły, nieznany, brak jawnego tokena w bazie, wpis w audycie. |
| `Api/AuthenticationTest.php` | Logowanie, `/api/me`, identyczna odmowa dla złego hasła i nieznanego konta, audyt. |
| `Api/SpaceAccessTest.php` | `404` zamiast `403`, identyczność odpowiedzi, lista tylko własnych przestrzeni, natychmiastowy skutek odebrania roli. |
| `Api/SpaceAdministrationTest.php` | Kto **nie może**: piszący nie awansuje siebie, obcy dostaje `404`, przestrzeni prywatnej nie da się udostępnić. |
| `Application/Memory/MemoryServiceTest.php` | Pamięć **bez pałaca i bez bazy**: puste przecięcie przestrzeni nie odpytuje pałaca wcale, szuflada z obcej przestrzeni odpada, nieznana szuflada odpowiada identycznie jak zabroniona, nieudane zaksięgowanie cofa zapis. Podwójki pisane ręcznie, żeby test czytał ruch do pałaca, a nie sprawdzał, że wywołaliśmy metodę. |
| `Infrastructure/MemPalace/MemPalaceClientTest.php` | Przewód: błąd w payloadzie zamiast w kopercie, HTTP 5xx, niezrozumiała treść, ponowienie odczytu, **brak ponowienia zapisu**, brak tokena w komunikacie błędu. |
| `Infrastructure/MemPalace/McpMemoryStoreTest.php` | Tłumaczenie na dialekt MemPalace, sprawdzone wobec pól, które **realnie** zwraca żywy pałac 3.7.0. |
| `Infrastructure/Doctrine/DoctrineMemoryRegistryTest.php` | To, czego podwójka sprawdzić nie może: indeks unikalny, klucz obcy, wycofanie transakcji, `tags` w obie strony. |
| `Infrastructure/Doctrine/DoctrineSpaceCatalogTest.php` | Skrzydło różne od slugu, przestrzeń prywatna po akceptacji zaproszenia, podróbka `priv_*` bez flagi. |
| `Api/McpGatewayTest.php` | Gateway tak, jak spotyka go agent: protokół, uzgodnienie wersji, brak żądań wsadowych, 401 dla tokena unieważnionego, wygasłego i po wyłączeniu konta właściciela, **obca przestrzeń jako pusty wynik, nie błąd**, odmowa zapisu, nieznany parametr, limit tempa per token, audyt wywołań udanych i nieudanych. Celowo **nie potrzebuje pałaca** — wszystko to dzieje się przed pamięcią, więc chodzi przy każdym commicie. |
| `Api/MemoryBrowseTest.php` | Przeglądanie pamięci: obca przestrzeń niewidoczna, wskazanie jej wprost nic nie daje, filtr klasy, stronicowanie, i to, że listowanie mówi, kto napisał każdy wiersz. Wiersze wstawiane prosto do rejestru — ten endpoint czyta wyłącznie naszą tabelę, więc test nie potrzebuje pałaca. |
| `Api/SearchTest.php` | Wyszukiwanie drzwiami, których używa człowiek — **tylko leksykalnie**, bo ten tryb chodzi w całości na naszych tabelach i nie potrzebuje pałaca. Pilnuje dwóch rzeczy, które psułyby się po cichu: zapytania sięgającego do przestrzeni bez uprawnień (również wtedy, gdy obcy wskaże ją wprost) i identyfikatora z łącznikiem, którego naiwna tokenizacja nie znajduje. |
| `Domain/Search/SnippetTest.php` | Zamiana wyjścia `ts_headline` w strukturę: właściwe słowa zaznaczone, **znaczniki znikają**, niedomknięty znacznik nie połyka reszty tekstu, a `<script>` w treści zostaje zwykłym tekstem. |
| `Api/WikiTest.php` | Wiki bez pałaca: rewizje, historia z tytułem z epoki, różnica, cofnięcie idące **do przodu**, weryfikacja czyszczona nową rewizją, brak jakiejkolwiek drogi dla agenta do weryfikacji, adres z ukośnikiem, kolejka propozycji. Sprawdza, że zlecenie publikacji **zostało złożone**. |
| `Integration/WikiOnLivePalaceTest.php` | To, czego podwójka pokazać nie może: druga rewizja **zastępuje** pierwszą w pałacu (stara treść przestaje być znajdowalna, czyli `update_drawer` naprawdę przelicza wektor) i trzy szybkie zapisy z kolejką opróżnioną **od najnowszego** kończą się najnowszym tekstem. |
| `Domain/Document/RevisionDiffTest.php` | Jedyny prawdziwy algorytm w projekcie. Zły diff nie jest błędem, który ktoś zobaczy — jest recenzentem ufającym zmianie na podstawie obrazka, który nie odpowiada tekstowi. |
| `Domain/Document/DocumentSlugTest.php` | 16 odrzucanych adresów. Test, który liczy się najbardziej, sprawdza, że adres jest **odrzucany**, a nie sprzątany. |
| `Integration/McpOnLivePalaceTest.php` | Pełny obieg przez `/mcp` na żywym pałacu: `ws_remember` → `ws_search` po polsku innymi słowami → `ws_get`, zapis bez przestrzeni do prywatnej, liczby w `ws_status`, obieg faktu, dziennik. |
| `Integration/MemoryOnLivePalaceTest.php` | **Cały łańcuch na żywym pałacu**: polskie zapytanie innymi słowami, odmowa dla obcego, szuflada wstawiona poza rejestrem, filtr pokoju, obieg faktu w grafie. Pomijany, gdy pałac nie odpowiada — więc szybkie CI zostaje szybkie, a przebieg nocny to wykonuje. |

Uruchomienie: `make test` (przygotowuje bazę testową i uruchamia PHPUnit).

**Dlaczego testy negatywne są tu ważniejsze od pozytywnych:** błąd w
uprawnieniach jest cichy. Nic nie rzuca wyjątku, nic nie pojawia się w logu —
treść po prostu trafia do kogoś, kto nie powinien jej zobaczyć.

## Jak dodać nową rzecz

**Nowy endpoint:** klasa w `Presentation/Api/` z atrybutem `#[Route]`. Logika
idzie do `Application/`, nie do kontrolera. Uprawnienia **zawsze** przez
`SpaceAccessResolver`.

**Nową sondę zdrowia:** klasa implementująca `Domain\Health\HealthProbe`.
Kontenerowy tag dokłada ją automatycznie — kontrolera nie ruszasz.

**Nową regułę uprawnień:** wyłącznie w `SpaceAccessResolver`, z testem
negatywnym. Druga metoda liczenia uprawnień to druga metoda pomylenia się.

**Nową migrację:** ręcznie w `migrations/`, nazwa `VersionRRRRMMDDNNNNNN`.
Tylko schemat `ws` — do `palace` nie piszemy nigdy (D-004).

**Nowe narzędzie MCP:** klasa implementująca `Presentation\Mcp\McpTool`
z przedrostkiem `ws_` w nazwie. Tag dokłada ją do rejestru — nie ruszasz ani
kontrolera, ani listy narzędzi. Schemat **musi** mieć
`additionalProperties: false` i nie może mieć pola autora ani skrzydła; oba
warunki sprawdza `McpGatewayTest`.

**Nową operację na wiki:** metoda w `DocumentService`. Nie buduj rewizji poza
`Document::addRevision()` — tam siedzi czyszczenie weryfikacji i numeracja,
a druga droga kiedyś pominie jedno z dwóch.

**Nową operację na pamięci:** metoda w `MemoryService`, nigdy nowy wołający
`MemoryStore`. Serwis jest granicą uprawnień; obejście go omija oba filtry
(D-019) i księgowanie (D-020). Jeśli potrzebujesz nowego narzędzia MemPalace,
dodaj je do portu `MemoryStore` i do `McpMemoryStore` — nazwy narzędzi nie
wolno wypuszczać wyżej.

**Nową zależność:** wpierw decyzja `D-0xx` w `docs/06-decyzje.md`.

## Konfiguracja

| Plik | Co ustawia |
|---|---|
| `config/services.yaml` | Autowiring, tagi sond zdrowia i **narzędzi MCP**, limit tempa, adres MemPalace, **token i limit czasu pałaca**, jawne powiązania portów z adapterami, publiczny adres. Blok `when@test` udostępnia testom kilka usług po nazwie. |
| `config/packages/doctrine.yaml` | Połączenie, `schema_filter` ukrywający `palace`, mapowanie encji. |
| `config/packages/security.yaml` | Firewalle: zdrowie bez zabezpieczeń, `json_login`, **osobny firewall `/mcp` na tokeny agentów**, JWT dla `/api`. W testach obniżony koszt hashowania. |
| `config/packages/messenger.yaml` | Kolejka w bazie, `auto_setup: false` — tabelę tworzy migracja. `PublishDocument` trafia do transportu `async`. |
| `config/packages/api_platform.yaml` | Kontrakt pod `/api/docs.json`, **Swagger UI wyłączone** (wymaga Twiga, a backend nie renderuje interfejsu). |

## Znane ograniczenia

- **Brak odświeżania tokenów.** `gesdinet/jwt-refresh-token-bundle` nie
  obsługuje jeszcze Symfony 8 (wymaga `symfony/console ^7`). Do czasu wydania
  zgodnej wersji token wygasa i trzeba zalogować się ponownie.
- **Brak wysyłki maili z zaproszeniami** — link trzeba przekazać ręcznie.
- **Graf wiedzy nie łączy encji między przestrzeniami.** `wing_alfa::Symfony`
  i `wing_beta::Symfony` są dla MemPalace dwiema encjami, bo zakres wchodzi do
  klucza (D-021). Zamierzone: relacja przez granicę przestrzeni byłaby wyciekiem.
- **Znacznik `tags` nie trafia do pałaca** — MemPalace 3.7 nie ma pola na
  znaczniki w `add_drawer`. Trzymamy je w `ws.memory_entries`, czyli są
  przeszukiwalne w SQL, ale nie wpływają na wyszukiwanie semantyczne.
- **Limit tempa ma okno stałe, nie przesuwane** — agent może wykonać dwa razy
  limit na przełomie okien (D-022). Limit istnieje, żeby pętla nie zajechała
  pałaca, a nie żeby rozliczać kwoty.
- **Nie ma rekompensaty dla sierot w pałacu** — szufladę bez wiersza w rejestrze
  raportuje zadanie cykliczne, nikt jej automatycznie nie usuwa (D-020).
