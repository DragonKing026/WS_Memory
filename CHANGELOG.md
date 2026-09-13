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
## 2026-09-13 20:56 — Ekrany maili w panelu, TODO-017 zamknięte

Dwa ekrany: **szablony** i **dziennik maili**. Obejrzane w przeglądarce, nie
tylko przetestowane — zrzuty leżą w `TODO/zrzuty/017-*.png`.

Ekran szablonów mówi wprost to, co najłatwiej zrozumieć opacznie: szablonów
**nie dodaje się i nie usuwa**, a wszystko, co nie jest miejscem z listy, trafia
do skrzynki adresata **dosłownie**. Obok pól stoi lista dozwolonych miejsc wraz
z tym, w co każde się zamieni — bo pytanie „co mogę tu wpisać" nie powinno
wymagać wywołania błędu.

`{{ link }}` wpisany w temat daje ostrzeżenie **w trakcie pisania**, a zapis
i tak odmawia — słowami backendu. Sprawdziłem oba: podpowiedź to podpowiedź,
regułą jest serwer. Podgląd składa serwer, jednym żądaniem na przycisk, i jest
zarazem sprawdzeniem poprawności: co odrzuci podgląd, odrzuci też zapis, tym
samym zdaniem.

Dziennik pokazuje adresata, rodzaj, stan i **powód porażki słowami serwera
poczty** — to jest ta kolumna, po której poznaje się złe hasło w DSN od
zamkniętego portu. Liczba prób pojawia się tylko wtedy, gdy coś znaczy: „1 próba"
w każdym wierszu zasłaniałaby ten jeden, gdzie prób było cztery. Na zrzucie
widać przypadek, dla którego powód porażki zostaje przy stanie „wysłany": dwie
odmowy serwera, sukces przy trzeciej próbie.

Schemat wiersza dziennika jest **ścisły** i odrzuca pole z treścią. Nie jest to
ostrożność na wyrost: schemat, który nadmiarowe pole po cichu pomija, pozwoliłby
backendowi zacząć je przysyłać — a wtedy działający token wyświetla się w panelu
i nic tego nie zgłasza.

**TODO-017 zamknięte**: dziesięć punktów i wszystkie kryteria.


---
## 2026-09-13 20:42 — API szablonów maili i dziennika wysyłki

Kontrakt pod ekrany w panelu. Lista szablonów oddaje w jednej odpowiedzi treść,
**zamkniętą listę miejsc**, wartości przykładowe i gotowy podgląd — bo ekran
pokazuje to wszystko naraz, a druga runda po każdy szablon kupiłaby tylko
kręciołek.

`POST …/preview` renderuje treść **niezapisaną**, więc podgląd jest przed
zapisem, nie po. Renderuje go serwer, nie przeglądarka: podgląd składany po
stronie klienta byłby drugą implementacją tej jednej rzeczy, która ma mieć
jedną — i pokazywałby radośnie szablon, który serwer właśnie odrzuci.

`POST …/test` wysyła do **własnego adresu administratora**, a nie do adresu
z żądania. Formularz z polem „odbiorca" to sposób na wysłanie maila z tego
serwera do obcej osoby z treścią do wyboru nadawcy; test sprawdza wprost, że
adres podany w żądaniu jest ignorowany. Odpowiedź to 202, nie 200 — wiadomość
jest w kolejce, a czy doszła, mówi dziennik.

**Nie ma trasy tworzącej szablon ani kasującej szablon**, i test tego pilnuje.
Zbiór szablonów to zbiór miejsc w kodzie, które wysyłają maila; wiersz dodany
ręcznie byłby treścią, której nic nie renderuje. Dziennika maili też nie da się
z panelu wyczyścić — z tego samego powodu, z którego nie da się wyczyścić audytu.

Nieznany stan w filtrze to **422, nie ciche zignorowanie**: filtr, który po cichu
się rozszerza, każe ekranowi mówić „to są nieudane" przy widoku wszystkich.

Test sprawdza też, że `{% if %}`, `{{ 7 * 7 }}` i `<?php … ?>` **przechodzą przez
całą drogę** — wpisane, zapisane, odczytane, wyrenderowane — jako znaki. To jest
zdanie, które przy następnym „użyjmy Twiga, ma piaskownicę" musi zapalić się na
czerwono.

Jeden test padł po drodze i wskazywał na zły adres z dobrego powodu:
`queued_at` ma dokładność do sekundy, a pytanie „najnowszy wiersz" nie jest wtedy
jednoznaczne. Pyta teraz o to, co faktycznie twierdzi — wiersz dla tego adresu —
i dodatkowo o brak wiersza dla adresu z żądania.


---
## 2026-09-13 20:30 — Maile naprawdę wychodzą: szablony, podstawianie miejsc, dziennik

Serwerowa strona TODO-017. Zaproszenie wystawione z panelu albo z konsoli
**wysyła maila**, a link w treści jest tym linkiem, który zakłada konto —
sprawdzone przez prawdziwy SMTP, nie założone: skrót tokena wyjętego z treści
wiadomości zgadza się z wierszem w `ws.invitations`.

**Szablony edytuje człowiek, ale nie jest to silnik szablonów.** Podstawianie
miejsc `{{ nazwa }}` z **zamkniętej listy na szablon**, bez pętli, warunków
i wywołań. Konstrukcje silnika (`{% if %}`, `{{ 7 * 7 }}`) i kod trafiają do
maila **dosłownie**, jako tekst — bo szablon edytowany w przeglądarce
i renderowany silnikiem jest wykonywaniem cudzego kodu na serwerze. Podstawienie
idzie jednym przebiegiem, więc wartość nie jest skanowana ponownie: treść
zawierająca `{{ tajne }}` zostaje takim napisem.

Nieznane miejsce to **błąd przy zapisie**, z komunikatem wymieniającym
dozwolone, a nie puste miejsce w wysłanej wiadomości. Walidacja stoi
w konstruktorze, więc szablon niepoprawny nie może zaistnieć jako obiekt —
także przy **czytaniu** z bazy, gdzie wychwytuje wiersz, pod którym zamknięta
lista się skurczyła.

**Dziennik `ws.mail_log` nie ma kolumny na treść** i mieć nie będzie: mail
z zaproszeniem niesie działający token, a to jest tabela otwierana swobodnie, bo
„to tylko logi". Sprawdzone zapytaniem po całej tabeli — każda kolumna każdego
wiersza rzutowana na tekst i przeszukana — a nie przeglądem kodu. Do tego temat,
który dziennik zapisuje, **nie może zawierać miejsca oznaczonego jako wrażliwe**;
bez tej reguły zdanie o braku tokena byłoby prawdziwe tylko do pierwszego
`{{ link }}` wpisanego w temat.

Wysyłka idzie przez Messenger, więc **niedostępny serwer poczty nie wywraca
wystawienia zaproszenia** — zaproszenie istnieje, link nadal da się skopiować
z panelu, a w dzienniku jest stan `nieudany` z powodem od serwera. Zmierzone na
martwym porcie: cztery próby (1 + 3 ponowienia), czytelny powód, zaproszenie
nietknięte. Bez ustawionego `WS_PUBLIC_URL` mail **nie wychodzi wcale** i dziennik
mówi dlaczego, zamiast wieźć komuś link do jego własnego localhosta.

Cena tego kształtu jest zapisana jako **D-038**: token jedzie w wierszu
`ws.messenger_messages`, a wiadomość po wyczerpaniu ponowień zostaje w kolejce
`failed` z treścią w środku. Sprawdziłem, że tak jest (ciało pasuje do
`[0-9a-f]{64}`), i dlatego retencja każe tę kolejkę czyścić.

Dwie rzeczy wyszły dopiero z prób i obie są opisane:

- **`TRUNCATE ws.users CASCADE`**, które robi w `setUp` każdy test integracyjny,
  kaskaduje na **każdą** tabelę z kluczem obcym do `users`. Z kluczem przy
  szablonach pierwszy test wyczyściłby treści wgrane migracją, a każdy następny
  mail w zestawie byłby „brak szablonu" — bez słowa wyjaśnienia. Dlatego autora
  zmiany trzymamy jako **adres**, nie referencję do konta. Sprawdzone
  zapytaniem: przed zmianą `mail_templates` było na liście kaskady, po zmianie
  nie jest.
- **Maile wysyła `worker`, nie `backend`.** Konfiguracja rozjechana między tymi
  usługami jest niewidoczna: `backend` z prawdziwym DSN i `worker` z `null://null`
  dają w dzienniku stan **wysłany**, choć nic nie poszło. Opisane w deploymencie.

Po drodze dwie naprawy poza zakresem zadania, obie odkryte tym, że zmieniłem
system, a nie przeglądem: `drainQueue()` w teście pałaca **liczył wszystkie**
wiadomości, więc asercja „dokładnie jedno zlecenie publikacji" zaczęła padać,
gdy na tym transporcie pojawił się drugi rodzaj wiadomości — liczy teraz to, co
nazywa. A `make analiza` padało na OOM, bo PHPStan bierze tyle procesów, ile
rdzeni (szesnaście), w kontenerze z gigabajtem pamięci; komunikat
„Child process error (exit code 137)" nie mówił nic o kodzie.

Zostają **ekrany w panelu**: lista szablonów z edycją, podglądem i wysyłką
próbną oraz dziennik maili z filtrem. Usługi pod nie są (`SendTestMail`,
`renderSample`, filtr dziennika), więc to warstwa prezentacji, nie logika.


