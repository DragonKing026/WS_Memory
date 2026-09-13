---
noteId: "be5f5841aeb111f1997d030a3cd38ca7"
tags: [ws-memory, dokumentacja, deployment, docker, backup, operacje]

---

# Deployment

Stan: **działa** (2026-09-12). `docker-compose.yml` obejmuje sześć usług;
brakuje `frontend` (TODO-006) i konfiguracji TLS dla produkcji.

## Wymagania serwera

| Zasób | Minimum | Zalecane | Dlaczego |
|---|---|---|---|
| RAM | 6 GB | 12 GB | model `bge-m3` zajmuje ~2–3 GB **po załadowaniu** (zmierzone); Postgres z HNSW lubi cache |
| CPU | 4 rdzenie | 8 rdzeni | liczenie wektorów przy mieleniu repozytoriów |
| Dysk | 40 GB | 100 GB SSD | baza rośnie z wolumenem wiedzy; wektory 1024-wymiarowe |
| Docker | Engine 24+ z Compose v2 | | |

GPU nie jest potrzebne. Usługę `embeddings` można przenieść na maszynę z GPU
bez zmiany czegokolwiek innego — to jedyny komponent liczący wektory.

**Każda usługa ma twardy `mem_limit`** i nie jest to strojenie wydajności,
tylko zabezpieczenie. Przy pierwszym uruchomieniu serwer embeddingów z
domyślnymi buforami TEI zajął 21 GB i zdławił maszynę. Bufory są dostrojone
(`--max-batch-tokens 2048`, `--tokenization-workers 2`, `--auto-truncate`),
ale limit zostaje jako druga linia obrony: kontener ma zginąć sam, a nie
zabrać ze sobą serwer.

**Czynnik decydujący o jej mocy:** od D-014 wysyłka z lokalnych pałaców jest
domyślna, więc serwer liczy wektory dla **całego** strumienia wiedzy wszystkich
maszyn, a nie dla wybranych fragmentów. Przy kilkunastu osobach mielących
projekty i rozmowy to stały ruch w tle, nie okazjonalne skoki. Jeśli kolejka
publikacji zaczyna rosnąć — najpierw dokładamy rdzenie usłudze `embeddings`,
dopiero potem myślimy o GPU.

## Uruchomienie

```bash
git clone <repo> ws-memory && cd ws-memory
cp .env.example .env
./docker/wygeneruj-sekrety.sh    # losowe hasła i tokeny
make start                       # pierwszy start ~3 min (pobranie modelu)
make migracje
```

Weryfikacja, że wszystko żyje:

```bash
make test-semantyka              # polskie zapytanie znajduje polską treść
curl http://127.0.0.1:8080/api/health
```

Pierwsze konto administratora zakłada się z konsoli:
`ws:user:invite ty@firma.pl --admin` — patrz niżej.

## Operacje administracyjne z konsoli

Instancja ma stany, z których interfejs nie wyprowadzi: nikt nie ma jeszcze
konta albo nikt nie umie się już zalogować. Na to jest konsola backendu —
wszystkie polecenia uruchamiamy w jego kontenerze:

```bash
docker compose exec backend php bin/console <polecenie>
```

| Polecenie | Kiedy się go używa |
|---|---|
| `ws:user:invite <email> [--admin]` | pierwsze konto w instancji i każde następne; wypisuje link, bo pierwsze zaproszenie powstaje zwykle przed konfiguracją poczty |
| `ws:user:password <email> [--password=…]` | **odzyskanie dostępu do instancji** — hasło konta, którego nikt już nie pamięta |
| `ws:agent:token <email> <etykieta> [--space=…] [--expires=…]` | podłączenie agenta AI; wypisuje gotowe `claude mcp add` razem z tokenem, jeden raz |
| `ws:agent:list <email>` | co to konto ma do odwołania: identyfikator, etykieta, ostatnie użycie, stan |
| `ws:agent:revoke <email> <identyfikator>` | **sprzątanie po tokenie** wystawionym do jednorazowej pracy z konsoli |
| `ws:dependency:check` | sprawdzenie tu i teraz, jaka wersja MemPalace działa i jaka jest najnowsza |
| `ws:updater:claim`, `ws:updater:finish`, `ws:updater:heartbeat` | rozmowa z agentem aktualizacji na hoście (D-032), nie do ręcznego użytku |

