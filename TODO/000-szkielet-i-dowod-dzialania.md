---
noteId: "29bab670aeb211f1997d030a3cd38ca7"
tags: []

---

# 000 — Szkielet Dockera i dowód, że polska semantyka działa

**Utworzono:** 2026-09-12 16:05 · **Stan:** do zrobienia · **Zależności:** brak

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
