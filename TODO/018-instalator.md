---
tags: [ws-memory, todo, instalacja, docker, deployment, operacje]
---

# TODO-018 — Instalator i deinstalator

**Utworzono:** 2026-09-13 19:45 · **Stan:** 🔵 **W TOKU — punkty 1–6 i 8 z 8** (2026-09-13 21:25) · **Zależności:** 017

## Powód

Postawienie instancji wymaga dziś przeczytania `docs/05-deployment.md`,
skopiowania `.env.example`, ręcznego wygenerowania **pięciu** sekretów,
uruchomienia compose, migracji, wygenerowania kluczy JWT i przejścia ścieżki
zaproszenia, żeby w ogóle mieć konto. Każdy z tych kroków da się pominąć albo
zrobić źle, a większość milczy, gdy się to stanie.

Sprawdzone dziś na własnej skórze: **nikt nie wiedział, jakie jest hasło
administratora**, bo instancja powstała bez zapisania go gdziekolwiek. Nie było
też polecenia, którym dałoby się je ustawić — dołożone dopiero teraz.

Drugi powód jest równie praktyczny: **nie ma jak tego usunąć.** Po próbie
instalacji zostają kontenery, wolumeny, obrazy, wygenerowany `.env` i wpisy
w systemie. Ktoś, kto chce zacząć od nowa, zaczyna od zgadywania, co po sobie
zostawił.

## Analiza

### Dwie drogi instalacji nie są równorzędne i trzeba to powiedzieć

**Docker** to droga wspierana: siedem usług, w tym Postgres z pgvector,
`text-embeddings-inference` i MemPalace. Wszystkie mają przypięte wersje
i znane zachowanie.

**Instalacja w systemie** nie może udawać, że zrobi to samo. Instalator **nie
zainstaluje** Postgresa z pgvector ani modelu embeddingów na dowolnej
dystrybucji — a jeśli spróbuje, zawiedzie w połowie i zostawi stan, którego nikt
nie umie opisać. Ta droga ma więc: **wykryć** wymagane składniki, powiedzieć
wprost, czego brakuje i jak to zdobyć, a potem zainstalować **samą aplikację**
(backend, frontend, jednostki systemd, konfigurację nginxa) na tym, co już jest.

Obiecywanie więcej byłoby obietnicą, której nie da się dotrzymać na maszynie,
której się nie widziało.

### Instalator pyta o wszystko raz, sekrety generuje sam

Sekret, o który instalator pyta, jest sekretem wpisanym z palca — czyli słabym
albo zapisanym w drugim miejscu. `APP_SECRET`, hasła do bazy, `JWT_PASSPHRASE`
i token MCP **generuje**, nie pyta o nie. Pyta o to, czego nie da się zgadnąć:
adres publiczny, port, dane administratora, SMTP (TODO-017), wersja MemPalace.

**Hasło administratora ma być pokazane raz i zapisane tam, gdzie użytkownik je
znajdzie** — powtórzenie dzisiejszej sytuacji, w której hasło zniknęło razem
z terminalem, jest dokładnie tym, czemu to zadanie ma zapobiec.

### Deinstalator musi być trudniejszy niż instalator

Usuwa dane. Ma pytać o potwierdzenie **wpisaniem nazwy instancji**, nie
klawiszem `t`, i domyślnie **zostawiać kopie zapasowe** oraz wolumen z modelem
embeddingów (2 GB, pobierane godzinami). Osobne, jawne opcje na usunięcie
jednego i drugiego.

### Instalator nie może być drugim opisem prawdy

Lista zmiennych żyje w `.env.example`, a kroki w `docs/05-deployment.md`.
Instalator, który powtarza je u siebie, rozjedzie się z nimi przy pierwszej
zmianie. **Czyta `.env.example`** jako źródło listy zmiennych, zamiast trzymać
własną kopię.

## Rozwiązanie

- [x] **1.** `scripts/instaluj.sh` — pyta o drogę (Docker / w systemie), zbiera dane,
      generuje sekrety, zapisuje `.env`, stawia i sprawdza.
- [x] **2.** Sprawdzenie **przed** czymkolwiek: wersje narzędzi, wolne porty, miejsce na
      dysku, uprawnienia do Dockera. Braki wypisane naraz, nie po jednym.
- [x] **3.** **Tryb nieinteraktywny** (`--plik-odpowiedzi`) — instalacja bez człowieka
      i możliwość powtórzenia dokładnie tej samej instalacji.
- [x] **4.** Konto administratora zakładane poleceniem konsoli, hasło pokazane raz
      i zapisane w pliku o prawach `600` z jawną informacją, gdzie leży.
- [x] **5.** **Sprawdzenie po instalacji**: logowanie działa, `/mcp` odpowiada, pałac
      odpowiada, wyszukiwanie semantyczne zwraca sensowny wynik
      (`test/semantyka.sh`). Instalator, który kończy się „gotowe" bez sprawdzenia,
      przenosi porażkę na pierwszego użytkownika.
- [x] **6.** `scripts/odinstaluj.sh` — potwierdzenie nazwą instancji, kopia zapasowa przed
      usunięciem, wypisanie **co dokładnie** zostanie usunięte i co zostanie.
