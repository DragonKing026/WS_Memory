---
noteId: "986e0eb1aeb111f1997d030a3cd38ca7"
tags: [ws-memory, dokumentacja, frontend, vue, vite, ui]

---

# Frontend

Stan: **projekt**, nieimplementowany (2026-09-12).

Osobna aplikacja Vue 3, niezależna od backendu (D-008). Stack i struktura
przeniesione z nowszego projektu z frontendem Vue, żeby zespół nie uczył się drugiego
zestawu konwencji.

## Stack

Vue 3 (`<script setup>` + TypeScript) · Vite 7 · Nuxt UI 4 · Tailwind 4 ·
Pinia · vue-router 5 z routingiem plikowym (`unplugin-vue-router`) · Zod 4 ·
Axios · Vitest 4 · pnpm 10 · Node 22 (`.nvmrc`).

Dodatkowo, specyficznie dla WS_Memory: **CodeMirror 6** jako edytor Markdown
(D-009) i `markdown-it` do podglądu.

## Struktura `frontend/src/`

| Katalog | Przeznaczenie |
|---|---|
| `pages/` | routing plikowy — struktura katalogów = struktura URL |
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
| `/login` | logowanie; rejestracja wyłącznie z linku zaproszenia |
| `/` | wyszukiwanie na całej dostępnej wiedzy + ostatnie zmiany |
| `/s/:space` | przestrzeń: drzewo dokumentów, ostatnie zapisy agentów |
| `/s/:space/:slug` | dokument: treść, autor, znacznik weryfikacji, historia |
| `/s/:space/:slug/edit` | edytor: CodeMirror + podgląd obok |
| `/s/:space/:slug/history` | lista rewizji, porównanie dwóch dowolnych, cofnięcie |
| `/s/:space/proposals` | kolejka propozycji (tylko gdy przestrzeń ją wymaga) |
| `/memory` | surowa pamięć: szuflady, dziennik, graf wiedzy — z filtrami |
| `/settings/tokens` | tokeny agentów: wystawianie, zakres, unieważnianie |
| `/admin/*` | użytkownicy, zaproszenia, przestrzenie, role, audyt |

## Zasady

**Jeden klient HTTP.** `api/client.ts` to jedyne miejsce znające ścieżkę
`/api`, dołączające token i obsługujące odświeżanie JWT. Komponent nigdy nie
woła Axiosa bezpośrednio.

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

Dev jak w 2.0: kontener Node 22 + pnpm + Vite, nginx proxuje `/` na
`frontend:5173` razem z websocketem HMR, `/api` i `/mcp` na backend. Edycja
plików na hoście jest natychmiast widoczna w przeglądarce, a przeglądarka nie
dotyka CORS-a, bo wszystko jest na jednym origin.

Produkcja: `pnpm build` → statyczne `dist/` serwowane przez nginx. Frontend
nie potrzebuje w produkcji żadnego procesu Node.
