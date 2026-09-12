---
noteId: "7a6c5dd0aeb211f1997d030a3cd38ca7"
tags: [ws-memory, todo, frontend, vue, vite, docker]

---

# TODO-006 — Frontend: fundament i logowanie

**Utworzono:** 2026-09-12 16:03 · **Stan:** ✅ **UKOŃCZONE 2026-09-12 23:22** · **Zależności:** 002

## Powód

Frontend jest osobną aplikacją (D-008) i potrzebuje własnego szkieletu:
build, routing, klient API, obsługa tokenów, layout. Dopiero na tym staną
ekrany wyszukiwania i edytora.

Zależność jest od zadania 002, nie od całego backendu — wystarczy logowanie i
`GET /api/me`, żeby frontend ruszył równolegle z resztą backendu.

## Analiza

Stack przenoszony z nowszego projektu z frontendem Vue (`docs/07-frontend.md`), żeby zespół
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

## Co zostało zrobione

**Ukończono:** 2026-09-12 23:22

### Kryteria ukończenia — weryfikacja

| Kryterium | Wynik |
|---|---|
| `docker compose up -d` — frontend dostępny, HMR działa | ✅ sprawdzone **w przeglądarce**: edycja nagłówka w pliku na hoście zmieniła treść na stronie bez przeładowania i bez nawigacji |
| zero błędów CORS i zero ostrzeżeń w konsoli | ✅ jedyny błąd to brak `favicon.ico` — dołożona ikona, konsola czysta |
| logowanie działa, token trzymany bezpiecznie | ✅ pełny obieg w przeglądarce; token w `localStorage` z ryzykiem nazwanym w D-028 i `SECURITY.md` |
| odświeżenie tokena przezroczyste dla użytkownika | ❌ **niewykonalne** — endpointu odświeżania nie ma (D-017). Zamiast tego: 401 kończy sesję, klient zapamiętuje **gdzie** był użytkownik i wraca tam po zalogowaniu. Test pilnuje wprost, że po 401 **nie ma ponowienia** |
| niezalogowany na `/` trafia na `/login` | ✅ z zachowaniem celu w `?powrot=` |
| `pnpm build` produkuje `dist/` serwowane przez nginx bez procesu Node | ✅ obraz produkcyjny **nie zawiera `node`** — sprawdzone poleceniem, nie założone |
| `pnpm test` i `vue-tsc` przechodzą | ✅ 24 testy, typy czyste; oba w CI |

### Co powstało

`frontend/` — Vue 3.5, Vite 8, Nuxt UI 4, Tailwind 4, Pinia 4, vue-router 5,
Zod 4, Vitest 5, pnpm 10.

- `api/client.ts` + `api/errors.ts` — jedyne miejsce znające `/api`; nazwane
  problemy zamiast kodów HTTP rozsypanych po komponentach.
- `features/auth/`, `features/tokens/` — schematy Zod i serwisy.
- `stores/auth.ts` — sesja, przestrzenie, uprawnienia z serwera.
- `router/index.ts` — trasy wypisane, jeden strażnik.
- `layouts/AppLayout.vue`, pięć stron, w tym działające ekrany logowania,
  akceptacji zaproszenia i tokenów agentów.
- `docker/frontend/Dockerfile` (dev + prod), `docker/nginx/frontend-prod.conf`,
  usługa `frontend` w compose, `/` w nginxie z websocketem HMR.
- Zadanie `frontend` w szybkim przebiegu CI: typy, testy, build.

### Decyzje podjęte po drodze

**D-027** — trasy wypisane jawnie, bez `unplugin-vue-router`.
**D-028** — token w `localStorage`, z ryzykiem nazwanym w `SECURITY.md`.

### Kryterium, którego nie dało się spełnić