- [ ] **7.** Instalacja w systemie: wykrycie składników, jednostki systemd, konfiguracja
      nginxa, użytkownik systemowy — na istniejącym Postgresie i PHP.
- [x] **8.** `README.md` i `docs/05-deployment.md` opisują obie drogi, z jawnym
      powiedzeniem, która jest wspierana.

## Postęp

Droga **dockerowa** jest zrobiona i sprawdzona w tym, co da się sprawdzić bez
drugiej maszyny: wymagania wstępne (z zajętym portem włącznie), rozmowa, plik
odpowiedzi w obie strony, wygenerowany `.env` (prawa 600, sześć sekretów po 32
znaki, komentarze z `.env.example` zachowane, plik daje się sourceować) oraz
odmowa nadpisania istniejącej konfiguracji.

**Sprawdzone pełnym przebiegiem na osobnej instancji** — świeży klon,
`COMPOSE_PROJECT_NAME=ws-memory-proba`, własne wolumeny, port 18099, obok
działającego stosu i bez dotykania go. Instalacja: pięć sprawdzeń na pięć,
z wyszukiwaniem znaczeniem włącznie, logowanie hasłem, które instalator wypisał.
Świeża baza: 1 konto, 2 przestrzenie, 5 wpisów audytu, 1 szuflada w pałacu.
Deinstalacja: kopia zapasowa (16 KB), zero kontenerów, zero wolumenów poza
modelem, stos główny nietknięty.

Przebieg znalazł **trzy błędy, których nie znalazłby żaden przegląd kodu**:

1. **Świeży klon nie ma `vendor/`.** Instalator wywracał się na kluczach JWT
   komunikatem „Dependencies are missing". `docker-compose.yml` montuje
   `./backend` z hosta, więc nawet obraz produkcyjny dostaje swoje zależności
   przysłonięte. Instalator uruchamia teraz `composer install` **jako UID
   człowieka**, bo obraz dev działa jako root, a `vendor` założony rootem
   w bind moncie to katalog, którego właściciel maszyny nie ruszy.
2. **Instalator nie umiał dokończyć własnej przerwanej instalacji**: port
   trzymał jego własny nginx z poprzedniego przebiegu, a kontrola portów
   meldowała konflikt. Port zajęty przez kontener tego samego projektu nie jest
   już konfliktem.
3. **Sprawdzenia startowały przed pobraniem modelu.** Trzy pozycje na czerwono
   na instancji, która była w trakcie startu — instalator kłamał w drugą stronę
   niż zwykle, mówiąc „nie działa" o czymś, co za chwilę działa. Zmierzone:
   346 s na same wagi ONNX. Teraz czeka na pałac i na embeddingi.

Kryterium o przerwaniu w połowie odhaczone **z przebiegu, nie z założenia**:
pierwsza próba padła na kluczach JWT, zostawiając kontenery i `.env`; kolejne
przebiegi to dokończyły, a deinstalator posprzątał wszystko.

**Czego nie sprawdziłem:** instalacji na maszynie bez Dockera w ogóle — do tego
służy maszyna testowa z punktu 7.

**Punkt 7 (instalacja w systemie) nie jest zrobiony** i skrypt mówi to wprost:
sprawdza wymagania, wypisuje braki i kończy się kodem 3. Kod instalujący
jednostki systemd i konfigurację nginxa, którego nie da się na niczym uruchomić,
byłby dokładnie tym, przed czym ostrzega analiza tego zadania — zawiódłby
w połowie i zostawił stan, którego nikt nie umie opisać.

Po drodze złapany błąd, który sam w sobie uzasadnia pisanie deinstalatora
ostrożnie: pierwsza wersja brała nazwę projektu Compose z nazwy katalogu,
a `docker-compose.yml` ustawia `name: ws-memory`. Filtr wolumenów po złej nazwie
nie znajdował **żadnego**, więc deinstalator wypisywał „wolumeny danych: nie ma
żadnego" i kończył słowem „odinstalowane", zostawiając całą bazę wiedzy na
dysku. Nazwę podaje teraz `docker compose config`.

## Kryteria ukończenia

- [x] Na czystej maszynie z Dockerem `./scripts/instaluj.sh` daje **działającą
  instancję**, do której da się zalogować danymi, które wypisał.
- [x] Instalator uruchomiony drugi raz na istniejącej instancji **nie niszczy
  danych** — mówi, co zastał, i pyta.
- [x] Przerwanie instalatora w połowie (Ctrl+C) nie zostawia stanu, którego
  deinstalator nie umie posprzątać.
- [x] Brakujący składnik jest zgłoszony **przed** pierwszą zmianą w systemie.
- [x] `./scripts/odinstaluj.sh` usuwa wszystko, co instalator utworzył, a `docker
  ps -a`, `docker volume ls` i katalog projektu nie zawierają po nim śladów
  poza tym, co jawnie zostawił.
- [x] Deinstalator **nie usuwa kopii zapasowych** bez jawnej opcji.
- [x] Hasło administratora jest zapisane w pliku o prawach `600`, poza
  repozytorium, i instalator mówi gdzie.
- [x] Żaden sekret nie trafia do `.env.example` ani do historii powłoki.
- [x] Plik odpowiedzi pozwala powtórzyć instalację bez pytań.