### Odzyskanie dostępu: `ws:user:password`

```bash
docker compose exec backend php bin/console ws:user:password ty@firma.pl
```

Używa się go wtedy, gdy do instancji nie da się wejść: konto administratora bez
hasła, poczta jeszcze nieskonfigurowana albo nikt już nie czyta tej skrzynki.
Zanim to polecenie istniało, jedynym wyjściem było wystawienie zaproszenia na
inny adres — stare konto zostawało zablokowane, a obok niego powstawało drugie.

Cztery rzeczy, których nie widać z samego wywołania:

- **Bez `--password` polecenie hasło generuje** i wypisuje je jeden raz. To nie
  wygoda, a zabezpieczenie: hasło podane w argumencie zostaje w historii powłoki
  maszyny, na której je wpisano, i przeżywa każdy powód, dla którego je ustawiono.
  `--password` istnieje, ale jest wyjątkiem — polecenie mówi wtedy wprost, że trzeba
  posprzątać historię.
- **Reguła hasła jest ta sama co przy przyjmowaniu zaproszenia**: co najmniej
  12 znaków i hasło nieznane z publicznych wycieków. Pilnuje jej jedna klasa
  (`PasswordPolicy`), a nie dwie kopie, które kiedyś by się rozjechały. Hasło
  odrzucone kończy się kodem wyjścia 2 i **nie zmienia niczego**.
- **Zmiana hasła nikogo nie wylogowuje.** JWT jest bezstanowe (D-017), więc
  wydane tokeny działają do wygaśnięcia, a tokeny agentów tego konta działają
  dalej tak samo. Żeby odciąć dostęp natychmiast, **wyłącz konto** — to odwołuje
  wszystkie jego tokeny agentów.
- **Konto wyłączone hasło dostaje**, ale się nim nie zaloguje, i polecenie mówi
  to wprost. Odmowa byłaby tu gorsza: hasło jest połową powrotu takiej osoby i
  samo nie nadaje żadnego dostępu, więc wpis w audycie nie ma o czym skłamać.
  (Inaczej niż przy nadaniu roli wyłączonemu kontu, które jest odrzucane — tam
  wpis mówiłby o dostępie, którego nikt nie ma.)

W audycie zostaje akcja `user.password_reset` **bez aktora**: z konsoli nikt nie
jest zalogowany, a wpisanie samego konta czytałoby się jak „sam sobie zmienił
hasło". W `target` stoi adres konta i to, czy było w tym momencie włączone.

### Sprzątanie po tokenie agenta: `ws:agent:list` i `ws:agent:revoke`

```bash
docker compose exec backend php bin/console ws:agent:list ty@firma.pl
docker compose exec backend php bin/console ws:agent:revoke ty@firma.pl 0191b8d2-…
```

Token wystawiony „na jedną robotę" trzeba potem odwołać, a jego identyfikator
został w wyjściu `ws:agent:token`, które dawno przewinęło się z ekranu — dlatego
wypisywanie i odwoływanie idą w parze. Są to **dwa polecenia**, nie jedno z
flagą: polecenie, które z flagą czyta, a bez niej niszczy, jest o jedną literówkę
od zdjęcia agenta z pracy w jej trakcie.

- Odwołanie działa **od następnego wywołania** agenta — token sprawdzany jest
  przy każdym żądaniu, nic nie trzeba restartować.
- Odwołać da się **tylko token wskazanego konta**. Cudzy odpowiada dokładnie tak
  samo jak nieistniejący (`Nie ma takiego tokena.`), bo inaczej konsola byłaby
  narzędziem do enumerowania cudzych poświadczeń po identyfikatorze.
- Powtórzone odwołanie kończy się powodzeniem i **nie przesuwa** zapisanego
  momentu odwołania — to jedyna data, którą czyta przegląd incydentu.