Zadanie wymagało przezroczystego odświeżania tokena i testu „wygaszony token
dostępowy, żądanie się udaje". **Endpointu odświeżania nie ma i nie będzie
w tym kształcie** (D-017): bundle nie obsługuje Symfony 8, a pisanie rotacji
tokenów odświeżających własnoręcznie to kod bezpieczeństwa z nieoczywistymi
pułapkami.

Zrealizowana jest natomiast intencja, która za tym stała — „wygaśnięcie tokena
w trakcie pisania nie może skończyć się utratą treści": 401 kończy sesję
**jawnie**, klient zapamiętuje adres, na którym był użytkownik, i wraca tam po
zalogowaniu. Test pilnuje, że ponowienia **nie ma**, bo bez odświeżania byłoby
to drugie identyczne 401 — a taki „na wszelki wypadek" ktoś kiedyś dopisze.

Pełna ochrona treści edytora (szkic lokalny) należy do `TODO-008`, gdzie edytor
w ogóle powstaje.

### Cztery rzeczy warte zapamiętania

1. **`unplugin-vue-router` wymaga `vue-router ^4.6`.** Dokumentacja stacku
   obiecywała routing plikowy przy vue-router 5 — to się wyklucza (D-027).
2. **Polski cudzysłów zamknięty znakiem `"` zamyka atrybut HTML.** Kompilacja
   `TokensPage.vue` wywracała się z komunikatem o `trim` w kompilatorze szablonu,
   który nie wskazywał przyczyny. Teraz konsekwentnie `„ … ”`.
3. **`vue-tsc` przeszedł, choć był błąd typu.** Gdy jeden plik nie daje się
   sparsować, sprawdzenie po cichu zawęża zakres i mówi „czysto". Wyszło dopiero
   przy `pnpm build`. Dlatego CI ma **oba** kroki, nie jeden.
4. **`instanceof AxiosError` zawodzi**, gdy w grafie są dwie kopie axiosa —
   każdy błąd stawał się wtedy „nieoczekiwanym błędem przeglądarki". Zamienione
   na `axios.isAxiosError()`; złapane przez test store'a.

Do tego drobiazg z oczu użytkownika: nagłówek zbijał się do lewej, bo
wyszukiwarka ma `flex-1` z `max-w-2xl` i na szerokim ekranie zostawało
niezagospodarowane miejsce. `ml-auto` na grupie przycisków.

### Zrzuty

`TODO/zrzuty/006-ekran-glowny-po-zalogowaniu.png` — aplikacja po zalogowaniu.
`TODO/zrzuty/006-naglowek-po-poprawce.png` — nagłówek po dosunięciu przycisków
do prawej krawędzi.

Drugi z nich wylądował najpierw w **korzeniu repozytorium** i tak został
zacommitowany oraz wypchnięty. Sprzątnięte `git mv`, a konwencja („zrzuty do
`TODO/zrzuty/`, nigdy do korzenia") dopisana do `AGENTS.md` i `TODO/README.md`,
żeby się nie powtórzyło. Obraz obejrzany przed przeniesieniem: token był ucięty
przez przewijanie poziome, więc do publicznego repozytorium nic nie wyciekło.

### Czego nie zrobiono

- **Brak ekranów wyszukiwania, dokumentu i edytora** — to `TODO-007` i `TODO-008`.
  Strony `/` i `/s/:space` mówią wprost, czego jeszcze nie ma, zamiast udawać
  gotowe: szkielet, który udaje skończony, marnuje czas tego, kto go otworzy.
- **Brak ekranów administracyjnych** (`/admin/*`) — backend ma endpointy,
  ekranów jeszcze nie.
- **Brak testów E2E (Playwright).** Interfejs sprawdziłem przeglądarką ręcznie
  i to wystarcza przy trzech ekranach; przy dwunastu będzie trzeba je napisać.
  Osobne zadanie, razem z `TODO-011`.
- **Brak trybu ciemnego.** Nuxt UI go wspiera, ale wybór palety to decyzja
  projektowa, nie techniczna — i lepiej podjąć ją, gdy będzie widać wszystkie
  ekrany.