---
## 2026-09-13 20:00 — Mailer wpuszczony do systemu, dokumentacja przestaje kłamać

Pierwsza warstwa TODO-017: `symfony/mailer` jest zależnością, `MAILER_DSN`,
`MAIL_FROM` i `MAIL_FROM_NAME` istnieją w konfiguracji, w `.env.example`
i w `docker-compose.yml` (backend i worker). Nic jeszcze nie wysyła — to
przyjdzie z szablonami i dziennikiem — ale transport da się skonfigurować.

W środowisku testowym transport `null` jest wpisany **wprost w `when@test`**,
nie przez `.env.test`. Zmienna środowiskowa kontenera wygrywa z `.env.test`,
co przy D-035 wywróciło CI; wartość wpisana w konfiguracji jest jedyną, której
nie da się nadpisać z zewnątrz. Nawet przebieg testów z prawdziwym `MAILER_DSN`
w środowisku nie wyśle niczego do nikogo.

`WS_PUBLIC_URL` zyskał **drugi parametr bez wartości zastępczej**
(`app.mail.link_base_url`). Link wypisany w konsoli czyta człowiek przy tej
maszynie, więc `127.0.0.1:8080` jest dla niego poprawny; link w mailu czyta ktoś
z innego komputera, gdzie ten sam adres prowadzi do jego własnej przeglądarki.
Pusta wartość będzie odmową wysyłki z powodem widocznym w dzienniku, a nie
wiadomością z martwym linkiem.

`docs/05-deployment.md` opisywał `WS_DOMAIN` jako „domena publiczna
(certyfikat, **linki w mailach**)". Zmiennej o tej nazwie nie ma w żadnym pliku
tego repozytorium i nigdy nie było, a maili nie było tym bardziej — wiersz
opisywał dwie rzeczy nieistniejące naraz. Zastąpiony trzema, które istnieją.
Dokumentacja jest wsadem dla agentów AI, więc nieaktualne zdanie nie jest
kosmetyką: zostaje potraktowane jako fakt i powielone.

Doszedł **mailpit za profilem `dev`** — skrzynka na próby na własnej maszynie.
Bez niej jedynym sposobem sprawdzenia, czy zaproszenie naprawdę wychodzi i czy
link w treści działa, byłaby wysyłka na prawdziwą skrzynkę, czyli prawdziwy
token w cudzej infrastrukturze. `docker compose up` jej nie podnosi.


---
## 2026-09-13 19:50 — Metodologia zadań zapisana tam, gdzie się jej szuka

Reguły o polach wyboru i o zadaniu zrobionym w części trafiły dotąd tylko do
`AGENTS.md` i do samych plików zadań — czyli do kontraktu i do przykładów, ale
nie do miejsca, w którym opisany jest **sposób prowadzenia zadań**.
`TODO/README.md` i jego angielski odpowiednik mają teraz obie, z powodami:
kryterium odhacza się po sprawdzeniu, a nie po napisaniu kodu, i nie prowadzi się
obok drugiej listy postępu, bo rozjedzie się z pierwszą.

Zapisane jest też to, czego **żaden skrypt nie wymusi**: `sprawdz-zadania.py` nie
wie, ile z zadania jest zrobione, i wiedzieć nie będzie. Reguła utrzymywana
ręcznie ma być nazwana ręcznie utrzymywaną, zamiast udawać, że pilnuje jej CI.

Doszedł `TODO/SZABLON.md` — nowe zadanie startuje z gotowymi polami wyboru
i z opisem stanów, więc konwencja nie zależy od tego, czy ktoś ją pamięta.


---
## 2026-09-13 19:48 — Zadania mają pola wyboru, odhaczane na bieżąco

Sekcje **Rozwiązanie** i **Kryteria ukończenia** są teraz listami `- [ ]`/`- [x]`
we wszystkich czterech aktywnych zadaniach. Postęp widać przy konkretnym punkcie,
a nie w tabeli obok — którą zresztą usunąłem z TODO-012, bo powtarzała listę
rozwiązania i była drugim źródłem prawdy o tym samym.

TODO-012 ma odhaczone punkty 1–4 oraz **sześć kryteriów**, które udowadniają
testy scalone w `main`. Nie odhaczyłem kryterium o filtrze sekretów, choć serwer
go ma: wymaga **dwóch osobnych testów**, po stronie klienta i serwera, a klienta
nie ma. Kryterium odhacza się, gdy jest sprawdzone, nie gdy kod istnieje.


---
## 2026-09-13 19:46 — Zadanie zrobione w połowie ma to po sobie widać

TODO-012 miało serwerową połowę scaloną w `main` i **nadal stało na liście jako
„do zrobienia"**, nieodróżnialne od zadania, którego nikt nie tknął. Różnicę
widać było wyłącznie w `CHANGELOG.md` i w historii gita — czyli nie tam, gdzie
ktoś patrzy, planując następny krok. Zauważył to człowiek, nie ja.

Nowy stan `🔵 W TOKU — punkty A–B z N` plus sekcja **Postęp** z tabelą: co
gotowe, co zostało, czego brakuje. TODO-012 dostało ją jako pierwsze: punkty 1–4
z 12 zrobione, punkty 5–10 to strona klienta, bez której **endpointu nie ma kto
zawołać poza testami**.


---
## 2026-09-13 19:44 — Dwa nowe zadania: wysyłka maili i instalator

**TODO-017** — system nie wysyła ani jednego maila i nigdy nie wysyłał; brakuje
zależności, konfiguracji i nadawcy. Zaproszenie wypisuje link, a człowiek
przekazuje go sam. Dokumentacja w tym miejscu **kłamie**: opisuje `WS_DOMAIN`
jako „domena publiczna (certyfikat, linki w mailach)". Szablony będą edytowalne
w panelu, ale **przez podstawianie miejsc, nie silnik szablonów** — szablon
edytowany w przeglądarce i renderowany silnikiem to wykonywanie cudzego kodu na
serwerze. Dziennik maili **nie zapisuje treści**: mail z zaproszeniem zawiera
działający token, a dziennik z ciałem wiadomości byłby drugą kopią poświadczenia
w miejscu, do którego zagląda się swobodnie.

**TODO-018** — instalator i deinstalator. Powód wyszedł dziś na jaw w praktyce:
nikt nie wiedział, jakie jest hasło administratora, bo instancja powstała bez
zapisania go gdziekolwiek, a polecenia do jego ustawienia jeszcze nie było.
Instalator **generuje sekrety zamiast o nie pytać** — sekret wpisany z palca jest
albo słaby, albo zapisany w drugim miejscu. Instalacja w systemie **nie będzie
udawać**, że postawi Postgresa z pgvector na dowolnej dystrybucji: wykryje braki
i zainstaluje samą aplikację na tym, co jest. Deinstalator ma być trudniejszy od
instalatora i domyślnie **zostawiać kopie zapasowe**.

Przy okazji posprzątane dwa drzewa robocze po podagentach. Kontenery montujące je
pisały jako root, więc 1159 plików nie dało się usunąć zwykłym `rm` — poszły
kontenerem. W głównym drzewie ten sam mechanizm dotknął dwóch **śledzonych**
plików (`frontend/auto-imports.d.ts`, `components.d.ts`); właściciel przywrócony.


---
## 2026-09-13 19:33 — Dało się przejąć cudzą szufladę przez publikację

Zgłoszone przez skanowanie kodu na pull requeście, zanim endpoint miał
kogokolwiek, kto mógłby go zawołać — i było prawdziwe.

`bindingForSource()` szukał wiersza po parze **(replika, szuflada źródłowa)**,
czyli po dwóch wartościach, które nadawca podaje **w całości sam**. Nic nie
wiązało tej pary z tym, kto publikuje. Wystarczyło więc podać cudzą nazwę repliki
i jeden z cudzych identyfikatorów szuflad, żeby dostać **cudzy wiersz** — a
ścieżka powtórnej publikacji nadpisywała wtedy zawartość szuflady w pałacu własnym
tekstem i przenosiła wiersz rejestru do własnej przestrzeni. Zniszczenie cudzej
treści i zabranie cudzego wpisu, zwykłym endpointem do publikowania.

Właściciel jest teraz **częścią pytania, nie kontekstem**: stoi w `WHERE`, a nie
w sprawdzeniu po fakcie, i indeks `uniq_entries_source` obejmuje go kolumna
w kolumnę (`Version20260913000006`). Skutek uboczny jest sam w sobie poprawką
błędu: dwie osoby mogą mieć **tę samą parę**. Nazwy replik wybiera się lokalnie
i nic ich nie uzgadnia, więc pod starym indeksem dwa laptopy o tej samej nazwie
oznaczały, że pierwszy publikujący blokuje drugiego na zawsze — awaria wyglądająca
jak utrata danych i praktycznie nie do odczytania z komunikatu.

Atrapa rejestru w testach dostała **to samo zawężenie**. Bez tego testy na
poziomie usługi świeciłyby na zielono, opisując zachowanie, którego baza już nie
dopuszcza — a rzecz, o którą chodzi, jest granicą uprawnień, nie szczegółem
zapytania.

Oba testy sprawdzone sabotażem: bez warunku w zapytaniu pada test przejęcia, bez
zawężonego indeksu druga osoba nie może opublikować własnej szuflady. 508 testów
zielonych, PHPStan czysty.

Przy okazji: do `docs/06-decyzje.md` trafiły **markery konfliktu scalania**.
Sprawdzałem po scaleniu liczbę markerów w pliku angielskim, a w polskim tylko
nagłówki decyzji — i przepuściłem trzy. Też zgłoszone przez skaner.