- Lista nigdy nie pokazuje samego tokena. W bazie jest wyłącznie jego skrót,
  więc nie ma czego pokazać.

Do tej pory token z konsoli odwoływało się `UPDATE`-em wprost w bazie, czyli
z pominięciem audytu i reguły „tylko własny token". Polecenie woła tę samą
usługę co `DELETE /api/agent-tokens/{id}`, więc obie drogi zostawiają ten sam
ślad.

## Zmienne środowiskowe

| Zmienna | Rola |
|---|---|
| `WS_PUBLIC_URL` | publiczny adres aplikacji **ze schematem** — z niego budują się linki w mailach; pusty = maile z linkami się nie wysyłają (TODO-017) |
| `MAILER_DSN` | serwer poczty; `null://null` = nie wysyłamy nic |
| `MAIL_FROM`, `MAIL_FROM_NAME` | nadawca w nagłówku `From` |
| `POSTGRES_PASSWORD` | hasło roli nadrzędnej bazy |
| `MEMPALACE_DB_PASSWORD` | hasło roli `mempalace` (schemat `palace`) |
| `WS_DB_PASSWORD` | hasło roli `ws_app` (schemat `ws`) |
| `APP_SECRET` | sekret aplikacji Symfony |
| `EMBEDDING_MEM_LIMIT`, `EMBEDDING_MAX_BATCH_TOKENS` | limity i bufory serwera embeddingów — patrz niżej |
| `JWT_SECRET_KEY` / `JWT_PUBLIC_KEY` | podpisywanie tokenów ludzi |
| `MEMPALACE_MCP_HTTP_TOKEN` | token między backendem a mempalace; **nigdy nie wychodzi na zewnątrz** |
| `MEMPALACE_BACKEND` | `pgvector` |
| `MEMPALACE_PGVECTOR_DSN` | połączenie mempalace do Postgresa |
| `MEMPALACE_EMBEDDING_MODEL` | `openai-compat` |
| `MEMPALACE_EMBEDDING_API_URL` | `http://embeddings/v1/embeddings` |
| `MEMPALACE_EMBEDDING_API_MODEL` | `BAAI/bge-m3` |
| `MEMPALACE_ENTITY_LANGUAGES` | `pl,en` — wykrywanie encji; domyślnie `en`, patrz D-011 |
| `MEMPALACE_TIMEOUT` | limit czasu na jedno wywołanie narzędzia pałaca, w sekundach (domyślnie 15, ustawiany w `backend/.env`) |
| `MCP_CALLS_PER_MINUTE` | ile wywołań na minutę może wykonać jeden token agenta (domyślnie 120, `backend/.env`) |
| `WS_INSTRUCTIONS_DIR` | katalog z treścią instrukcji wystawianą jako zasoby MCP — patrz niżej |
| `WS_DEFAULT_SPACE_SLUG` | wspólna przestrzeń, do której trafia **każde** nowe konto (domyślnie `wiedza`); pusta wartość wyłącza mechanizm — D-035 |
| `WS_DEFAULT_SPACE_NAME` | jej nazwa widoczna w interfejsie (domyślnie `Baza wiedzy`) |
| `WS_DEFAULT_SPACE_ROLE` | rola, z jaką konto do niej wchodzi (domyślnie `writer`; nieczytelna wartość degraduje się do `reader`) |
| `FRONTEND_TARGET` | `dev` (Vite z przeładowaniem na gorąco) albo `prod` (statyczne `dist/` w nginxie) |
| `WS_DEV_PORT` | port, pod którym przeglądarka widzi aplikację; websocket HMR musi być ogłoszony na nim, nie na porcie Vite |

> **MemPalace zatrzymujemy SIGINT-em, nie SIGTERM-em.** Na SIGTERM nie reaguje:
> Docker czekał dziesięć sekund i zabijał go SIGKILL-em, co dawało kod wyjścia
> **137** wyglądający w interfejsie jak awaria. Na SIGINT kończy się czysto
> w 0,2 sekundy — stąd `stop_signal: SIGINT` w `docker-compose.yml`. Danych to
> nie dotyczyło (pisze do Postgresa), ale każde zatrzymanie stosu trwało
> dziesięć sekund dłużej niż powinno.

