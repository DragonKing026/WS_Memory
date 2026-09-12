---
noteId: "7a6c5dd0aeb211f1997d030a3cd38ca7"
tags: []

---

# 006 — Frontend: fundament i logowanie

**Utworzono:** 2026-09-12 16:05 · **Stan:** do zrobienia · **Zależności:** 002

## Powód

Frontend jest osobną aplikacją (D-008) i potrzebuje własnego szkieletu:
build, routing, klient API, obsługa tokenów, layout. Dopiero na tym staną
ekrany wyszukiwania i edytora.

Zależność jest od zadania 002, nie od całego backendu — wystarczy logowanie i
`GET /api/me`, żeby frontend ruszył równolegle z resztą backendu.

## Analiza

Stack przenoszony z nowszy projekt z frontendem Vue (`docs/07-frontend.md`), żeby zespół
nie uczył się drugiego zestawu konwencji. Wzorzec dev z 2.0: kontener Node 22 +
pnpm + Vite, nginx proxuje `/` na `frontend:5173` razem z websocketem HMR, a
`/api` na backend — **jeden origin, więc zero CORS-a**.

Dwie rzeczy, które w 2.0 okazały się istotne i warto je powtórzyć:

- Nakładka konfiguracji Vite dla Dockera jako osobny plik `.mts` (poza `/app`
  nie ma `package.json` z `"type": "module"`, więc config ładowany jako CJS
  wywraca ESM-only pakiety).
- `VITE_API_BASE_URL` jako **absolutny** adres — pochodne adresy liczone przez
  `new URL(path, base)` wymagają bazy absolutnej.

Odświeżanie tokena musi być w kliencie HTTP, nie w komponentach. Wygaśnięcie
tokena w trakcie pisania dokumentu nie może skończyć się utratą treści —
klient ponawia żądanie po odświeżeniu.

## Rozwiązanie

1. `frontend/` — Vue 3 + TS, Vite 7, pnpm 10, Node 22 (`.nvmrc`),
   Nuxt UI 4 + Tailwind 4, Pinia, vue-router 5 z routingiem plikowym, Zod 4.
2. `docker/frontend/Dockerfile` (dev: Node + pnpm + Vite; prod: build do
   `dist/` serwowany przez nginx) + `vite.docker.config.mts`.
3. `docker/nginx/default.conf` — `/` → frontend (z websocketem HMR w dev),
   `/api` i `/mcp` → backend.
4. `api/client.ts` — Axios z dołączaniem tokena, automatycznym odświeżaniem
   i ponowieniem żądania, oraz jednolitą obsługą błędów.
5. Store `auth` (Pinia): logowanie, wylogowanie, dane użytkownika, przestrzenie.
6. Layout aplikacji: nagłówek z wyszukiwaniem, lewa nawigacja z przestrzeniami,
   obszar treści. Strażnik trasy przekierowujący niezalogowanych.
7. Ekrany `/login` i akceptacji zaproszenia (ustawienie hasła z linku).
8. Vitest + pierwszy test store'a `auth` i klienta (odświeżanie tokena).

## Kryteria ukończenia

- `docker compose up -d` — frontend dostępny pod domeną dev, HMR działa
  (edycja pliku na hoście widoczna w przeglądarce bez przeładowania).
- W konsoli przeglądarki **zero** błędów CORS i zero ostrzeżeń.
- Logowanie działa, token trzymany bezpiecznie, odświeżenie przezroczyste dla
  użytkownika (test: wygaszony token dostępowy, żądanie się udaje).
- Niezalogowany na `/` trafia na `/login`.
- `pnpm build` produkuje `dist/` serwowane przez nginx bez procesu Node.
- `pnpm test` i `vue-tsc` przechodzą bez błędów.
