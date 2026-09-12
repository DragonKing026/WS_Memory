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

Konto administratora powstanie razem z zarządzaniem użytkownikami (TODO-002).

## Zmienne środowiskowe

| Zmienna | Rola |
|---|---|
| `WS_DOMAIN` | domena publiczna (certyfikat, linki w mailach) |
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
nie dotyka naszego kodu:

1. Backup bazy.
2. Podniesienie wersji w `docker/mempalace/Dockerfile`, przebudowa obrazu.
3. `docker compose up -d mempalace`, sprawdzenie `/healthz`.
4. Test integracyjny polskiej semantyki (patrz `TODO/000`) — jeśli przechodzi,
   wektory są nietknięte.

Czego przy aktualizacji **nie wolno**: zmienić modelu embeddingów „przy okazji".
To osobna operacja z przeliczeniem całej bazy.

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

Surowych transkryptów sesji serwer **nie przechowuje** — mielą się lokalnie
i nigdy tu nie trafiają (D-012).