> **Usługa `worker` jest od `TODO-005` niezbędna, nie opcjonalna.** Publikacja
> dokumentów do pałaca idzie przez kolejkę; zatrzymany worker nie psuje zapisu
> w wiki, ale dokumenty przestają być wyszukiwalne semantycznie i nikt tego nie
> zauważy poza brakiem trafień. Zlecenia czekają w `ws.messenger_messages`
> i zostaną wykonane po uruchomieniu — są idempotentne (D-025).
| `MAILER_DSN` | zaproszenia i powiadomienia |

Trzy zmienne embeddingów (`MEMPALACE_EMBEDDING_*`) są **nierozdzielne** — patrz
D-003. Zmiana modelu unieważnia wszystkie wektory w bazie.

`WS_INSTRUCTIONS_DIR` wskazuje katalog z **treścią instrukcji dla agentów** —
protokołem recall, zasadami dokumentowania, opisami podagentów — którą gateway
wystawia jako zasoby MCP (`docs/03-mcp-gateway.md`). Treść ma jedno źródło,
`plugin/shared/` (D-013), ale w każdym środowisku leży gdzie indziej:

| Gdzie | Ścieżka | Skąd się tam bierze |
|---|---|---|
| testy na hoście i w CI | `../plugin/shared` względem `backend/` | wartość domyślna, zmiennej się nie ustawia |
| kontenery `backend` i `worker` | `/opt/ws-memory/instrukcje` | wolumen `./plugin/shared:/opt/ws-memory/instrukcje:ro` z `docker-compose.yml` |
| obraz produkcyjny | `/opt/ws-memory/instrukcje` | `COPY plugin/shared/` w etapie `prod`, ze `ENV` w obrazie |

W obrazie produkcyjnym jest to **kopia, nie wolumen**: obraz ma być
samowystarczalny. Brakujący albo niepodmontowany katalog jest **błędem**, nie
pustą listą zasobów — instrukcja wczytana jako pusty tekst czyta się dla modelu
jak „nie ma żadnego protokołu" i agent po prostu jedzie dalej.

`MEMPALACE_TIMEOUT` trzymamy **krótki celowo**. Przez czas oczekiwania na pałac
otwarta jest transakcja bazy (D-020), więc hojny limit nie daje pewniejszego
zapisu, tylko dłużej trzymany wiersz. Jeśli zapisy zaczynają przekraczać limit,
problemem jest serwer embeddingów, nie ta liczba.

## Sekrety a publiczne repozytorium

Repozytorium jest **publiczne**. Wynikają z tego dwie zasady, obie twarde:

1. **Żaden plik commitowany nie zawiera prawdziwego sekretu.** `backend/.env`
   trzyma wyłącznie wartości zastępcze; realne podaje `docker-compose.yml`
   z głównego `.env`, którego nie ma w repozytorium.
2. **Wartości domyślne z repozytorium nigdy nie idą na produkcję.**
   `./docker/wygeneruj-sekrety.sh` losuje komplet przy pierwszym uruchomieniu.

W historii repozytorium znajdują się dwie wartości sprzed przejścia na
publiczne: `JWT_PASSPHRASE` i `APP_SECRET` z przepisu Symfony Flex. Obie zostały
wymienione, a klucz prywatny JWT nigdy nie był commitowany, więc nie chronią już
niczego. **Historii nie przepisujemy** — zmiana wypchniętych commitów rozjeżdża
kopie u wszystkich, a korzyść jest zerowa, skoro wartości są martwe.

Gdyby kiedyś wyciekł sekret **nadal używany**: najpierw go wymień w działającym
systemie, dopiero potem rozważaj historię. Kolejność odwrotna zostawia działający
klucz w rękach osoby, która już go ma.

## TLS

