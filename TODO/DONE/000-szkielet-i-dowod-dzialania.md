---
noteId: "29bab670aeb211f1997d030a3cd38ca7"
tags: [ws-memory, todo, docker, mempalace, embeddingi, postgres, faza-0]

---

# TODO-000 — Szkielet Dockera i dowód, że polska semantyka działa

**Utworzono:** 2026-09-12 16:03 · **Stan:** ✅ **UKOŃCZONE 2026-09-12 17:46** · **Zależności:** brak

## Powód

Cały projekt stoi na założeniu, które nie zostało jeszcze sprawdzone w
działaniu: że MemPalace z backendem pgvector i zewnętrznym serwerem
embeddingów poprawnie obsłuży **polską** wiedzę. Jeśli to założenie jest
błędne, wszystko zbudowane nad nim trzeba będzie przestawiać.

Dlatego pierwsze zadanie jest celowo małe i celowo nie zawiera ani linii
Symfony: **dowód działania fundamentu, zanim cokolwiek na nim postawimy.**

## Analiza

Ustalenia z rozpoznania MemPalace 3.7.0 (na kodzie wersji zainstalowanej):

- Backend pgvector konfiguruje się przez `MEMPALACE_BACKEND=pgvector` +
  `MEMPALACE_PGVECTOR_DSN`; tworzy własne tabele, nazwa zawiera namespace.
- Zewnętrzny embedder: `MEMPALACE_EMBEDDING_MODEL=openai-compat` +
  `MEMPALACE_EMBEDDING_API_URL` + `MEMPALACE_EMBEDDING_API_MODEL`.
  Serwer musi odpowiadać na `/v1/embeddings` w formacie OpenAI, a odpowiedź
  jest walidowana (`encoding_format=float`, zgodność indeksów, długość).
- `mempalace serve` wystawia `POST /mcp` (JSON-RPC) i `GET /healthz`
  (bez uwierzytelnienia). Token w `MEMPALACE_MCP_HTTP_TOKEN`.
- Wymiar wektora to „cokolwiek zwróci serwer" — `bge-m3` daje 1024.

Na co uważać:

- **Kolejność startu.** MemPalace przy pierwszym zapisie sonduje wymiar
  embeddingu. Jeśli `embeddings` jeszcze nie wstał, sondowanie się nie uda.
  Potrzebne `depends_on` z `condition: service_healthy`.
- **Model nie może się zmienić po pierwszym zapisie** (D-003) — przypinamy
  wersję obrazu i nazwę modelu, nie `latest`.
- **Nie wystawiamy portów** poza nginxem (D-006) — w tym zadaniu nginx jeszcze
  nie jest potrzebny, więc testujemy z wnętrza sieci Compose.

## Rozwiązanie

1. `docker-compose.yml` z trzema usługami: `postgres` (obraz
   `pgvector/pgvector:pg18`), `embeddings` (HF TEI z `BAAI/bge-m3`),
   `mempalace` (własny obraz na bazie upstream `Dockerfile`).
2. `docker/mempalace/Dockerfile` — przypięta wersja MemPalace, wejście
   `mempalace serve --host 0.0.0.0 --port 8765`.
3. `.env.example` ze wszystkimi zmiennymi z `docs/05-deployment.md`.
4. Healthchecki: `postgres` przez `pg_isready`, `embeddings` przez
   `/health`, `mempalace` przez `/healthz`. `depends_on` w tej kolejności.
5. Inicjalizacja bazy: rozszerzenie `vector`, dwa schematy (`palace`, `ws`),
   rola aplikacji z prawami tylko do swojego schematu.
6. **Test integracyjny polskiej semantyki** (`test/semantyka.sh`):
   - zapisz szufladę: „Umowa najmu lokalu wymaga aneksu przy zmianie stawki
     czynszu";
   - szukaj frazy **niezawierającej tych samych słów**: „zmiana opłaty za
     wynajem — jakie dokumenty";
   - test przechodzi, gdy zapisana szuflada jest w wynikach.
7. `README.docker.md` — jak to uruchomić i jak odczytać wynik testu.

## Kryteria ukończenia

- `docker compose up -d` podnosi trzy usługi, wszystkie `healthy`.
- `docker compose exec mempalace curl -s localhost:8765/healthz` → `200`.
- `test/semantyka.sh` przechodzi — polskie zapytanie innymi słowami znajduje
  polską treść.
- `docker compose down && docker compose up -d` zachowuje dane (wolumen).
- W bazie istnieją tabele pałaca w schemacie `palace` (potwierdzone `\dt`).
- Żadna usługa poza wskazanymi nie ma mapowania portu na host.


---

## Co zostało zrobione

**Ukończono:** 2026-09-12 17:46

### Wynik: fundament działa, założenie potwierdzone