---
## 2026-09-13 19:21 — Serwer przyjmuje publikację z lokalnego pałaca (TODO-012, punkty 1–4)

`POST /api/publish` to jedyna droga, którą wiedza z lokalnego pałaca wchodzi do
wspólnej bazy poza pisaniem w wiki — i od D-014 jeździ **sama, bez udziału
użytkownika**. Powstała serwerowa połowa mostka: migracja `Version20260913000005`
z tabelami `mirrors`, `publish_settings` i `publish_batches`, reguła lądowania,
filtr sekretów, endpoint publikacji i wycofanie partii. Kolumny mostka
w `memory_entries` istniały już od `Version20260912000003`; brakowało wszystkiego
wokół nich.

Trzy rzeczy są tu ważniejsze od samego endpointu. **Serwer nie ufa klientowi**:
wtyczka filtruje sekrety przed wysłaniem, serwer filtruje po odebraniu — filtr,
który działa wyłącznie na laptopie, działa czasami. **Powtórna wysyłka jest
darmowa**: para (replika, szuflada źródłowa) jest unikalna, więc kolejka wyjściowa
może ponawiać bez końca, a odsiew po skrócie treści łapie drugi rodzaj powtórzenia
— trzy osoby mielące to samo repozytorium. **Partia jest niepodzielna**: jedna
transakcja obejmuje wiersze rejestru i partię, która za nie odpowiada, bo osiem
wierszy bez partii to treść, której nikt nie cofnie.

Filtr sekretów rozpoznaje **wartości zastępcze**, bo bez tego odrzuciłby własny
`.env.example` — plik istniejący po to, żeby nikt nie zapisywał prawdziwych haseł.
Skłania się przy tym do odmowy: pominięcie zapisuje sekret do wspólnej,
indeksowanej i backupowanej bazy, a fałszywy alarm kosztuje jedną szufladę
wymienioną w raporcie, bo lokalny oryginał zostaje.

`/api/publish` przyjmuje **token agenta** i jest to jedyne wyłamanie z reguły
„agenci na `/mcp`, ludzie na `/api`". Nadawcą jest kolejka wyjściowa działająca
bez nadzoru na czyimś laptopie (D-015), a ośmiogodzinny JWT nie jest
poświadczeniem, które coś takiego może trzymać. Osłona jest wąska: żądanie zostaje
przejęte tylko gdy ścieżka to `/api/publish` **i** nagłówek zaczyna się od
`Bearer wsm_`, więc żądanie zalogowanej osoby przechodzi dalej do JWT. Ta granica
dostała własny test jednostkowy sprawdzony sabotażem — test przez HTTP wymaga
żywego pałaca i nie chodziłby na zwykłych commitach.

Klient — wtyczka, kolejka wyjściowa, `/ws-publish` — jest osobnym zadaniem; do tego
czasu endpointu nie ma kto zawołać poza testami. Rozstrzygnięcia, których D-014 nie
zawiera, zapisano jako **D-036**.

---
## 2026-09-13 19:11 — Testy integracyjne przestały zaśmiecać pałac (D-037)

Trzy klasy z grupy `integracja` pisały do prawdziwego pałaca i nie kasowały po
sobie **niczego**. Stan zmierzony tego dnia: **1554 szuflady i 100% z nich to
śmieci po testach** — 531 skrzydeł `test-integracja-*`, 447 `test-wiki-*`, 324
`test-mcp-*` i 162 osierocone `priv_<uuid>` po użytkownikach testowych.
Prawdziwej treści: zero. Każdy przebieg dokładał 14 szuflad.

Sprzątanie stoi raz, we wspólnej cesze, i bierze listę z rejestru
`ws.memory_entries` — bo kasowanie własnego skrzydła przebiegu **nie wystarcza**:
zapis bez wskazanej przestrzeni ląduje w prywatnej przestrzeni autora (reguła
nienaruszalna 6) i stamtąd wzięło się te 162 skrzydła `priv_`. Kasuje przez API
pałaca, nigdy SQL-em w schemacie `palace` — D-004 obowiązuje też testy.

Porażka sprzątania nie wywraca testu, bo niedostępny pałac na końcu przebiegu nic
nie mówi o sprawdzanym kodzie — ale idzie na stderr z nazwą skrzydła. **Cicha
porażka sprzątania to dokładnie mechanizm, który wyprodukował te tysiąc skrzydeł**,
więc ścieżkę porażki sprawdzono celowo psując nazwę narzędzia.

Przy okazji ustalenie o samym narzędziu: `mempalace_status` wymienia **najwyżej
1000 skrzydeł**, a resztę wrzuca do jednej pozycji `unknown`. Wyglądało to jak
skrzydło z setkami szuflad, którego `list_drawers` nie potrafi pokazać — bo ono
nie istnieje. Jedyną wiarygodną liczbą z tego narzędzia jest `total_drawers`.

Zmierzone: 1727 szuflad przed przebiegiem grupy, 1727 po.

---
## 2026-09-13 19:04 — Konsola umie ustawić hasło i odwołać token agenta

Do dziś z konsoli dało się konto **stworzyć**, ale nie **naprawić**. Konta
administratora bez hasła nie odzyskiwało się w ogóle — jedynym wyjściem było
zaproszenie na inny adres, po którym stare konto zostawało zablokowane, a obok
niego powstawało drugie. `ws:user:password` kończy ten stan: **domyślnie
generuje** hasło i wypisuje je raz, bo hasło podane w argumencie zostaje
w historii powłoki i przeżywa każdy powód, dla którego je ustawiono. Reguła hasła
przestała przy tym istnieć w dwóch kopiach — dwanaście znaków i odrzucanie haseł
znanych z publicznych wycieków mieszkają w jednej klasie `PasswordPolicy`,
z której korzysta i przyjmowanie zaproszenia, i to polecenie.

Druga luka była gorsza, bo miała obejście: token agenta wystawiony do
jednorazowej pracy odwoływało się **zapisem wprost w bazie**, czyli z pominięciem
audytu i reguły „tylko własny token". Zrobiłem tak dziś sam, bo nie było innej
drogi. `ws:agent:revoke` woła tę samą usługę co `DELETE /api/agent-tokens/{id}`,
więc obie drogi mają jedną regułę i jeden ślad, a cudzy token odpowiada dokładnie
jak nieistniejący. Towarzyszy mu `ws:agent:list` — **osobne polecenie, nie flaga**,
bo takie, które z flagą czyta, a bez niej niszczy, jest o jedną literówkę od
zdjęcia agenta z pracy w jej trakcie.

Dwie rzeczy polecenie hasła **mówi wprost**, bo inaczej nikt by ich nie
podejrzewał. Wydane tokeny JWT działają do wygaśnięcia — JWT jest bezstanowe
(D-017), więc reset hasła brzmi jak odcięcie dostępu, a nim nie jest; odcina
wyłączenie konta. I konto wyłączone hasło dostaje, ale się nim nie zaloguje:
odmowa byłaby tu gorsza, bo hasło nie nadaje żadnego dostępu, więc wpis w audycie
nie ma o czym skłamać — inaczej niż przy nadaniu roli, które dlatego odrzucamy.
Sam wpis `user.password_reset` **nie ma aktora**: z konsoli nikt nie jest
zalogowany, a wpisanie konta czytałoby się jak „sam sobie zmienił hasło".

Dwanaście testów poleceń, każdy sprawdzony, że pada bez poprawki.

---
## 2026-09-13 18:30 — Slug testowej przestrzeni wpisany, a nie brany ze środowiska

Testy dostawały nazwę wspólnej przestrzeni z `backend/.env.test`. Lokalnie
działało; na pełnym stosie w CI **zmienna środowiskowa kontenera wygrywa z tym
plikiem**, więc testy dostały produkcyjne `wiedza`, zderzyły się z własną fiksturą
o tym samym slugu i padły — 58 błędów naraz, po raz drugi tego samego dnia.

Teraz wartość jest **wpisana** w `when@test`, więc nie zależy od tego, w jakim
środowisku akurat lecą. Sprawdzone przez uruchomienie całego zestawu z jawnie
podstawioną zmienną `WS_DEFAULT_SPACE_SLUG=wiedza` — czyli dokładnie w warunkach,
które wywróciły CI. 422 testy zielone.

Przy okazji zniknął drugi opis tego samego z `.env.test`: dwa źródła prawdy dla
jednej wartości to pytanie, które z nich obowiązuje.

---
## 2026-09-13 18:24 — Wartość ze spacją w `.env.example` wywróciła CI

`WS_DEFAULT_SPACE_NAME=Baza wiedzy` bez cudzysłowów. Docker Compose czyta taki
plik poprawnie, ale krok CI **sourceuje go jak skrypt powłoki** — i „wiedzy"
stało się poleceniem: `./.env: line 79: wiedzy: command not found`, wyjście 127.

Jedyna taka wartość w pliku. Sprawdzone `source`-em po poprawce.

---
## 2026-09-13 18:20 — Jedna wspólna przestrzeń dla każdego nowego konta (D-035)

Konto trafia od razu do **Bazy wiedzy** z rolą `writer`. Przestrzeń powstaje sama
przy pierwszym koncie, a pusty `WS_DEFAULT_SPACE_SLUG` wyłącza mechanizm.