`nginx` z Let's Encrypt (`certbot` w trybie webroot). Jedyne wystawione porty
to 80 (przekierowanie i odnowienia) i 443. Żadna inna usługa nie ma mapowania
portu na host (D-006).

## Backup

**Jedno polecenie obejmuje cały system** — to główna korzyść z trzymania pałaca
w Postgresie (D-002):

```bash
docker compose exec -T postgres pg_dump -U postgres --format=custom ws_memory \
  > backup-$(date +%F-%H%M).dump
```

Obejmuje: dokumenty, wszystkie rewizje, konta, uprawnienia, audyt **i pałac**
(wektory razem z metadanymi). Odtworzenie:

```bash
docker compose exec -T postgres pg_restore -U postgres -d ws_memory --clean < backup.dump
```

Poza bazą do skopiowania zostają tylko klucze JWT — serwer nie trzyma żadnych
surowych materiałów źródłowych.

**Harmonogram:** dzienny `pg_dump` z retencją 30 dni, tygodniowy z retencją
roczną. Odtworzenie z backupu należy przetestować — backup nieprzetestowany to
nie backup.

## Aktualizacja MemPalace

MemPalace jest czarną skrzynką za granicą HTTP MCP (D-001), więc aktualizacja
nie dotyka naszego kodu. Wersja jest przypięta w `.env` (`MEMPALACE_VERSION`)
i instalowana z PyPI przy budowaniu obrazu.

### Z panelu administratora

Aplikacja sprawdza PyPI co sześć godzin i pokazuje w panelu
(**Administracja → Zależności**) wersję działającą, przypiętą i najnowszą.
Przycisk zakłada zlecenie; wykonuje je **agent działający na hoście**, bo żaden
kontener nie dostaje dostępu do Dockera (D-032). Agent robi kopię zapasową
schematu `palace`, przebudowuje obraz, wymienia kontener, uruchamia test
semantyki i **wycofuje się**, gdy test padnie.

Wymaga to jednorazowej instalacji jednostki systemd — opisanej w
`docker/systemd/README.md`. Dopóki jej nie ma, panel mówi wprost, że aktualizator
jest niedostępny, i **nie pokazuje przycisku**, który i tak by nic nie zrobił.
Samo sprawdzanie wersji działa bez agenta.

Warto znać jedno ograniczenie automatycznego wycofania: cofa ono **wersję
obrazu, nie zawartość bazy**. Gdyby nowsza wersja pałaca zmigrowała schemat
`palace`, stary obraz może go nie zrozumieć — wtedy potrzebna jest ręczna
interwencja, a agent wypisuje w dzienniku gotowe polecenie `pg_restore`.

### Ręcznie

1. Backup bazy.
2. Podniesienie `MEMPALACE_VERSION` w `.env`, przebudowa obrazu.
3. `docker compose up -d mempalace`, sprawdzenie `/healthz`.
4. `./test/semantyka.sh` — jeśli przechodzi, wektory są nietknięte.

Czego przy aktualizacji **nie wolno**: zmienić modelu embeddingów „przy okazji".
To osobna operacja z przeliczeniem całej bazy.

## Wdrożenie mostka z lokalnych pałaców (TODO-012)

Migracja **`Version20260913000005`** zakłada `ws.mirrors`, `ws.publish_settings`
i `ws.publish_batches` oraz dokłada do `ws.memory_entries` klucz obcy
`fk_entries_batch` (odroczony) i indeks częściowy `idx_entries_batch`. Kolumny
mostka — `source_replica`, `source_drawer_id`, `publish_batch_id`,
`content_hash` — istnieją od `Version20260912000003` i migracja ich nie rusza.

Jest **wstecznie odwracalna**: `down()` zdejmuje dokładnie te trzy tabele, klucz
obcy i indeks, nie dotykając danych `memory_entries`. Sprawdzone w dół i z
powrotem w górę.

```bash
make migracje                                     # albo:
docker compose exec backend php bin/console doctrine:migrations:migrate
```

