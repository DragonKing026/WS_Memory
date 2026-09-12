---
noteId: "be5f5841aeb111f1997d030a3cd38ca7"
tags: [ws-memory, dokumentacja, deployment, docker, backup, operacje]

---

# Deployment

Stan: **projekt**, `docker-compose.yml` jeszcze nie istnieje (2026-09-12).
Powstanie w zadaniu `TODO/000`.

## Wymagania serwera

| Zasób | Minimum | Zalecane | Dlaczego |
|---|---|---|---|
| RAM | 6 GB | 12 GB | model embeddingów (`bge-m3`) zajmuje ~2-3 GB; Postgres z HNSW lubi cache |
| CPU | 4 rdzenie | 8 rdzeni | liczenie wektorów przy mieleniu repozytoriów |
| Dysk | 40 GB | 100 GB SSD | baza rośnie z wolumenem wiedzy; wektory 1024-wymiarowe |
| Docker | Engine 24+ z Compose v2 | | |

GPU nie jest potrzebne. Usługę `embeddings` można przenieść na maszynę z GPU
bez zmiany czegokolwiek innego — to jedyny komponent liczący wektory.

**Czynnik decydujący o jej mocy:** od D-014 wysyłka z lokalnych pałaców jest
domyślna, więc serwer liczy wektory dla **całego** strumienia wiedzy wszystkich
maszyn, a nie dla wybranych fragmentów. Przy kilkunastu osobach mielących
projekty i rozmowy to stały ruch w tle, nie okazjonalne skoki. Jeśli kolejka
publikacji zaczyna rosnąć — najpierw dokładamy rdzenie usłudze `embeddings`,
dopiero potem myślimy o GPU.

## Uruchomienie

```bash
git clone <repo> ws-memory && cd ws-memory
cp .env.example .env          # hasła, domena, sekret JWT
docker compose up -d
docker compose exec backend bin/console doctrine:migrations:migrate
docker compose exec backend bin/console ws:user:invite twoj@email.pl --admin
```

## Zmienne środowiskowe

| Zmienna | Rola |
|---|---|
| `WS_DOMAIN` | domena publiczna (certyfikat, linki w mailach) |
| `POSTGRES_PASSWORD` | hasło bazy |
| `JWT_SECRET_KEY` / `JWT_PUBLIC_KEY` | podpisywanie tokenów ludzi |
| `MEMPALACE_MCP_HTTP_TOKEN` | token między backendem a mempalace; **nigdy nie wychodzi na zewnątrz** |
| `MEMPALACE_BACKEND` | `pgvector` |
| `MEMPALACE_PGVECTOR_DSN` | połączenie mempalace do Postgresa |
| `MEMPALACE_EMBEDDING_MODEL` | `openai-compat` |
| `MEMPALACE_EMBEDDING_API_URL` | `http://embeddings/v1/embeddings` |
| `MEMPALACE_EMBEDDING_API_MODEL` | `BAAI/bge-m3` |
| `MEMPALACE_ENTITY_LANGUAGES` | `pl,en` — wykrywanie encji; domyślnie `en`, patrz D-011 |
| `MAILER_DSN` | zaproszenia i powiadomienia |

Trzy ostatnie zmienne MemPalace są **nierozdzielne** — patrz D-003. Zmiana
modelu unieważnia wszystkie wektory w bazie.

## TLS

`nginx` z Let's Encrypt (`certbot` w trybie webroot). Jedyne wystawione porty
to 80 (przekierowanie i odnowienia) i 443. Żadna inna usługa nie ma mapowania
portu na host (D-006).

## Backup

**Jedno polecenie obejmuje cały system** — to główna korzyść z trzymania pałaca
w Postgresie (D-002):

```bash
docker compose exec -T postgres pg_dump -U ws --format=custom ws_memory \
  > backup-$(date +%F-%H%M).dump
```

Obejmuje: dokumenty, wszystkie rewizje, konta, uprawnienia, audyt **i pałac**
(wektory razem z metadanymi). Odtworzenie:

```bash
docker compose exec -T postgres pg_restore -U ws -d ws_memory --clean < backup.dump
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

## Monitorowanie

| Co | Jak |
|---|---|
| żywotność mempalace | `GET /healthz` (bez uwierzytelnienia) |
| żywotność backendu | `GET /api/health` |
| zaległości kolejki | zadania Messenger starsze niż godzina |
| rozjazd pałaca z rejestrem | zadanie nocne: `memory_entries` bez szuflady w pałacu |
| wzrost bazy | rozmiar schematów `ws` i `palace` w raporcie tygodniowym |

Rozjazdu nie naprawiamy po cichu — raportujemy. Cicha naprawa ukryłaby błąd,
który go powoduje.

## Retencja

| Dane | Retencja |
|---|---|
| rewizje dokumentów | bezterminowo (historia się nie skraca) |
| `audit_log` | 24 miesiące, potem agregacja do statystyk |
| partie publikacji | bezterminowo (jednostka wycofania i ślad audytowy) |

Surowych transkryptów sesji serwer **nie przechowuje** — mielą się lokalnie
i nigdy tu nie trafiają (D-012).