Powód wyszedł na jaw brutalnie: świeżo utworzone konto **administratora
globalnego** zalogowało się i przeczytało „nie należysz jeszcze do żadnej
przestrzeni zespołowej" — przy bazie wiedzy, która stała obok i była dla niego
niewidoczna, mimo najwyższych uprawnień w systemie. Dopisywanie ludzi ręcznie,
jeden po drugim, było jedyną drogą.

To nie kłóci się z D-016. Tamta zabrania administratorowi **cichego** sięgania do
przestrzeni — chodzi o wyjątek bez śladu. To jest jawna reguła stosowana do
wszystkich i zapisywana w dzienniku. Wpis nie ma aktora, bo nikt tego nie nadał:
nowe konto jako aktor czytałoby się jak „sam się wpuścił", a zaproszenie z konsoli
nie ma zapraszającego wcale.

**Dwie pułapki, obie warte zapamiętania.** Pierwsza: `flush()` w środku transakcji
miał łapać kolizję klucza i doczytać cudzy wiersz — nie może, bo Doctrine
**zamyka** EntityManagera po nieudanym `flush`, więc ścieżka ratunkowa działała na
zamkniętym managerze, a konto zostawało utworzone w połowie. Zgłosiło to naraz
58 testów. Druga: usługa wpięta wyłącznie w bloku `when@test` przechodziła **cały
zestaw testów**, a dev i produkcja wywalały się na autowiringu przy pierwszym
prawdziwym żądaniu. Złapane dopiero sprawdzeniem na żywej aplikacji — testy nie
mogły tego złapać z definicji.

Własność „świeże konto ma **dokładnie** swoją przestrzeń prywatną" przestała
obowiązywać i była wprost zapisana w jedenastu testach. Zostały przepisane tak,
żeby mówiły prawdę o nowym stanie — a nie tak, żeby przestały cokolwiek znaczyć:
tam, gdzie wcześniej stała liczba przestrzeni, stoi teraz **wypisana lista
slugów**, bo liczba przepuściłaby podmianę „wspólna → cudza".

---
## 2026-09-13 17:50 — Sprawdzenie zadań przepuszczało anulowane leżące na liście

Warunek w `sprawdz-zadania.py` brzmiał „zamknięte **i nie anulowane**", więc
zadanie anulowane mogło zostać w `TODO/` bez słowa protestu. Dokładnie to zrobiło
TODO-010: leżało dobę na liście do zrobienia, mimo że D-012 anulowała je dzień
wcześniej — i zauważył to człowiek, pytając, czemu nie robimy go przed 011. Czyli
tym kosztem, któremu ten skrypt ma zapobiegać.

Teraz anulowane liczy się tak samo jak ukończone: **ma zniknąć z listy**.
Sprawdzone symulacją — zadanie oznaczone jako anulowane i zostawione w `TODO/`
jest zgłaszane, czego przed poprawką nie było.

Drugą stroną tej zmiany jest to, że anulowanego nie zmuszamy już do sekcji
**Co zostało zrobione**. Nic w nim nie powstało; wystarczy **Dlaczego anulowane**.
Wymaganie rozliczenia z pracy, której nie było, produkuje tylko pustą sekcję.

---
## 2026-09-13 17:47 — Anulowane TODO-010 zeszło z listy zadań

Zadanie jest anulowane od 2026-09-12 (decyzja D-012 zabrała mielenie po stronie
serwera w całości), ale leżało dalej w `TODO/` i wyglądało na zaległe — na tyle,
że padło pytanie, czemu nie robimy go przed 011. Przeniesione do `TODO/DONE/`,
gdzie skrypt sprawdzający i tak akceptuje stan `ANULOWANE`.

---
## 2026-09-13 17:46 — Nowy dokument da się wreszcie zacząć z przeglądarki

Edytor **od początku** umiał tworzyć dokumenty — otwarcie nieistniejącego adresu
to jego normalna droga — ale nic w interfejsie tam nie prowadziło. Jedynym
wejściem było wpisanie adresu ręcznie w pasku przeglądarki, więc pusta przestrzeń
uczciwie pisała, że „edytor dochodzi w TODO-008", długo po tym, jak doszedł.

Przycisk **Nowy dokument** w nagłówku przestrzeni, widoczny dla piszących. Pyta
o adres i od razu sprawdza go regułą serwera, żeby odmowa przyszła przy pisaniu,
a nie po pierwszej próbie zapisu — oraz mówi, gdy taki dokument już jest.

---
## 2026-09-13 17:44 — Trzy usterki wyłapane pierwszym logowaniem na świeże konto

**Ekran zaproszeń nie otwierał się w ogóle.** Schemat frontendu wymagał, żeby
każde zaproszenie miało zapraszającego, a **pierwsze konto każdej instancji
zaprasza się z konsoli**, gdzie nikt nie jest zalogowany — więc `invitedBy` jest
puste. Backend miał to poprawnie jako `?string` od encji po kontroler; złożenie
psuł wyłącznie Zod. Efekt: ekran padał na każdej świeżo postawionej instancji, dla
każdego, i wychodziło to dopiero po zalogowaniu się tym pierwszym kontem. Teraz
zaproszenie bez zapraszającego wyświetla się jako „wystawione z konsoli".

**Nadanie roli, którą ktoś już ma, dopisywało do audytu wpis o zmianie.**
`space.member_role_changed` z `previousRole: "admin"` na `"admin"` — zdanie o
zmianie, której nie było. Żądanie nadal kończy się powodzeniem, bo powtórzenie nie
jest błędem, ale **nie zapisuje wiersza**. To ta sama rodzina co 20 335 fałszywych
`user.login`: zdarzenie zapisywane przy żądaniu zamiast przy realnej czynności.
Reguła ogólna trafiła do `docs/08-backend.md`.

**Pusta przestrzeń odsyłała do TODO-008** — „edytor w przeglądarce dochodzi
w TODO-008" — a edytor istnieje od wczoraj.

---
## 2026-09-13 17:23 — Instrukcje jako zasoby MCP; TODO-009 zamknięte

Gateway wystawia treść z `plugin/shared/` jako **zasoby MCP**: `resources/list`,
`resources/read` i `resources` w `capabilities`. Siedem adresów w schemacie
`ws-memory://` — protokół recall, zasady dokumentowania, konfiguracja i po jednym
na każdego z czterech podagentów. Powód jest ten sam, dla którego instrukcje nie
mieszkają we wtyczce (D-013): treść ma jedno źródło, a jej zmiana jest **deployem
serwera**, nie aktualizacją wtyczki u każdej osoby — i każdy klient MCP, nie tylko
Claude Code, czyta dokładnie ten sam tekst.

Mapowanie adresu na plik jest **jawną tablicą w kodzie**, a nie skanem katalogu:
skan opublikowałby każdemu agentowi cokolwiek, co do tego katalogu wpadnie —
brudnopis, kopię zostawioną przez edytor. Frontmatter jest metadanymi opakowania,
więc wychodzi z treści zasobu. Brak pliku na dysku jest błędem, nigdy pustym
zasobem: pusta instrukcja czyta się dla modelu jak „nie ma żadnego protokołu"
i agent po prostu jedzie dalej.

**Odczytu zasobu nie zapisujemy w dzienniku audytu i jest to decyzja, nie
przeoczenie.** Zasób to statyczny tekst, identyczny dla każdego tokena, a klient
MCP odpytuje listę przy każdym połączeniu — wpis mówiłby „ktoś się podłączył",
a nie „ktoś coś zrobił". Ten sam mechanizm dał już w tym systemie 20 335
fałszywych wpisów `user.login`. Sprawdzone na żywym gatewayu: dziesięć odczytów,
zero nowych wierszy audytu. Limit tempa obejmuje te metody tak samo jak resztę.

`plugin/` weszło do zakresu backendu w szybkim sprawdzeniu — od teraz zmiana samej
instrukcji potrafi wywrócić PHPUnita, i ma to zrobić na gałęzi, a nie po scaleniu.

Backend: **416 testów**, PHPStan poziom 8 czysty.

**TODO-009 zamknięte.** Jedno kryterium zostało niespełnione i jest tak zapisane:
zakazu odpowiadania z wiedzy ogólnej w `ws-onboarding` nie da się potwierdzić
testem — nie ma mechanizmu, który by go wymusił.

---
## 2026-09-13 17:21 — Dwie reguły gita dla pracy równoległej w jednym drzewie

Indeks gita jest wspólny dla wszystkich sesji w katalogu, więc gołe `git commit`
zabiera to, co ktoś inny zdążył zapisać do indeksu. Dziś commit „backend: dodaj
port biblioteki instrukcji w Domain" wciągnął przy okazji całe pakowanie wtyczki
Claude Code. Nic nie zginęło, ale opis commita zaczął kłamać — a to jedyna rzecz,
po której za pół roku poznaje się, co się wtedy działo. Reguła: **commit z podaną
ścieżką**, `git commit -- <ścieżki>`.

Druga, z tej samej godziny: `git commit -- <ścieżka>` wskazująca **dowiązanie do
katalogu** przechodzi przez nie i zapisuje zawartość jako zwykłe pliki. W świeżym
klonie treść podagentów istniałaby przez to dwa razy — dokładnie to, czego
zabrania D-013. Sprawdzenie to `git ls-files -s` i tryb `120000`.

---
## 2026-09-13 17:20 — Wtyczka WS_Memory dla Claude Code (TODO-009)

Wtyczka działa i jest zainstalowana z tego repozytorium: trzy skille, czterech
podagentów, trzy komendy, jeden hook i serwer MCP `ws_memory` po HTTP.