Test `./test/semantyka.sh` przechodzi. Zapisane po polsku zdanie *„Umowa najmu
lokalu wymaga aneksu przy zmianie stawki czynszu"* zostało znalezione
zapytaniem *„zmiana opłaty za wynajem — jakie dokumenty"* z wynikiem:

```
podobieństwo 0.743, dopasowanie słów (BM25) 0.0
```

**`bm25_score = 0.0` to sedno dowodu**: wyszukiwarka nie miała się o co
zaczepić ani jednym wspólnym słowem, więc trafienie jest wyłącznie
semantyczne. Wymiar wektora w bazie to **1024** — czyli pracuje `bge-m3`,
a nie domyślny 384-wymiarowy `minilm`. Założenie, na którym stoi cały projekt,
jest potwierdzone w działaniu.

### Kryteria ukończenia — weryfikacja

| Kryterium | Wynik |
|---|---|
| trzy usługi `healthy` | ✅ postgres, embeddings, mempalace |
| `/healthz` zwraca 200 | ✅ |
| `test/semantyka.sh` przechodzi | ✅ podobieństwo 0.743, BM25 0.0 |
| `down && up` zachowuje dane | ✅ szuflada przetrwała, test przeszedł ponownie |
| tabele pałaca w schemacie `palace` | ✅ `mempalace_9a994364b7c26031_mempalace_drawers`, właściciel `mempalace` |
| żadna usługa bez portu na hoście | ✅ zero opublikowanych portów |

### Co powstało

- `docker-compose.yml` — trzy usługi, wolumeny, healthchecki
- `docker/mempalace/Dockerfile` + `entrypoint.sh` — przypięty MemPalace 3.7.0
  z `psycopg`, czekający na serwer embeddingów
- `docker/postgres/init/01-schematy.sh` — `vector`, schematy `palace` i `ws`,
  dwie role z rozdzielonymi uprawnieniami
- `docker/wygeneruj-sekrety.sh` — losowe hasła i token do `.env`
- `.env.example`, `README.docker.md`
- `test/semantyka.sh` + `test/sprawdz_odpowiedz.py`

### Cztery rzeczy, które wyszły dopiero w działaniu

**1. Postgres 18 zmienił konwencję montowania.** Wolumen idzie na
`/var/lib/postgresql`, **nie** na `/var/lib/postgresql/data` — od 18 dane leżą
w podkatalogu z numerem wersji, żeby `pg_upgrade --link` nie przekraczał
granicy montowania. Stara ścieżka kończy się odmową startu z komunikatem o
„unused mount/volume". Kosztowało jeden nieudany start.

**2. Obraz TEI jest distroless — nie ma nawet powłoki.** Sprawdzone: brak
`curl`, `wget`, `nc`, `sh`. Healthcheck Dockera jest tam **niewykonalny**, bo
nie ma czym wykonać polecenia. Dlatego na gotowość embeddingów czeka entrypoint
usługi `mempalace`, a operator sprawdza je z sąsiedniego kontenera. Pierwotny
plan zadania zakładał healthcheck przez `/health` — nie dało się.

**3. Wyszukiwanie degraduje się cicho.** Gdy serwer embeddingów nie odpowiada,
MemPalace zwraca **poprawną** odpowiedź JSON-RPC, w której błąd siedzi wewnątrz
treści narzędzia razem z pustą listą wyników. Pierwsza wersja testu widziała
pustą listę i orzekła „model nie rozumie polskiego" — czyli postawiła fałszywą
diagnozę przy zupełnie innej awarii. `test/sprawdz_odpowiedz.py` rozróżnia teraz
te przypadki. To trafiło też do monitorowania w `docs/05-deployment.md`, bo
alert patrzący tylko na kod HTTP tej awarii nie zobaczy.

**4. Model wymaga realnej pamięci.** `bge-m3` to ~2–3 GB RAM po załadowaniu i
~2 GB pobrania. Na maszynie deweloperskiej z 1 GB wolnego kontener się
przeładowywał, a wyszukiwanie w tym czasie zwracało puste wyniki. Po każdym
restarcie usługa jest niedostępna przez 1–3 minuty (ładowanie z cache'u).

### Czego nie zrobiono i dlaczego

- **Healthcheck usługi `embeddings`** — niewykonalny, patrz punkt 2. Zastąpiony
  oczekiwaniem w entrypoincie i udokumentowanym poleceniem diagnostycznym.
- **Automatyczne ponowne czekanie po restarcie embeddingów** — entrypoint czeka
  tylko przy starcie. Jeśli embeddingi padną później, wyszukiwanie zwraca błąd
  do czasu ich powrotu; system sam się podnosi. Uznano za wystarczające na tym
  etapie; gdyby okazało się uciążliwe, to zadanie na później.
