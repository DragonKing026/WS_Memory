---
noteId: "32101d30aeb011f1997d030a3cd38ca7"
tags: []

---

# CHANGELOG — WS_Memory

Wszystkie istotne zmiany w projekcie, z datą i godziną. Najnowsze na górze.
Format: `## RRRR-MM-DD GG:MM — tytuł`.

---

## 2026-09-12 16:12 — Spec projektowy i dwanaście zadań wdrożeniowych

- `docs/superpowers/specs/2026-09-12-ws-memory-design.md` — spec utrwalający
  decyzje, kryteria ukończenia projektu i ryzyka wraz z ich obsługą.
- `TODO/000` … `TODO/011` — **dwanaście** zadań, każde z sekcjami Powód,
  Analiza, Rozwiązanie i sprawdzalnymi Kryteriami ukończenia.
- `TODO/README.md` — zasady prowadzenia zadań, przenoszenia do `DONE/`
  i graf zależności między zadaniami.
- `.gitignore` — wykluczenia dla Symfony, Node, Dockera i sekretów.

Kolejność wynika z jednej zasady: zadanie `000` nie zawiera ani linii Symfony,
bo najpierw trzeba udowodnić, że polskie zapytanie znajduje polską treść przez
centralny serwer embeddingów. Budowanie interfejsu nad nieudowodnionym
fundamentem byłoby marnotrawstwem.

---

## 2026-09-12 16:02 — Rozdzielenie backendu i frontendu

Na wniosek zmieniono warstwę prezentacji: zamiast monolitu z Twigiem — czyste
API Symfony plus osobna aplikacja Vue 3. Wzorzec przeniesiony z Precision
Telemed 2.0 (sprawdzony w zespole), potwierdzony wyszukiwaniem w pałacu.

- **D-008** — rozdzielenie backendu i frontendu; backend wystawia REST `/api`
  i MCP `/mcp` nad wspólnymi serwisami domenowymi, frontend to osobna
  aplikacja Vue 3 + Vite 7 + Nuxt UI 4 + Tailwind 4 + Pinia + Zod.
  `nginx` trzyma oba na jednym origin, więc przeglądarka nie dotyka CORS-a.
- **D-009** — edytor wiki na CodeMirror 6, **nie** WYSIWYG. Uzasadnienie:
  dokumenty krążą między ludźmi i AI, a każdy obieg przez model dokumentu
  WYSIWYG gubi to, czego ten model nie obsługuje. Markdown jako jedyna
  reprezentacja usuwa tę klasę błędów.
- **D-001** oznaczona jako zmieniona w zakresie warstwy prezentacji.
- Dodano regułę nienaruszalną nr 9: backend nie renderuje interfejsu,
  frontend nie zna bazy; jedyny kontrakt to OpenAPI.
- Liczba usług w Compose: 6 → 7 (doszedł `frontend`); `app` przemianowany
  na `backend`.
- Nowy dokument `docs/07-frontend.md` — stack, struktura katalogów, ekrany.
- Dopisano do `AGENTS.md` konwencje gita: jeden commit = jedna zamknięta myśl,
  commit obejmuje kod razem z dokumentacją i wpisem w CHANGELOG.

---

## 2026-09-12 15:43 — Projekt zatwierdzony, dokumentacja założycielska

Etap projektowania zakończony. Kodu jeszcze nie ma.

**Rozpoznanie MemPalace 3.7.0 (jako zależności):**
- Ustalono, że `mempalace serve` wystawia HTTP MCP pod `POST /mcp` z **jednym
  wspólnym tokenem** dla wszystkich klientów — brak tożsamości per użytkownik.
  To wyznaczyło właściwy zakres WS_Memory.
- Odkryto, że MemPalace ma pełnoprawny backend **pgvector** z izolacją przez
  namespace i bezpieczeństwem przy wielu pisarzach. Pozwoliło to trzymać pałac
  i dane aplikacji w jednym Postgresie.
- Ustalono, że mechanizm hub-forward działa wyłącznie w obrębie jednej maszyny
  (wykrywanie huba przez plik w katalogu pałaca) — nie nadaje się do pracy
  zdalnej zespołu.
- Ustalono, że domyślny model embeddingów `minilm` jest trenowany **tylko na
  angielskim**, co dyskwalifikuje go dla bazy pisanej po polsku.

**Podjęte decyzje** (szczegóły w `docs/06-decyzje.md`):
- D-001 — Symfony 8 + MemPalace jako sidecar
- D-002 — PostgreSQL 18 + pgvector zamiast firmowego MariaDB
- D-003 — centralny serwer embeddingów, model `BAAI/bge-m3`
- D-004 — Postgres źródłem prawdy dla wiki, pałac warstwą wyszukiwania
- D-005 — agent zapisuje bez bramki; wersjonowanie jako siatka bezpieczeństwa
- D-006 — zamknięta sieć; hooki wysyłają transkrypty przez HTTPS
- D-007 — kurowany zestaw narzędzi MCP zamiast przepuszczania 36 narzędzi

**Dodane pliki:**
- `AGENTS.md` — kontrakt projektu: reguły nienaruszalne, stack, workflow, git
- `README.md` — opis projektu i spis dokumentacji
- `CHANGELOG.md` — ten plik
- `docs/01-architektura.md` … `docs/06-decyzje.md` — dokumentacja działania
