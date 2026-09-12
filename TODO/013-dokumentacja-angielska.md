---
tags: [ws-memory, todo, dokumentacja, i18n]
---

# TODO-013 — Angielskie odpowiedniki dokumentacji

**Utworzono:** 2026-09-12 19:40 · **Stan:** do zrobienia · **Zależności:** brak

## Powód

Ustalono, że dokumentacja ma być dwujęzyczna: polska wiodąca, angielska
równoległa. Kod jest już w całości po angielsku, więc osoba nieznająca
polskiego przeczyta źródła — ale nie zrozumie **dlaczego** są takie, a to
właśnie mieszka w `docs/` i w decyzjach `D-0xx`.

Angielska wersja przyda się też agentom AI: modele pracują na angielskim
znacznie pewniej, a instrukcje projektowe będą wystawiane jako zasoby MCP
(D-013).

## Analiza

Do przetłumaczenia jest komplet: `AGENTS.md`, `README.md`, `docs/01`–`docs/07`,
spec projektowy i `TODO/README.md`. To około 1500 wierszy — jednorazowo dużo,
ale utrzymanie jest tanie, jeśli tłumaczenie idzie w tym samym commicie co
zmiana oryginału.

Ryzyko jest jedno i trzeba mu zapobiec konstrukcyjnie: **rozjazd wersji**.
Zapobiegamy tak, że polska jest wiodąca (rozstrzyga spory), a każdy plik
angielski nosi w nagłówku informację, z jakiej wersji polskiego oryginału
został przetłumaczony.

Czego **nie** tłumaczymy: `CHANGELOG.md` (zapis historyczny, rośnie w
nieskończoność), zadań w `TODO/` (robocze, krótkotrwałe) i opisów commitów.
Tłumaczenie ich kosztowałoby stale, a nie służy nikomu poza archiwum.

## Rozwiązanie

1. `docs/en/` — struktura lustrzana wobec `docs/`, nazwy plików angielskie
   (`01-architecture.md`, `02-data-model.md`, …).
2. `AGENTS.en.md` i `README.en.md` w katalogu głównym, z odsyłaczem w obie
   strony w nagłówku każdego pliku.
3. Nagłówek każdego pliku angielskiego: „Translated from `docs/NN-....md`
   (<data ostatniej synchronizacji>). The Polish version is authoritative."
4. Tłumaczenie decyzji `D-001`–`D-015` z zachowaniem numeracji — numer
   decyzji jest wspólnym punktem odniesienia obu wersji.
5. Uzupełnienie `AGENTS.md` o wskazanie, że zmiana dokumentu obejmuje jego
   angielski odpowiednik (już dopisane).
6. Prosty test spójności: skrypt sprawdzający, czy każdy plik z `docs/` ma
   odpowiednik w `docs/en/` i czy numery decyzji się zgadzają.

## Kryteria ukończenia

- Każdy plik z `docs/` ma odpowiednik w `docs/en/`.
- Numery decyzji `D-0xx` są identyczne w obu wersjach.
- Nagłówek każdego pliku angielskiego wskazuje oryginał i datę synchronizacji.
- Skrypt spójności przechodzi i jest wpięty w `make`.
- `AGENTS.en.md` i `README.en.md` istnieją i odsyłają do wersji polskich.
