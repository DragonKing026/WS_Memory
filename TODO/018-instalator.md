---
tags: [ws-memory, todo, instalacja, docker, deployment, operacje]
---

# TODO-018 — Instalator i deinstalator

**Utworzono:** 2026-09-13 19:45 · **Stan:** do zrobienia · **Zależności:** 017

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

1. `scripts/instaluj.sh` — pyta o drogę (Docker / w systemie), zbiera dane,
   generuje sekrety, zapisuje `.env`, stawia i sprawdza.
2. Sprawdzenie **przed** czymkolwiek: wersje narzędzi, wolne porty, miejsce na
   dysku, uprawnienia do Dockera. Braki wypisane naraz, nie po jednym.
3. **Tryb nieinteraktywny** (`--plik-odpowiedzi`) — instalacja bez człowieka
   i możliwość powtórzenia dokładnie tej samej instalacji.
4. Konto administratora zakładane poleceniem konsoli, hasło pokazane raz
   i zapisane w pliku o prawach `600` z jawną informacją, gdzie leży.
5. **Sprawdzenie po instalacji**: logowanie działa, `/mcp` odpowiada, pałac
   odpowiada, wyszukiwanie semantyczne zwraca sensowny wynik
   (`test/semantyka.sh`). Instalator, który kończy się „gotowe" bez sprawdzenia,
   przenosi porażkę na pierwszego użytkownika.
6. `scripts/odinstaluj.sh` — potwierdzenie nazwą instancji, kopia zapasowa przed
   usunięciem, wypisanie **co dokładnie** zostanie usunięte i co zostanie.
7. Instalacja w systemie: wykrycie składników, jednostki systemd, konfiguracja
   nginxa, użytkownik systemowy — na istniejącym Postgresie i PHP.
8. `README.md` i `docs/05-deployment.md` opisują obie drogi, z jawnym
   powiedzeniem, która jest wspierana.

## Kryteria ukończenia

- Na czystej maszynie z Dockerem `./scripts/instaluj.sh` daje **działającą
  instancję**, do której da się zalogować danymi, które wypisał.
- Instalator uruchomiony drugi raz na istniejącej instancji **nie niszczy
  danych** — mówi, co zastał, i pyta.
- Przerwanie instalatora w połowie (Ctrl+C) nie zostawia stanu, którego
  deinstalator nie umie posprzątać.
- Brakujący składnik jest zgłoszony **przed** pierwszą zmianą w systemie.
- `./scripts/odinstaluj.sh` usuwa wszystko, co instalator utworzył, a `docker
  ps -a`, `docker volume ls` i katalog projektu nie zawierają po nim śladów
  poza tym, co jawnie zostawił.
- Deinstalator **nie usuwa kopii zapasowych** bez jawnej opcji.
- Hasło administratora jest zapisane w pliku o prawach `600`, poza
  repozytorium, i instalator mówi gdzie.
- Żaden sekret nie trafia do `.env.example` ani do historii powłoki.
- Plik odpowiedzi pozwala powtórzyć instalację bez pytań.
