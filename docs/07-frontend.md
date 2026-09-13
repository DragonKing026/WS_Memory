---
noteId: "986e0eb1aeb111f1997d030a3cd38ca7"
tags: [ws-memory, dokumentacja, frontend, vue, vite, ui]

---

# Frontend

Stan: **wyszukiwanie i przeglądanie działają** (2026-09-13, `TODO-007`).
Logowanie, zaproszenia, wyszukiwanie w dwóch trybach, drzewo dokumentów, ekran
dokumentu, surowa pamięć, tokeny agentów. Brakuje edytora i historii rewizji
(`TODO-008`).

Osobna aplikacja Vue 3, niezależna od backendu (D-008). Stack i struktura
przeniesione z nowszego projektu z frontendem Vue, żeby zespół nie uczył się drugiego
zestawu konwencji.

## Stack

Vue 3.5 (`<script setup>` + TypeScript) · Vite 8 · Nuxt UI 4 · Tailwind 4 ·
Pinia 4 · vue-router 5 · Zod 4 · Axios · Vitest 5 · TypeScript 5.9 ·
pnpm 10 · Node 22 (`.nvmrc`).

> **Dwie rzeczy różnią się od pierwotnego zapisu i oba odstępstwa są świadome.**
>
> **Nie ma routingu plikowego** (D-027): `unplugin-vue-router` wymaga
> `vue-router ^4.6`, a tu jest 5. Trasy są wypisane w `src/router/index.ts`.
>
> **Vite 8 i Vitest 5, nie 7 i 4** — w chwili pisania to wersje bieżące,
> a zaczynanie nowej aplikacji od wersji już zastąpionej byłoby długiem od
> pierwszego dnia. Konwencje pracy (struktura katalogów, `<script setup>`,
> Zod na granicy) są te same, a to one były powodem trzymania się stacku
> z drugiego projektu.
>
> TypeScript celowo **5.9, nie 7**: TS 7 to przepisany rdzeń, a `vue-tsc` 3.x
> stoi na API poprzedniej generacji. To ryzyko nie ma tu żadnej nagrody.

Dodatkowo, specyficznie dla WS_Memory: **CodeMirror 6** jako edytor Markdown
(D-009) i `markdown-it` do podglądu.

## Struktura `frontend/src/`

| Katalog | Przeznaczenie |
|---|---|
| `pages/` | komponenty stron; adresy nadaje `router/index.ts` (D-027) |
| `features/<domena>/` | logika domenowa: serwisy, typy, store'y danej domeny |
| `components/<domena>/` | komponenty prezentacyjne |
| `composables/` | reużywalne composables (`useXxx.ts`) |
| `stores/` | store'y Pinia współdzielone między domenami |
| `api/client.ts` | klient HTTP (Axios) — jedyne miejsce, które zna `/api` |
| `utils/` | czyste funkcje pomocnicze |
| `layouts/` | layouty |

Domeny WS_Memory: `auth`, `spaces`, `documents`, `search`, `memory`, `admin`.

## Ekrany

| Ścieżka | Co robi |
|---|---|
| `/login` | ✅ logowanie; rejestracja wyłącznie z linku zaproszenia |
| `/zaproszenie/:token` | ✅ ustawienie hasła z linku zaproszenia |
| `/` | ✅ wyszukiwanie: dwa tryby, filtry, słabe wyniki osobno |
| `/s/:space` | ✅ drzewo dokumentów przestrzeni, stronicowane |
| `/s/:space/:slug` | ✅ dokument: treść, autor, weryfikacja (historia w `TODO-008`) |
| `/s/:space/:slug/edit` | edytor: CodeMirror + podgląd obok |
| `/s/:space/:slug/history` | lista rewizji, porównanie dwóch dowolnych, cofnięcie |
| `/s/:space/proposals` | kolejka propozycji (tylko gdy przestrzeń ją wymaga) |
| `/memory` | ✅ surowa pamięć z filtrami (graf wiedzy dochodzi później) |
| `/settings/tokens` | ✅ tokeny agentów: wystawianie, zakres, unieważnianie |
| `/admin/*` | użytkownicy, zaproszenia, przestrzenie, role, audyt, szablony maili, dziennik maili |

## Zasady

**Jeden klient HTTP.** `api/client.ts` to jedyne miejsce znające ścieżkę `/api`
i dołączające token. Komponent nigdy nie woła Axiosa bezpośrednio.