Żadnej nowej zmiennej środowiskowej. Publikacja nie zależy od konfiguracji —
`auto_publish` mieszka w bazie (`ws.publish_settings`), a nie w `.env`, bo jest
ustawieniem **na użytkownika i replikę**, nie na instalację.

### Obciążenie usługi `embeddings` po włączeniu mostka

To najważniejszy skutek operacyjny D-014 i wart osobnej uwagi przy doborze mocy.
Serwer liczy wektory dla **całego** strumienia z wszystkich maszyn, nie dla
wybranych fragmentów: każda szuflada z każdego lokalnego pałaca przechodzi przez
`/v1/embeddings`.

Dwa mechanizmy zmniejszają to z góry, i oba są w tej migracji:

- **para źródłowa** (`uniq_entries_source`) — powtórna wysyłka tej samej
  lokalnej szuflady aktualizuje wiersz, więc kolejka wyjściowa może ponawiać
  bez końca. Indeks obejmuje **właściciela wpisu**, nie samą parę
  (`Version20260913000006`), i to nie jest szczegół: para pochodzi w całości od
  nadawcy, więc bez właściciela wystarczyło podać cudzą nazwę repliki, żeby
  dostać cudzy wiersz do nadpisania. Przy okazji dwie osoby mogą mieć tę samą
  parę — nazwy replik wybiera się lokalnie i nic ich nie uzgadnia;
- **odsiew po skrócie treści** (`idx_entries_dedup`) — trzy osoby mielące to
  samo repozytorium płacą za wektor raz **w obrębie przestrzeni docelowej**.
  W trzech prywatnych przestrzeniach nadal będą trzy kopie; jedno potwierdzone
  mapowanie sprowadza je do jednej.

Ograniczenie po stronie żądania: **200 szuflad na partię**
(`PublishService::MAX_DRAWERS`). Większa partia dostaje `400` z komunikatem, żeby
podzielić wysyłkę — transakcja trzyma blokady wierszy przez czas liczenia
wszystkich wektorów w partii, a żądanie, które po kwadransie odpada z niczym
zapisanym, jest gorsze niż dwa żądania.

### Trasa z dwoma poświadczeniami

`POST /api/publish` przyjmuje **token agenta albo JWT**, i jest jedyną taką
trasą (D-036). Decyduje prefiks: `Authorization: Bearer wsm_…` idzie do
authenticatora tokenów agenta, cokolwiek innego do listenera JWT. Przy diagnozie
`401` na tej trasie warto sprawdzić najpierw, czy token ma ten prefiks — bez
niego żądanie jest traktowane jako JWT i odbija się o wygasły podpis, a komunikat
mówi wtedy o czymś innym, niż jest zepsute.

## Znane ograniczenie: narzędzia schematu Doctrine

`doctrine:schema:validate` (pełny) i `doctrine:migrations:diff` **nie działają**
w tym składzie pakietów: DoctrineBundle 3 wymaga API `Schema::edit()` z DBAL
**^4.5**, a najnowszym stabilnym wydaniem jest 4.4.4. Nie jest to skutek naszej
konfiguracji — sprawdzone, że błąd występuje także po usunięciu `schema_filter`.

Do czasu wydania DBAL 4.5:

- migracje **piszemy ręcznie** (i tak są przez to jawniejsze),
- poprawność mapowania sprawdzamy przez `doctrine:schema:validate --skip-sync`,
- `schema_filter` zostaje w konfiguracji, bo zadziała, gdy DBAL się ukaże.

Filtr nie jest ozdobnikiem: rola `ws_app` **widzi** tabele w schemacie `palace`,
więc bez niego generator różnic zaproponowałby kiedyś ich usunięcie.

## Pułapka: filtr schematu a `search_path`

`schema_filter` w Doctrine porównuje wzorzec z nazwami tabel **tak, jak zwraca
je DBAL** — a te zależą od `search_path`. Rola `ws_app` ma `search_path =
ws, public`, więc własne tabele wracają **bez kwalifikacji schematem**
(`users`, a nie `ws.users`). Filtr `~^ws\.~` odrzucał je wszystkie, przez co
Doctrine nie widział własnej tabeli migracji i przy drugim uruchomieniu
próbował ją utworzyć ponownie:

