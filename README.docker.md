---
noteId: "b30a3a81aebd11f1997d030a3cd38ca7"
tags: []

---

# Uruchomienie WS_Memory w Dockerze

Stan: **fundament (TODO-000)**. Stoją trzy usługi: baza, serwer embeddingów
i pamięć. Backend, frontend, worker i nginx dojdą w kolejnych zadaniach.

## Pierwsze uruchomienie

```bash
cp .env.example .env
./docker/wygeneruj-sekrety.sh     # losowe hasła i token
docker compose up -d
```

Pierwszy start trwa kilka minut: serwer embeddingów pobiera model `BAAI/bge-m3`
(~2 GB). Model ląduje w wolumenie, więc kolejne starty są natychmiastowe.

Postęp:

```bash
docker compose ps
docker compose logs -f embeddings
```

## Sprawdzenie, czy fundament działa

```bash
./test/semantyka.sh
```

Ten test istnieje po to, żeby wyłapać **jedyną cichą wadę**, jaka może tu
wystąpić. Zapisuje po polsku zdanie o aneksie do umowy najmu, a potem szuka
frazy *„zmiana opłaty za wynajem — jakie dokumenty"* — bez wspólnych słów z
treścią. Domyślny model MemPalace (`minilm`) jest trenowany wyłącznie na
angielskim: zapisałby i wyszukał bez błędu, tylko nie znalazłby tego, co
trzeba. Test przechodzi jedynie wtedy, gdy wektory naprawdę rozumieją polski.

Ręcznie:

```bash
docker compose exec mempalace curl -s localhost:8765/healthz
```

## Co gdzie jest

| Plik | Rola |
|---|---|
| `docker-compose.yml` | trzy usługi, **żadna bez portu na hoście** (D-006) |
| `docker/mempalace/Dockerfile` | obraz pamięci: przypięta wersja MemPalace + `psycopg` |
| `docker/mempalace/entrypoint.sh` | czeka na serwer embeddingów, zanim wystartuje |
| `docker/postgres/init/01-schematy.sh` | schematy `palace` i `ws`, dwie role, rozszerzenie `vector` |
| `docker/wygeneruj-sekrety.sh` | wypełnia `.env` losowymi sekretami |
| `test/semantyka.sh` | dowód, że polska semantyka działa |

## Szczegóły, które mają znaczenie

**Postgres 18 montuje się inaczej niż wcześniejsze wersje.** Wolumen idzie na
`/var/lib/postgresql`, **nie** na `/var/lib/postgresql/data` — od 18 dane leżą
w podkatalogu z numerem wersji, żeby `pg_upgrade --link` nie przekraczał
granicy montowania. Zamontowanie starej ścieżki kończy się odmową startu z
komunikatem o „unused mount/volume".

**Serwer embeddingów nie ma healthchecku, bo nie może go mieć.** Obraz TEI
jest distroless — nie zawiera `curl`, `wget`, `nc` ani nawet powłoki
(sprawdzone). Docker nie ma więc czym wykonać polecenia sprawdzającego wewnątrz
kontenera. Na gotowość czeka entrypoint usługi `mempalace`, a stan sprawdza się
z zewnątrz:

```bash
docker compose exec mempalace curl -s -o /dev/null -w '%{http_code}\n' \
  http://embeddings:80/health
```

**Gdy serwer embeddingów nie odpowiada, wyszukiwanie nie zgłasza awarii
głośno.** MemPalace zwraca poprawną odpowiedź JSON-RPC, w której błąd siedzi
*wewnątrz* treści narzędzia razem z pustą listą wyników. Bez zajrzenia tam
niedostępny embedder wygląda identycznie jak model, który nie rozumie
polskiego — dwie zupełnie różne awarie. `test/sprawdz_odpowiedz.py` rozróżnia
je i nazywa po imieniu.

**Model potrzebuje pamięci.** `bge-m3` zajmuje ~2–3 GB RAM po załadowaniu, a
pierwsze pobranie to ~2 GB na dysk. Na maszynie z zajętą pamięcią kontener
będzie się przeładowywał, a wyszukiwanie w tym czasie zwraca puste wyniki.
Ładowanie modelu z pobranego już cache'u trwa 1–3 minuty — tyle po każdym
restarcie usługa jest niedostępna.

**Dwie role bazy, każda we własnym schemacie.** `mempalace` pisze do `palace`,
`ws_app` do `ws` i tylko czyta `palace`. Pomyłka w kodzie nie sięgnie cudzych
tabel, a backupem całego systemu jest jeden `pg_dump`.

**Modelu embeddingów nie zmienia się po pierwszym zapisie** — zmiana
unieważnia wszystkie wektory w bazie i wymaga przeliczenia jej od zera
(`docs/06-decyzje.md`, D-003).

## Zatrzymanie i sprzątanie

```bash
docker compose down          # zatrzymuje, zachowuje dane
docker compose down -v       # USUWA wolumeny: bazę i pobrany model
```
