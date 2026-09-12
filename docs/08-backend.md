---
tags: [ws-memory, dokumentacja, backend, symfony, warstwy, api]
---

# Backend — co do czego służy

Stan: **działa** (2026-09-12). Zaimplementowane: zdrowie, zaproszenia, konta,
logowanie, przestrzenie i role, audyt, dostęp do pamięci (szukanie, zapis, graf
wiedzy, dziennik) oraz **gateway MCP z tokenami agentów**. Brakuje: wiki
(TODO-005), frontend (TODO-006…008), publikacja z lokalnych pałaców (TODO-012).

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
| `Memory/MemoryUnavailable.php` | „Nie mogłem sprawdzić" — odrębne od „nic nie znalazłem". Agent, któremu powiemy „nic nie ma", zapisze tę wiedzę drugi raz obok kopii, której nie zobaczył. |
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
| `Memory/MemoryService.php` | **Jedyne wejście do pamięci.** REST i MCP wołają tę klasę i nic pod nią, więc wybór drzwi nie zmienia odpowiedzi (D-008). Tu mieszka rozsyłanie zapytania po skrzydłach, przerankowanie wyników, druga warstwa filtrowania (D-019), domyślna przestrzeń prywatna (reguła 6) i etykieta autora. Nic powyżej tej warstwy nie ma prawa trzymać `MemoryStore`. |

### `Infrastructure/` — adaptery portów

| Plik | Rola |
|---|---|
| `Doctrine/DoctrineSpaceMembershipRepository.php` | Czyta członkostwa **przez DBAL, nie przez ORM**. To zapytanie leży na ścieżce uprawnień każdego żądania, a hydracja encji wstawiłaby identity map między odebranie roli a jego skutek — czyli dokładnie ten cache, którego resolver obiecuje nie mieć. |
| `Doctrine/DoctrineAuditTrail.php` | Zapisuje wpisy audytu. IP i przeglądarkę bierze z bieżącego żądania, nie z parametrów — gdyby były parametrem, część wywołań by o nich zapomniała, a wpis bez pochodzenia odpowiada na połowę pytania, po co istnieje. |
| `Doctrine/DatabaseHealthProbe.php` | Sonda: czy baza odpowiada. |
| `Doctrine/DoctrineMemoryRegistry.php` | Rejestr na DBAL. Przestrzeń rozwiązuje **wewnątrz INSERT-a** po slugu — osobny SELECT otwierałby okno, w którym przestrzeń znika między sprawdzeniem a zapisem. Trzyma też granicę transakcji. |
| `Doctrine/DoctrineSpaceCatalog.php` | Skrzydło przestrzeni i przestrzeń prywatna użytkownika. Prywatną sprawdza **po konwencji slugu ORAZ po fladze** — przestrzeń nazwana ręcznie `priv_<uuid>` bez flagi nie może stać się miejscem, gdzie lądują cudze zapisy. |
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
| `Tool/DiaryWriteTool.php` | `ws_diary_write`. Domyślnie prywatnie — notatki z sesji to treść, której ludzie najbardziej oczekują jako swojej. |

### `Entity/` — model trwałości

| Encja | Uwagi konstrukcyjne |
|---|---|
| `User` | Konta się **dezaktywuje, nie usuwa** — rewizje i wpisy audytu wskazują autora, a historia bez autora przestaje być dowodem. `ROLE_USER` jest domyślna i nieprzechowywana. |
| `Space` | `Space::privateFor()` tworzy przestrzeń prywatną `priv_<uuid>` — konwencję slugu trzyma `SpaceId::privateFor()`, żeby zapis i wyszukanie nie mogły się rozjechać. `palace_namespace` niepuste = osobne tabele pgvector dla przestrzeni wrażliwych. |
| `SpaceMember` | **Klucz złożony** `(space, user)`: dwie role jednej osoby w jednej przestrzeni są niereprezentowalne, więc pytanie „która wygrywa" nie może paść. |
| `Invitation` | Tylko skrót tokena. `isUsable()` łączy „niewykorzystane" i „nieprzeterminowane" w jednym miejscu, żeby drugie wejście nie zapomniało o jednym z warunków. |
| `AuditLog` | Dopisywany, nigdy nie zmieniany. Aktor zapisany **zwykłym identyfikatorem, nie kluczem obcym** — dezaktywacja konta nie rusza zapisu tego, co zrobiło. |

## Jak przechodzi żądanie

### Logowanie

1. `POST /api/login` → firewall `json_login` przechwytuje, kontroler się nie wykonuje.
2. Provider `app_users` znajduje konto po adresie, hasło weryfikowane hasherem.
3. Sukces → `LoginSuccessEvent` → `LoginAuditSubscriber` zapisuje `user.login`
   i znacznik ostatniego logowania; Lexik zwraca token JWT.
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
| `config/packages/messenger.yaml` | Kolejka w bazie, `auto_setup: false` — tabelę tworzy migracja. |
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