**Hook `session-start`** woła `ws_status` i wstrzykuje agentowi na wejściu:
do jakich przestrzeni token ma prawo, z jaką rolą, ile jest w nich wpisów
i **gdzie wyląduje zapis bez wskazanej przestrzeni**. Bez tego agent odkrywa
własne uprawnienia przez porażki, a domysły trafiają do bazy.

**Prywatność sprawdzona zapisem ruchu, nie deklaracją.** Hook uruchomiono
z podstawionym transkryptem i znacznikami kontrolnymi na wejściu, po czym
przechwycono całe żądanie: 91 bajtów, jedno wywołanie `ws_status` bez
argumentów, zero znaczników. Nie ma czym wyciec, bo hook w ogóle nie dotyka
transkryptu.

**Treść instrukcji istnieje raz** — `plugin/skills/*/SKILL.md` i `plugin/agents`
to dowiązania symboliczne do `plugin/shared/`. Przy czym podagenci wymagają
dowiązania do **katalogu**: cztery dowiązania do plików dawały `Agents (0)` —
nie ładowały się **bez żadnego błędu**. Skille tego problemu nie mają.

**Prawdziwa instalacja wyłapała trzy rzeczy, których walidator nie widzi**:
zadeklarowany klucz `"hooks"` (plik ładuje się sam, deklaracja to
`Duplicate hooks file detected`), niekwalifikowana zależność `["mempalace"]`
(szuka we własnym marketplace) i te dowiązania wyżej. Wniosek jest w D-034,
a sprawdzeniem końcowym jest **policzenie składników** w
`claude plugin details`, nie zielony walidator.

Poprawiony też błąd, który wychodził tylko przy złym tokenie: hook wstrzykiwał
**pustą** ramkę kontekstu. Pusty kontekst wygląda dla agenta jak „baza nic nie
ma", czyli mówi nieprawdę. Teraz przy każdej porażce hook milczy — brak
konfiguracji, brak sieci, padnięty serwer, odmowa uwierzytelnienia — i zawsze
kończy zerem.

---
## 2026-09-13 17:18 — D-033 i D-034: gdzie mieszka wtyczka i czego nie sprawdziliśmy

**D-033** — wtyczka zostaje w tym repozytorium, jako `plugin/`, a
`.claude-plugin/marketplace.json` w korzeniu wskazuje ją wpisem `"./plugin"`.
Bez submodułu i bez drugiego repozytorium. Sprawdzone w zainstalowanym katalogu
wtyczek, nie w dokumentacji: większość wpisów oficjalnego katalogu Anthropica to
podkatalogi jednego repo, a `claude plugin marketplace add --sparse` istnieje
wprost pod ten przypadek, więc instalujący nie pobiera całej aplikacji. Wyjście
na osobne repozytorium zostaje tanie (`git subtree split` plus zmiana wpisu),
i właśnie dlatego można je odłożyć.

**D-034** koryguje D-012. Ta wymieniła trzy mechanizmy Claude Code jako
„sprawdzone w dokumentacji"; `TODO-009` dołożyło czwarte założenie. Dwa się
potwierdziły, dwa nie: `source: {"type": "command"}` ma inny klucz **i inne
przeznaczenie** (wypisuje ścieżkę do katalogu wtyczki, nie jest hakiem
instalacyjnym), a zdarzenia `PreCompact` nie ma w udokumentowanej liście.

Reguła, która z tego wynika: **mechanizm zewnętrznego narzędzia wpisujemy do
decyzji dopiero po tym, jak go uruchomiliśmy.** „Sprawdzone w dokumentacji"
znaczy „przeczytane".

---
## 2026-09-13 16:58 — Treść instrukcji dla agentów: jedno źródło w `plugin/shared/`

Siedem plików z treścią, którą wtyczka WS_Memory ma podawać agentom: protokół
odtwarzania wiedzy (szukaj, zanim odpowiesz), zasady pisania firmowej
dokumentacji, konfiguracja tokena i opisy czterech podagentów.

Leżą w `plugin/shared/`, bo **treść ma istnieć w repozytorium raz** (D-013).
Pakowania dla poszczególnych klientów AI będą ją zaciągać, a nie kopiować —
inaczej po pierwszej poprawce protokołu Claude i Codex mówiłyby co innego.

Dwie rzeczy zapisane wprost, bo wynikają z tego, jak ta baza ma działać:
kolejność szukania to **najpierw wspólna baza, potem lokalny pałac** (notatka
jest zapisem czyjegoś myślenia, dokument jest ustaleniem), a `ws-onboarding`
ma zakaz odpowiadania z wiedzy ogólnej — bo nowa osoba nie odróżni firmowej
praktyki od domysłu modelu, zapamięta go i powtórzy jako zasadę.

---
## 2026-09-13 16:32 — Ekrany administracyjne; TODO-008 zamknięte

Cztery ekrany pod `/admin`: konta, zaproszenia, przestrzenie i dziennik audytu.
Zaproszenia da się wreszcie wystawić z panelu, a nie tylko z konsoli — link
pokazuje się **raz**, tak jak token agenta.

**Ekran audytu udowodnił, po co istnieje, w minutę po otwarciu.** Pokazał 40 889
wpisów, z czego **20 335 to `user.login`** — połowa dziennika opisująca
logowania, których nie było. Firewalle są bezstanowe, więc token jest sprawdzany
przy **każdym** żądaniu i zdarzenie logowania leciało za każdym razem. Jedna
osoba klikająca po aplikacji dopisywała wiersz na każde żądanie HTTP; przy okazji
„ostatnie logowanie" pokazywało „przed chwilą" każdemu z ważnym tokenem, a każdy
odczyt wykonywał zapis do bazy.

Istniejący test sprawdzał, że dziennik **nie jest pusty** — co było prawdą i przed
zepsuciem, i po, więc nie łapał niczego. Nowy liczy. Trzy testy świeżego ekranu
audytu miały tę usterkę zakodowaną jako oczekiwanie i poprawka je wywróciła —
dokładnie tak, jak powinna.

Dziennik audytu, w którym większość wpisów to fikcja, jest gorszy od krótkiego:
prawdziwe wpisy w nim są, tylko nikt ich nie znajdzie.

Druga rzecz z tej samej rodziny: nadawanie dostępu do przestrzeni żyło pod inną
trasą niż zmiana roli, więc ta sama reguła odpowiadała dwoma kodami, a ta sama
operacja zapisywała się w audycie raz jako „dodano", raz jako „zmieniono rolę".
To unieważnia pytanie, dla którego D-016 w ogóle istnieje: czy ta osoba wtedy
dostała dostęp, czy tylko awansowała. Ujednolicone. Przy okazji nadanie roli
wyłączonemu kontu jest teraz odrzucane — udałoby się, nic by nie dało, a w
dzienniku zostałby wpis mówiący, że ktoś dostał dostęp.

Poza tym `vue-tsc` wywracał się w kontenerze na braku pamięci (Node dobiera
stertę od limitu kontenera; przy 1 GB wychodziło 549 MB) — objaw wygląda jak błąd
typów i blokował też skrypt wypychający.

Praca szła przez czterech podagentów, każdy commitował **własne ścieżki**. To
poprawka po wcześniejszej sesji, w której trzy równoległe podagenty w jednym
drzewie dały jeden commit na czterdzieści plików — czyli dokładnie to, czego
zakazuje `AGENTS.md`.

---
## 2026-09-13 15:29 — Domyślna wersja MemPalace w repozytorium to 3.9.0

Aktualizacja na działającej instalacji zmieniła tylko `.env`, którego w
repozytorium nie ma. Domyślne przypięcie zostawało na 3.7.0 w trzech miejscach
(`.env.example`, `ARG` w Dockerfile pałaca, wartości zastępcze w compose), więc
**każda nowa instalacja startowałaby z wersji o dwie mniejsze wstecz** i od razu
pokazywała w panelu, że jest co aktualizować.

Podniesienie domyślnej wartości jest tu decyzją, nie porządkami: wersja jest
przypięta świadomie, a przypięcie wolno przesunąć dopiero po tym, co właśnie się
odbyło — kopii zapasowej, przebudowie i teście polskiej semantyki na nowej
wersji. Ten test przeszedł, więc 3.9.0 przestaje być „nowością do sprawdzenia"
i staje się tym, co instalujemy.

---
## 2026-09-13 15:25 — MemPalace podniesiony do 3.9.0; dwie usterki z prawdziwego przebiegu

Agent aktualizacji zainstalowany jako jednostka systemd i **użyty naprawdę**:
3.7.0 → 3.9.0, zlecone z panelu, wykonane na hoście. Test semantyki przeszedł na
nowej wersji (podobieństwo 0,743 dla zapytania bez wspólnych słów z treścią),
a kopia zapasowa sprzed operacji zapisała się sama.

Uruchomienie na żywo obnażyło dwie usterki, których **nie złapał żaden test** —
i to jest tu najciekawsze, bo obie są tego samego rodzaju: dotyczą stanu **poza**
tym, który testy w ogóle widzą.

**Panel po udanej aktualizacji nadal pokazywał starą wersję.** Zapisany stan
przepisuje wyłącznie sprawdzenie, a nikt o nie nie prosił. Ekran meldował
„zakończone powodzeniem" i obok tego wersje sprzed operacji, którą właśnie
ogłosił za zakończoną. Testy tego nie widziały, bo wszystkie asercje dotyczyły
wierszy, które ta klasa **zapisuje** — a rzecz szła o wiersz, którego nie
zapisywała.