**Odświeżania tokena nie ma** (D-017) — i to zmienia sposób obsługi wygaśnięcia.
Kod 401 kończy sesję: klient czyści token, zapamiętuje, **gdzie** był użytkownik,
i przekierowuje na logowanie, po którym wraca dokładnie tam. Test pilnuje wprost,
że po 401 żądanie **nie jest ponawiane** — bez odświeżania byłoby to drugie
identyczne 401, a „na wszelki wypadek" ktoś takie ponowienie dopisze.

Token mieszka w `localStorage`, z ryzykiem nazwanym wprost w D-028 i w
`SECURITY.md`. Każdy dostęp do magazynu jest owinięty w `try/catch`, bo
w prywatnym oknie i po wyczyszczeniu danych witryny odczyt rzuca albo zwraca nic
— a aplikacja ma wtedy działać, nie wywracać się.

**Zod na granicy.** Każda odpowiedź API przechodzi przez schemat Zod przed
wejściem do store'a. Backend zmienia kontrakt → test frontendu pada od razu,
zamiast produkować `undefined` trzy ekrany dalej.

**Treść dokumentu to Markdown i tylko Markdown.** Nie ma drugiej
reprezentacji (D-009). Podgląd renderuje ten sam tekst, który poleci do API.

**Autor widoczny zawsze.** Każda treść — dokument, rewizja, szuflada — pokazuje,
czy napisał ją człowiek, czy AI, i czy człowiek to zweryfikował. To nie ozdoba:
od tego zależy, jak czytelnik ma traktować treść.

**Wyszukiwanie pokazuje źródło i trafność.** Wynik bez informacji, z której
przestrzeni pochodzi i jak mocno pasuje, jest bezużyteczny przy bazie rzędu
setek tysięcy szuflad.

## Dev i build

Dev: kontener Node 22 + pnpm + Vite, nginx proxuje `/` na `frontend:5173`
razem z websocketem HMR, `/api` i `/mcp` na backend. Edycja plików na hoście
jest natychmiast widoczna w przeglądarce, a przeglądarka nie dotyka CORS-a, bo
wszystko jest na jednym origin.

Trzy rzeczy, bez których HMR nie działa przez Dockera i nginxa — każda
udokumentowana w `vite.docker.config.mts`:

- `host: true` — bez tego port jest nieosiągalny z zewnątrz kontenera;
- `allowedHosts: true` — Vite odrzuca żądanie pod nazwą projektu jako możliwą
  próbę przewiązania DNS;
- `watch.usePolling` — zdarzenia inotify nie zawsze przechodzą przez montowanie
  z hosta, a `hmr.clientPort` musi wskazywać port **nginxa**, bo przeglądarka
  rozmawia z nginxem, nie z Vite.

Nakładka jest osobnym plikiem `.mts`, i to też nie jest przypadek: poza `/app`
nie ma `package.json` z `"type": "module"`, więc konfiguracja `.ts` wczytuje się
jako CommonJS i wywraca pakiety ESM-only.

Produkcja: `pnpm build` → statyczne `dist/` serwowane przez nginx. Obraz
produkcyjny **nie zawiera node** — sprawdzone, nie założone. Oba tryby nasłuchują
na **tym samym porcie 5173**, żeby główny nginx miał jeden adres nadrzędny
niezależnie od trybu.

Plik `pnpm-lock.yaml` jest commitowany, a build używa `--frozen-lockfile` bez
awarii do zwykłego `install`: build, który po cichu rozwiązuje zależności inaczej
niż plik blokady, przestaje być powtarzalny — czyli traci jedyny powód, po który
ten plik się trzyma.

## Edytor: własne `node_modules` na hoście

Kontener trzyma zależności w **anonimowym wolumenie** przysłaniającym
`/app/node_modules`, więc katalog `frontend/node_modules` na hoście jest pustym
punktem montowania. Aplikacja działa, testy przechodzą — ale edytor nie widzi
żadnych typów i zgłasza „Nie można znaleźć pliku definicji typu dla elementu
»vite/client«" oraz brak podpowiedzi w całym projekcie.

Lekarstwo to jednorazowa instalacja **na hoście**:

```bash
cd frontend
npx pnpm@10.20.0 install --frozen-lockfile   # wersja z pola packageManager
```

**To nie koliduje z kontenerem** i nie jest obejściem — na tym właśnie polega
anonimowy wolumen. Kontener nigdy nie widzi katalogu z hosta, więc obie strony
mają własne drzewo zależności, każde z binariami dla swojej biblioteki C
(host: glibc, kontener: musl). Gdyby współdzieliły jeden katalog, `esbuild`
z jednej strony wywracałby się po drugiej.

Wersję Node bierz z `.nvmrc`. Prawdą o zależnościach pozostaje kontener:
`typecheck`, `test` i `build` w CI biegną tam i to one rozstrzygają.

