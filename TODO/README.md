---
noteId: "29ba8f60aeb211f1997d030a3cd38ca7"
tags: []

---

# TODO — zasady prowadzenia zadań

Jeden plik = jedno zadanie. Numeracja rosnąca, `NNN-krotki-opis.md`.

## Struktura pliku zadania

Każde zadanie ma cztery sekcje:

- **Powód** — dlaczego to robimy. Co jest teraz źle albo czego brakuje.
- **Analiza** — co sprawdziliśmy, jakie są opcje, na co trzeba uważać.
- **Rozwiązanie** — jak to robimy, w punktach, w kolejności wykonania.
- **Kryteria ukończenia** — sprawdzalne warunki. Nie „działa", a „polecenie X
  zwraca Y".

## Po ukończeniu

1. Dopisz sekcję **Co zostało zrobione** z datą i godziną: co powstało, co
   przetestowano, co odłożono i dlaczego. Fakty, nie deklaracje.
2. `git mv TODO/NNN-....md TODO/DONE/` — historia pliku zostaje zachowana.
3. Zaktualizuj dokumentację w `docs/`, jeśli zmieniło się zachowanie systemu.
4. Dopisz wpis do `CHANGELOG.md` z datą i godziną.
5. Zacommituj wszystko razem: kod, dokumentację, przeniesione zadanie, changelog.

Zadanie bez sekcji **Co zostało zrobione** nie trafia do `DONE/`.

## Kolejność i zależności

```
000 szkielet ──► 001 backend fundament ──► 002 konta i przestrzenie
                                              │
                          ┌───────────────────┼───────────────────┐
                          ▼                   ▼                   ▼
                   003 klient pałaca    005 wiki backend    006 frontend fundament
                          │                   │                   │
                          ▼                   │                   ▼
                   004 gateway MCP ◄──────────┘            007 szukanie (UI)
                          │                                       │
                          ▼                                       ▼
                   009 plugin                              008 edytor i historia
                          │
                          ▼
                   010 mielenie ──► 011 dopieszczenie
```

Zadania 000-002 są ściśle sekwencyjne. Dalej backend (003-005) i frontend
(006-008) mogą iść równolegle, bo stykają się tylko przez kontrakt OpenAPI.