**Backend nie widział nowego `.env`.** Agent wymieniał tylko kontener pałaca,
a zmienna środowiskowa ustala się przy **tworzeniu** kontenera. Pole „przypięta"
pokazywało starą wartość, czyli dokładnie ten rozjazd, który ta funkcja ma
wykrywać — wywołany przez nią samą. Testy tego nie widziały, bo wszystkie chodzą
wewnątrz jednego, raz utworzonego kontenera.

Obie poprawki sprawdzone porządnie: test regresji **pokazany najpierw jako
czerwony** po tymczasowym cofnięciu poprawki, a pełna pętla przejechana drugi raz
z celowo zafałszowanym stanem, który sam się poprawił.

Morał wart zapisania: **test sprawdza to, co kod robi ze stanem, który zna.**
Wersja w środowisku kontenera i wiersz przepisywany inną ścieżką są poza tym
zasięgiem — wychodzą dopiero wtedy, gdy operację wykona się naprawdę.

---
## 2026-09-13 14:59 — Administracja wyprowadzona z paska bocznego bazy wiedzy

Pozycja „Administracja → Zależności" wisiała w pasku bocznym obok przestrzeni
i surowej pamięci. To pomieszanie dwóch ról: pasek boczny odpowiada na pytanie
„gdzie jest wiedza", a utrzymanie instalacji na zupełnie inne. Czytający dokument
miał maszynownię w kącie oka, a administrator musiał mijać listę przestrzeni,
żeby dojść do swojego.

Administracja ma teraz **własny układ** pod `/admin`, z własnym paskiem i jawnym
powrotem do bazy wiedzy. Wejście jest ikoną w nagłówku, widoczną wyłącznie dla
administratora globalnego. Odmowa dla osoby bez roli mieszka w układzie, nie na
pojedynczej stronie — obowiązuje każdy ekran administracyjny, a powtarzana na
każdym z osobna rozjedzie się przy pierwszym, który o niej zapomni.

Rozstrzyga to przy okazji pytanie, które wracałoby przy każdym kolejnym ekranie
z TODO-008 (użytkownicy, zaproszenia, przestrzenie, role, audyt): gdzie go
powiesić.

---
## 2026-09-13 14:48 — Aktualizacja MemPalace z panelu administratora (TODO-015)

Pałac jest przypięty na sztywno i to jest słuszne — aktualizacja dotyka wektorów,
więc nie ma się dziać przypadkiem. Skutkiem ubocznym było jednak to, że **nikt nie
wiedział, kiedy wyszło coś nowego**. Przy pisaniu zadania okazało się, że działa
3.7.0, a na PyPI od 31 sierpnia jest 3.9.0. Dwie wersje mniejsze w tyle,
i dowiedzieliśmy się o tym tylko dlatego, że ktoś ręcznie zapytał.

Teraz aplikacja sprawdza PyPI co sześć godzin, a panel (**Administracja →
Zależności**) pokazuje wersję działającą, przypiętą i najnowszą.

**Wersja działająca bierze się z MCP `initialize`, nie z `.env`.** Pałac nie ma
endpointu `/version`, ale handshake zwraca `serverInfo.version`. Zmienna mówi, co
*miało* zostać zbudowane; handshake — co **naprawdę odpowiada**. Rozjazd między
nimi znaczy, że ktoś zmienił `.env` i nie przebudował obrazu, i panel to pokazuje.

**Aktualizację wykonuje agent na hoście, nie kontener** (D-032). Rozważono gniazdo
Dockera w backendzie — odrzucone, bo kontener obsługujący ruch z sieci dostałby
władzę równoważną rootowi na hoście. Backend zapisuje tylko zlecenie; skrypt na
hoście robi kopię zapasową schematu `palace`, przebudowuje obraz, uruchamia test
semantyki i **wycofuje się**, gdy test padnie — bo zepsuta trafność wyszukiwania
jest cicha (D-003).

Cztery stany na ekranie są rozróżnione celowo: „jest nowsza", „masz najnowszą",
**„nie udało się sprawdzić"** i „agent niezainstalowany". Trzeci stoi wyżej niż
dwa pierwsze, bo panel mówiący „wszystko aktualne", gdy w rzeczywistości nie
dodzwonił się do PyPI, **kłamie**. Przy czwartym nie ma przycisku — przycisk bez
skutku uczy nie ufać interfejsowi.

Trzy rzeczy złapane dopiero przy składaniu części, nie przy pisaniu:
`MEMPALACE_VERSION` nie docierała do kontenera backendu (więc rozjazd byłby
niewidoczny właśnie wtedy, gdy zaistnieje); panel i backend przeczytały ten sam
kontrakt inaczej; a `!tagged_iterator` w konfiguracji Symfony podświetlał się
w edytorze jako błąd, choć `lint:yaml --parse-tags` mówi, że plik jest poprawny.

Wyścig dwóch kliknięć rozbija się o **indeks częściowy w bazie**, nie o
sprawdzenie w PHP — potwierdzone realnie: sześć równoległych zleceń, jedno
przechodzi. Wersja docelowa walidowana wzorcem **po obu stronach**, bo trafia do
`pip install` na hoście; `3.9.0; touch /tmp/wlamanie` jest odrzucane.

Czego **nie** zrobiono: prawdziwego podniesienia 3.7.0 → 3.9.0. To operacja
dotykająca wektorów i należy do decyzji człowieka. Ograniczenia i kruche miejsca
spisane w `TODO/DONE/015-aktualizacja-mempalace.md`.

---
## 2026-09-13 13:44 — Pełne sprawdzenie pomija zmiany wyłącznie tekstowe

Rozliczenie TODO-016 zmieniło dwa pliki tekstowe i kazało pełnemu przebiegowi
postawić cały stos na 4 min 57 s. Zmiana w `docs/`, `TODO/` albo dowolnym `.md`
nie ma czego zepsuć, więc od teraz nie stawia stosu.

Filtr jest na poziomie workflowu i jest to bezpieczne **wyłącznie dlatego**, że
pełne sprawdzenie nie jest wymaganym checkiem — gdyby było, pominięcie
zgłosiłoby się jako wiecznie oczekujące i zablokowało każdy pull request
dokumentacyjny. To ta sama pułapka, przez którą szybkie sprawdzenie ma bramkę,
tylko obrócona: tam pomijamy **zadania** wymaganego workflowu i potrzebna jest
bramka, tu pomijamy **cały workflow**, który wymagany nie jest. Zapisane
w komentarzu przy filtrze i w `docs/09-ci.md`, bo pomylenie tych dwóch przypadków
kończy się zablokowanym repozytorium.

Bezpiecznik zostaje: skrypt wypychający czeka na wszystkie checki pull requesta,
więc pełne sprawdzenie musi być zielone przed scaleniem, choć ruleset go nie
wymaga.

---
## 2026-09-13 13:36 — TODO-016 rozliczone

Gałąź na zadanie, sprawdzenia zawężone ścieżkami, ruleset na jednym checku,
pełne sprawdzenie przed scaleniem. Szczegóły i to, czego nie zrobiono, w
`TODO/DONE/016-galaz-na-zadanie.md`.

Ten wpis jest przy okazji sprawdzianem zawężania: zmienia wyłącznie `TODO/`
i `CHANGELOG.md`, więc `Testy backendu` i `Testy frontendu` powinny zostać
**pominięte**, a bramka `Wynik sprawdzenia` mimo to zielona. Gdyby pominięcie
zgłosiło się jako oczekujące zamiast jako w porządku, ten pull request nie dałby
się scalić — i o to właśnie chodziło w całym rozdzielaniu na etapy.

---
## 2026-09-13 13:29 — Sprawdzenia zawężone ścieżkami; pełne sprawdzenie przed scaleniem

Domknięcie D-031. Zadanie `Zakres zmian` porównuje gałąź z `main` i wystawia dwie
flagi, więc poprawka wyłącznie we frontendzie nie budzi już Postgresa, Composera
ani PHPStana, a zmiana samej dokumentacji nie budzi żadnego z nich. Pliki wspólne
(`docker-compose.yml`, workflowy, `Makefile`, `.env.example`) trafiają do **obu**
zakresów, bo potrafią zepsuć każdą ze stron.

Porównanie idzie do punktu rozejścia (`git diff origin/main...HEAD`), a nie do
listy plików ostatniego pusha: gałąź zadania ma kilkanaście commitów i liczy się
jej całość, inaczej push poprawiający literówkę skasowałby fakt, że dziesięć
commitów wcześniej zmieniono backend. Logika sprawdzona lokalnie na dziesięciu
przypadkach, zanim pojechała na CI.

**Dwie poprawki wyzwalaczy, obie z realnego potknięcia dzisiejszego dnia.**

Szybkie sprawdzenie miało `branches: [main]`, więc push na gałąź zadania nie
uruchamiał **niczego** aż do otwarcia pull requesta — feedback znikał dokładnie
wtedy, gdy jest najbardziej potrzebny. Teraz rusza na każdej gałęzi. Wyzwalacza
`pull_request` nie ma i to jest świadome: statusy przypinają się do commita, więc
przebieg z pusha widać w pull requeście dla tego samego SHA, a drugi wyzwalacz
dawałby dwa identyczne przebiegi. Skutek uboczny do zapamiętania: pull request
z forka nie dostanie tu sprawdzenia i nie da się go scalić — blokada zamiast
cichego przepuszczenia.