### Pułapka: nie kasuj `frontend/node_modules` na hoście

Wolumen przysłania `/app/node_modules` **od momentu utworzenia kontenera**.
Skasowanie katalogu po stronie hosta usuwa punkt montowania i przysłonięcie
przestaje działać — kontener zaczyna wtedy pisać prosto do katalogu hosta.
Objawy są mylące, bo wszystko nadal działa: `pnpm add` w kontenerze zostawia
pliki należące do roota w repozytorium, host dostaje binaria zbudowane pod musl,
a `pnpm` zgłasza `ERR_PNPM_UNEXPECTED_STORE`.

Jeśli już do tego doszło, kolejność naprawy ma znaczenie:

```bash
# 1. usuń katalog roota (z kontenera, bo host nie ma do niego prawa)
docker run --rm -v "$PWD/frontend:/w" alpine rm -rf /w/node_modules
# 2. zainstaluj na hoście, jako swój użytkownik
cd frontend && CI=true npx pnpm@10.20.0 install --frozen-lockfile
# 3. odtwórz kontener — dopiero to przywraca przysłonięcie
docker compose up -d --force-recreate frontend
# 4. uzupełnij zależności w kontenerze
docker compose exec -e CI=true frontend pnpm install --frozen-lockfile
```

Sprawdzenie, że rozdział działa: `mount | grep /app` w kontenerze musi pokazać
**dwie** linie — `/app` i osobno `/app/node_modules`. Jedna linia znaczy, że
obie strony dzielą jeden katalog.

## Testy end-to-end

`frontend/e2e/` — Playwright, uruchamiany przeciwko **działającemu stosowi**, nie
przeciwko atrapom. To jedyne miejsce, które sprawdza, czy logowanie, zapis
dokumentu, porównanie rewizji i cofnięcie działają **razem**, przez nginxa, Vite,
API i bazę — czyli w jedynym układzie, jaki człowiek kiedykolwiek wykonuje.

```bash
docker compose up -d               # stos musi stać
cd frontend && pnpm test:e2e
```

Konfiguracja nie ma `webServer`: stos podnosi `docker compose`, a Playwright
startujący własny serwer sprawdzałby inny układ niż ten, który jedzie na
produkcję. W CI testy chodzą w przebiegu nocnym, na maszynie przebiegu, a nie
w kontenerze — Playwright potrzebuje przeglądarki z bibliotekami systemowymi,
których obraz Alpine nie ma.

Vitest ma celowo wąskie `include` (`tests/**/*.test.ts`): domyślny wzorzec
złapałby pliki `e2e/*.spec.ts` jako testy jednostkowe i wywrócił się na braku
przeglądarki.

**Pierwszy przebieg tych testów znalazł usterkę**, której nie widziało nic
innego: adres dokumentu ze ścieżką w nazwie (`procedury/pierwsza`) wychodził
jako `procedury%2Fpierwsza`, bo vue-router koduje ukośnik wewnątrz parametru.
Strona się otwierała, więc nic nie wyglądało na zepsute — ale adres był inny niż
ten, do którego odsyłają drzewo i wyniki wyszukiwania, i to on trafiał do
schowka. Stąd `features/documents/paths.ts`: adresy dokumentów budujemy jako
napisy, nie przez `params`.

## Nadpisania zależności

W `package.json` jest jedno `pnpm.overrides`: **`esbuild: ^0.28.2`**.

Powód: esbuild poniżej 0.28.1 pozwala odczytać dowolny plik, gdy serwer
deweloperski działa na Windowsie (GHSA-g7r4-m6w7-qqqr). Vite 8 dopuszcza
`^0.27.0 || ^0.28.0`, ale `fontless@0.2.1` — wciągany przez `@nuxt/ui` →
`@nuxt/fonts` — deklaruje `^0.27.0` i trzymał całe drzewo na 0.27.7.

**Nadpisanie zakresu zadeklarowanego przez zależność jest ryzykiem i wymaga
dowodu, nie założenia.** Ten został sprawdzony na cztery sposoby: typecheck,
testy, `pnpm build` i budowa obrazu produkcyjnego od zera z `--frozen-lockfile`.
Każde podbicie tego wpisu wymaga powtórzenia całej czwórki.

Wpis **ma zniknąć**, gdy `@nuxt/fonts` podniesie `fontless` powyżej `^0.2.1`:
`fontless` 0.3+ nie zależy już od esbuilda w ogóle. Nadpisanie, które przestało
być potrzebne, to zamrożona wersja, o której nikt nie pamięta.