```
SQLSTATE[42P07]: Duplicate table: relation "doctrine_migration_versions" already exists
```

Poprawny wzorzec jest odwrotny — **wyklucza** `palace`, zamiast wymagać `ws`:

```yaml
schema_filter: '~^(?!palace\.)~'
```

Działa, bo `palace` jest poza `search_path`, więc jego tabele zawsze wracają
kwalifikowane i wzorzec je łapie.

## Monitorowanie

| Co | Jak |
|---|---|
| żywotność mempalace | `GET /healthz` (bez uwierzytelnienia) |
| żywotność embeddingów | `docker compose exec mempalace curl http://embeddings:80/health` — obraz TEI jest distroless i nie ma czym sprawdzić sam siebie |
| **ciche degradacje wyszukiwania** | wyszukiwanie z błędem w treści narzędzia (`results: []` + `error`) — MemPalace nie zgłasza tego w kopercie JSON-RPC, więc alert musi zaglądać do środka |
| żywotność backendu | `GET /api/health` |
| zaległości kolejki | zadania Messenger starsze niż godzina |
| rozjazd pałaca z rejestrem | zadanie nocne: `memory_entries` bez szuflady w pałacu |
| wzrost bazy | rozmiar schematów `ws` i `palace` w raporcie tygodniowym |

Rozjazdu nie naprawiamy po cichu — raportujemy. Cicha naprawa ukryłaby błąd,
który go powoduje.

**Najgroźniejsza awaria tego systemu jest cicha.** Gdy serwer embeddingów nie
odpowiada, wyszukiwanie nadal zwraca poprawną odpowiedź — tylko pustą. Dla
użytkownika wygląda to jak „nic o tym nie mamy", a nie jak awaria. Dlatego
monitorowanie musi sprawdzać treść odpowiedzi, nie sam kod HTTP.

## Retencja

| Dane | Retencja |
|---|---|
| rewizje dokumentów | bezterminowo (historia się nie skraca) |
| `audit_log` | 24 miesiące, potem agregacja do statystyk |
| partie publikacji | bezterminowo (jednostka wycofania i ślad audytowy) |
| `mail_log` | 12 miesięcy (metadane wysyłki; treści i tak nie ma — D-038) |
| kolejka `failed` w `messenger_messages` | **7 dni**, i to nie jest porządkowanie — patrz niżej |

Surowych transkryptów sesji serwer **nie przechowuje** — mielą się lokalnie
i nigdy tu nie trafiają (D-012).

> **Kolejkę `failed` trzeba czyścić, bo leżą w niej tokeny.** Mail z zaproszeniem
> niesie działający token, a wiadomość, która nie przeszła wszystkich ponowień,
> zostaje w tej kolejce z treścią w środku (D-038 — sprawdzone zapytaniem, nie
> założone). Siedem dni bierze się z terminu ważności zaproszenia: po nim token
> i tak nic nie otwiera.
>
> ```bash
> docker compose exec backend php bin/console messenger:failed:show
> docker compose exec backend php bin/console messenger:failed:remove --all --force
> ```
>
> `messenger:failed:show` **wypisuje treść wiadomości**, czyli i token. Na cudzym
> ekranie ani w zgłoszeniu błędu jego wynik nie ma czego szukać.

> **Maile wysyła `worker`, nie `backend`.** Żądanie tylko wkłada wiadomość do
> kolejki; łączy się z serwerem poczty proces roboczy. Konfiguracja rozjechana
> między tymi dwiema usługami jest przez to **niewidoczna**: sprawdzone —
> `backend` z prawdziwym `MAILER_DSN` i `worker` z `null://null` dają w dzienniku
> maili stan **wysłany**, mimo że nic nigdzie nie poszło. `docker-compose.yml`
> podaje obu usługom te same zmienne z jednego `.env`, więc rozjazd bierze się
> tylko z ręcznego nadpisania — i wtedy jedynym objawem jest brak maili.