Pełne sprawdzenie ruszało wyłącznie po scaleniu na `main` i to było gorsze.
Zmiana dotykająca testów E2E i konfiguracji frontendu poszła do scalenia **bez
ani jednego uruchomienia pełnego stosu**, bo jedyne, co potrafiło ją sprawdzić,
chodziło dopiero po fakcie. Usterka integracyjna wykryta minutę po scaleniu jest
już usterką na main-ie. Teraz rusza na pull requeście, a po scaleniu również —
bo pull request sprawdza commit scalający, którego na `main` już nie ma.

Ruleset wymaga od teraz jednego checka: `Wynik sprawdzenia`. Musiało to zostać
zrobione ręcznie w interfejsie GitHuba — `PATCH /repos/.../rulesets/{id}` zwraca
404 dla tokenu z `gh auth login`, mimo zakresu `repo` i uprawnień administratora.
Odczyt tego samego zasobu działa; zapisu GitHub nie dopuszcza dla tokenów
aplikacji OAuth.

Potwierdzone przy okazji: pełne sprawdzenie na `main` przeszło (5 min 55 s,
z testami E2E), a cache modelu zapisał się **pierwszy raz w historii tego
repozytorium** — `embeddings-BAAI-bge-m3-v2`, 1278 MB.

---
## 2026-09-13 13:01 — Gałąź na zadanie; koniec omijania ochrony main-a (D-031)

Ruleset „Ochrona gałęzi głównej" istniał i wymagał pull requesta oraz zielonych
checków. I był łamany **przy każdym commicie** — skrypt wypychający pchał prosto
na `main` rolą administratora, co wypisywał zresztą sam:

```
remote: Bypassed rule violations for refs/heads/main:
remote: - Changes must be made through a pull request.
```

Reguła omijana przy każdym użyciu nie chroni przed niczym. Od teraz: **jedno
zadanie = jedna gałąź = jeden pull request = jeden merge**, nazwa gałęzi za
zadaniem (`todo-015-aktualizacja-mempalace`).

Rozważane były stałe gałęzie warstwowe (`frontend`, `backend`, `docs`) i zostały
odrzucone, bo zmiany w tym repozytorium nie dzielą się po warstwach — wymuszają
to jego własne reguły. Każda zmiana ma wpis w CHANGELOGU, a dokumentacja idzie
w tym samym commicie po polsku i po angielsku. Dzisiejsza poprawka cache'u
dotknęła sześciu plików w czterech „warstwach" naraz. Do tego CHANGELOG dopisuje
się **na górze pliku**, czyli w miejscu, w którym równolegle żyjące gałęzie
konfliktują zawsze.

`scripts/wypchnij.sh` przepisany: prowadzi całą drogę od gałęzi do scalenia —
sprawdzenia lokalne, push gałęzi, założenie pull requesta, oczekiwanie na
**wszystkie** jego checki, scalenie przez merge commit. Bez `--admin`. Stojąc na
`main` z lokalnymi commitami sam zdejmuje je na gałąź zadania.

Sprawdzenia będą zawężone ścieżkami — zmiana w samym frontendzie nie ma budzić
PHPUnita ani PHPStana. Wchodzi tu pułapka warta zapamiętania: **pominięte
zadanie nie zgłasza się jako zielone, tylko jako wiecznie oczekujące**, więc
wymaganie go wprost zablokowałoby każdy pull request, którego nie dotyczy.
Dlatego doszło jedno zadanie-bramka „Wynik sprawdzenia": wykonuje się zawsze,
zbiera wyniki pozostałych i traktuje pominięcie jako w porządku, a porażkę jako
błąd. To ono — i tylko ono — jest wymagane przez ruleset.

Przy okazji domknięta druga przyczyna pobierania 2,3 GB przy każdym przebiegu.
`actions/cache` zapisuje **tylko gdy zadanie skończyło się sukcesem**, a nasze
padało na E2E — więc poprawiona ścieżka i tak by nie pomogła. Pułapka nakręca
się sama: jeden czerwony test blokuje cache na zawsze. Odczyt i zapis są teraz
rozdzielone, zapis ma `if: always()`, ale pod warunkiem, że **test semantyki
przeszedł** — bo dopiero on dowodzi, że model wczytał się w całości, a zapisanie
przerwanego pobierania utrwaliłoby uszkodzone pliki pod tym samym kluczem.

---
## 2026-09-13 12:46 — E2E na buildzie produkcyjnym; „Nocne” staje się „Pełnym”

Poprzednia poprawka pustego ekranu edytora **nie wystarczyła** i widać to w danych.
`router.onError` zadziałał — błąd `R0010` zniknął, a w śladzie widać, że strona
faktycznie się przeładowała — ale po przeładowaniu przeglądarka wzięła przetworzony
moduł z własnej pamięci podręcznej, trafiła w ten sam nieaktualny skrót
(`v=58af5a6d`) i 504 wrócił. Drugiego przeładowania blokuje zapora przed pętlą,
więc kończyło się białą stroną.

Właściwe rozwiązanie okazało się prostsze niż walka z optymalizatorem: **testy E2E
chodzą teraz po buildzie produkcyjnym** (`FRONTEND_TARGET=prod`, statyczne `dist/`
za nginxem). Build nie ma optymalizatora, więc problem znika u źródła — a przy
okazji testujemy artefakt, który naprawdę jedzie na serwer. Lokalnie: 5,1 s zamiast
8,9 s.

Obie wcześniejsze poprawki zostają, bo mają wartość poza CI: `optimizeDeps.include`
oszczędza to samo deweloperowi, a `router.onError` dotyczy **produkcji** — po
wdrożeniu ktoś ma otwartą starą stronę i prosi o plik, którego już nie ma.

Druga rzecz z tego samego przebiegu: cache modelu **nadal się nie zapisał**, mimo
poprawionej ścieżki. Przyczyna jest w logu: `save-always: false` i brak kroku
„Post Cache”. `actions/cache` zapisuje **tylko gdy zadanie kończy się sukcesem**,
a zadanie padało na E2E. To pułapka, która sama się nakręca: jeden czerwony test
blokuje cache na zawsze, więc każdy kolejny przebieg znowu pobiera 2,3 GB.

Przy okazji przebieg „Nocne sprawdzenie pełnego stosu” zmienia się w **„Pełne
sprawdzenie”** (`nocne.yml` → `pelne.yml`). Rusza po scaleniu na main, a nie raz
na dobę — nocna pora była konsekwencją zepsutego cache'u, nie prawem natury.
Harmonogram dobowy zostaje, bo łapie to, czego push nie złapie: zależność
zewnętrzną psującą się **bez naszego commita**.

---
## 2026-09-13 12:22 — Cache modelu, który od początku zapisywał pustkę

Pytanie brzmiało, czy przebieg nocny musi pobierać 2 GB za każdym razem.
Odpowiedź: nie musiał, ale pobierał — i to od pierwszego dnia.

Krok cache istniał i wyglądał poprawnie:

```yaml
path: /tmp/model-embeddingow
key: embeddings-bge-m3-v1
```

tyle że serwer embeddingów trzyma wagi w `embeddings-cache:/data`, czyli
w **wolumenie nazwanym Dockera**, którego `actions/cache` nie widzi. Wskazany
katalog był pusty przez cały czas, więc krok zapisywał pustkę i przy każdym
przebiegu nie miał czego przywrócić. Dowód nie z rozumowania, tylko z listy
cache'ów repozytorium: 46 wpisów — `composer`, `pnpm`, CodeQL — i **ani jednego**
dla embeddingów.

Poprawki:

- ścieżka wag jest teraz konfigurowalna (`EMBEDDING_CACHE_DIR`). Domyślnie
  wolumen nazwany, bo nikt nie chce 2,3 GB w katalogu projektu; przebieg nocny
  ustawia katalog hosta, który cache potrafi objąć;
- klucz cache bierze nazwę modelu **z `.env.example`** zamiast wpisanej na
  sztywno. Poprzedni klucz mówił „bge-m3”, a model bierze się z
  `EMBEDDING_MODEL` — po jego zmianie przebieg sprawdzałby polską semantykę na
  wagach poprzedniego modelu i przeszedłby.

Osobno, bo pytanie padło wprost: **„environment” w GitHub Actions tu nie pomoże.**
To mechanizm kontroli dostępu i sekretów — reguły zatwierdzania, ograniczenie
gałęzi — a nie pamięć podręczna. Nic nie przechowuje między przebiegami.

Morał wart zapamiętania: **krok cache, który nigdy nie trafia, wygląda dokładnie
tak samo jak krok, który działa.** Nie ma ostrzeżenia, nie ma czerwonego światła;
jest tylko czas przebiegu, którego nikt nie mierzył.

---
## 2026-09-13 12:17 — Pusty ekran edytora: 504 z optymalizatora Vite

Przebieg nocny poszedł na czerwono, a oba testy E2E przewróciły się na tym samym:
na ekranie edytora nie było **niczego**. Nie błędu, nie układu strony — pustej
bieli. Zrzut z przebiegu i zapis sieciowy nie zostawiły miejsca na domysły:

```
504 (Outdated Optimize Dep)
TypeError: Failed to fetch dynamically imported module: …/DocumentEditPage.vue
[VUE_ROUTER_R0010] Uncaught error during route navigation
```

Poległo dokładnie sześć żądań: pięć pakietów `@codemirror/*` i `markdown-it` —
czyli dokładnie ten zbiór, którego nie importuje żaden ekran poza edytorem.

Mechanizm jest taki: Vite pakuje zależności raz, przy starcie, i znaczy wynik
skrótem, który wchodzi do każdego adresu modułu. Zależność pominięta w tym
przebiegu zostaje odkryta dopiero wtedy, gdy ktoś **pierwszy raz** otworzy stronę,
która ją importuje. Ponowne pakowanie zmienia skrót, więc żądania będące w locie
dostają 504, dynamiczny import odrzuca, a vue-router nie miał obsługi błędu —
i nawigacja kończyła się niczym.

Dwie poprawki, bo to dwa różne problemy:

1. **`optimizeDeps.include`** w `vite.config.ts` — te sześć zależności wchodzi do
   pierwszego przebiegu pakowania i nie ma czego odkrywać później.
2. **`router.onError`** — gdy kod strony nie chce się wczytać, aplikacja ładuje ten
   sam adres jeszcze raz, **jeden raz**, i zapamiętuje próbę w `sessionStorage`,
   żeby nie wpaść w pętlę. To nie jest łatka na powyższe: dokładnie tak samo psuje
   się **produkcja** po wdrożeniu, gdy ktoś ma otwartą starą stronę i prosi o plik,
   którego już nie ma. Dotąd taki człowiek zobaczyłby białą stronę.

Warte odnotowania: **u mnie to się nie odtwarza.** Wyczyściłem pamięć podręczną
Vite, wystartowałem kontener na zimno i puściłem E2E trzy razy — za każdym razem
zielono, bo skanowanie przy starcie wygrywa tu wyścig, którego na maszynie
przebiegu nie wygrywa. Dowodem na skuteczność poprawki jest więc CI, nie moja
maszyna, i tak to trzeba czytać.

Przy okazji: `codemirror` (pakiet zbiorczy) siedzi w zależnościach, choć nie jest
nigdzie importowany — importujemy pakiety składowe. Do usunięcia osobno.

---
## 2026-09-13 11:59 — Testy E2E i usterka, którą znalazły od razu

Playwright w `frontend/e2e/`, uruchamiany przeciwko **działającemu stosowi**:
logowanie → utworzenie → edycja → porównanie rewizji → cofnięcie, plus
sprawdzenie, że świeżo zapisany dokument jest znajdowalny. W przebiegu nocnym,
na maszynie przebiegu — Playwright potrzebuje przeglądarki z bibliotekami
systemowymi, których obraz Alpine nie ma.

**Pierwsze uruchomienie znalazło usterkę, której nie widziało nic innego.**
Adres dokumentu ze ścieżką w nazwie (`procedury/pierwsza`) wychodził jako
`procedury%2Fpierwsza`, bo vue-router koduje ukośnik wewnątrz parametru. Strona
się otwierała, więc nic nie wyglądało na zepsute — ale adres różnił się od tego,
do którego odsyłają drzewo i wyniki wyszukiwania, i to on trafiał do schowka.
Adresy dokumentów budujemy teraz jako napisy (`features/documents/paths.ts`),
z każdym odcinkiem kodowanym osobno.

Do tego **potwierdzenie przy unieważnianiu tokena agenta**, którego brakowało:
unieważnienie działa od następnego żądania agenta i nie da się go cofnąć —
jedyna droga powrotna to wystawienie nowego tokena i przekonfigurowanie tego, co
go używało. Na pojedyncze kliknięcie w liście podobnych wierszy to za mało
ceremonii.

Sam test też miał dwa błędy, oba mojego autorstwa i oba pouczające: liczyłem
wiersze przez `hasText` z kotwicą `^`, która nigdy nie dopasuje elementu
zawierającego więcej tekstu, a potem liczyłem je przez `count()`, które **na nic
nie czeka** — więc test porównywał zero z zerem i przechodziłby, gdyby nie
asercja na konkretną liczbę.

---
## 2026-09-13 11:46 — Fałszywy alarm skryptu wypychającego

Skrypt zgłosił porażkę CI, choć wszystkie trzy przebiegi były zielone. Winny był
on sam: kod Pythona jedzie w pojedynczych cudzysłowach basha, a ja napisałem
w f-stringu `\"` — ucieczki, które bash bierze dosłownie, więc Python wywracał
się na składni i kończył kodem różnym od zera, co skrypt czytał jako czerwone CI.

Ironia jest na miejscu: narzędzie do wykrywania fałszywych czerwonych świateł
samo dało fałszywe czerwone światło. Poprawione bez ucieczek — klucze
wyciągnięte do zmiennych i sklejanie zamiast f-stringu.

Poprawka niesie własny morał: **sprawdzenie, że narzędzie wykrywa porażkę, nie
wystarczy — trzeba też sprawdzić, że rozpoznaje sukces.** Ścieżkę porażki
przetestowałem (odmówiło wypchnięcia przy brakującym pakiecie), ścieżki
powodzenia nie.

---
## 2026-09-13 11:43 — TODO-008: edytor, historia i weryfikacja

Edytor Markdown (CodeMirror 6) z podglądem obok, ekran historii z porównaniem
**dowolnych dwóch** rewizji, cofanie i weryfikacja dokumentu.

**Podgląd renderuje dokładnie ten tekst, który poleci do API** — ten sam ciąg
znaków i ten sam renderer, co na ekranie dokumentu. Podgląd różniący się od
wyniku uczy ludzi, żeby mu nie ufać, a potem żeby go nie oglądać.

**Opis zmiany jest wymagany**, nie proszony. Historia bez opisów to lista dat,
a pole opcjonalne w pośpiechu jest polem pustym.

**Szkic przeżywa zamknięcie karty** — zapis do `localStorage` chwilę po
przerwie w pisaniu, przywracanie **z pytaniem**. Nigdy po cichu: szkic może być
starszy niż to, co jest na serwerze, a nadpisanie cudzej nowszej treści
zapomnianym szkicem to gorsza porażka niż utrata szkicu.

**Rewizja zapisana w trakcie edycji to ostrzeżenie, nie blokada.** Agent może
zapisać w dowolnej chwili. Odmowa zapisu straciłaby pracę człowieka, cichy zapis
— pracę agenta; więc ekran mówi, co się stało, i pozwala zdecydować.

**Historia idzie do przodu także przy cofaniu** i potwierdzenie mówi to wprost:
cofnięcie dopisuje nową rewizję z dawną treścią, nie usuwa niczego. Sprawdzone
w przeglądarce: edycja dała rewizję 2, cofnięcie do 1 dało rewizję **3**,
a wszystkie trzy zostały w historii.

**Historia pokazuje nazwiska, nie identyfikatory.** Rewizja przechowuje surowe
UUID-y (celowo — skopiowane nazwisko się starzeje, a klucz obcy do kont
uniemożliwiłby ich usuwanie), więc nowy port `AuthorDirectory` rozwiązuje je
hurtem przy odczycie. Konto usunięte zostawia rewizję na miejscu, bez nazwiska —
historia gubiąca wpisy, gdy ktoś odchodzi, jest gorsza niż historia bez nazwiska.

**Dwie usterki złapane w przeglądarce, nie w kodzie:** cofanie wysyłało pole
`revision`, a API oczekuje `toRevision` (400); w tabeli różnic numery linii były
centrowane w pionie, więc przy zawiniętym wierszu wypadały poniżej swojej
pierwszej linii — zgłoszone przez użytkownika.

---
## 2026-09-13 11:33 — Skrypt pyta GitHuba, czy to jego wina

**GitHub ma dziś krytyczną awarię** — od 09:16 UTC: Pull Requests niesprawne,
Actions, API, Issues i Pages z obniżoną wydajnością. To tłumaczy wszystkie trzy
czerwone przebiegi z tej sesji: błąd 500 przy pushu do wiki, nieudaną analizę
przyrostową CodeQL i wysyłkę wyników padającą **we wszystkich trzech językach
naraz** (analiza kończyła się poprawnie, umierało dopiero „Uploading results”).

Po naszej stronie nie ma czego naprawiać, ale ustalenie tego zajmowało za każdym
razem kilka minut grzebania w logach, w których przyczyny nie ma. Dlatego
`scripts/wypchnij.sh` przy porażce **najpierw pyta o stan GitHuba** i wypisuje
niesprawne usługi. Rozróżnienie „nasz kod jest zepsuty” od „GitHub jest zepsuty”
jest pierwszą rzeczą, którą trzeba wiedzieć, a ostatnią, którą log podaje.

---
## 2026-09-13 11:25 — Skrypt, który sprawdza lokalnie i czeka na CI

**Szybkie sprawdzenie padło na moim własnym przeoczeniu:** w rozliczeniu
TODO-007 wpisałem `**Stan:** zrobione`, a `sprawdz-zadania.py` wymaga słowa
`UKOŃCZONE` albo `ANULOWANE`. Reguła projektu zadziałała — tylko że o czerwonym
przebiegu dowiedział się człowiek, nie ja, bo wypchnąłem i poszedłem dalej.

Stąd `scripts/wypchnij.sh`: uruchamia **lokalnie to samo**, co „Szybkie
sprawdzenie” w CI, pcha dopiero po komplecie zieleni, a potem czeka na wynik
i przy porażce pokazuje ogon logu kroku, który padł. Sprawdza też plik compose,
którego CI nie rusza — zepsuty compose raz już poszedł na `main`.

To trzeci raz tego dnia, gdy czerwony przebieg zauważył ktoś inny: raz ta
literówka, dwa razy chwilowe awarie GitHuba (CodeQL i push do wiki). Skrypt nie
naprawi awarii GitHuba, ale sprawi, że zobaczę je od razu — i to jest cała
różnica między „wiem i czekam” a „nie wiem”.

Reguła dopisana do `AGENTS.md` i `AGENTS.en.md`.

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
