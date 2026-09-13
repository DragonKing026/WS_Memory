#!/usr/bin/env python3
"""Sprawdza, czy zamknięte zadania są rozliczone i leżą tam, gdzie powinny.

`AGENTS.md` wymaga, żeby plik przeniesiony do `TODO/DONE/` niósł sekcję
**Co zostało zrobione** z datą i faktami. Bez tego katalog `DONE/` staje się
listą tytułów, z której po miesiącu nie wynika nic — a to właśnie te sekcje
tłumaczą później, dlaczego coś wygląda tak, a nie inaczej.

Zadanie **anulowane** rozlicza się inaczej: nic w nim nie powstało, więc zamiast
opisu wykonanej pracy wystarczy **Dlaczego anulowane**. Wymaganie od niego sekcji
„Co zostało zrobione" zmuszałoby do napisania rozliczenia z pracy, której nie było.

Reguła bez egzekwowania jest deklaracją, więc egzekwuje ją ten skrypt.
Uruchamiane przez `make sprawdz-zadania`.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

KORZEN = Path(__file__).resolve().parent.parent
DONE = KORZEN / "TODO" / "DONE"
AKTYWNE = KORZEN / "TODO"

NAGLOWEK_ZADANIA = re.compile(r"^# TODO-\d{3} — ", re.M)
SEKCJA_ROZLICZENIA = re.compile(r"^## Co zostało zrobione\s*$", re.M)
# Dopuszczamy cytat blokowy przed nagłówkiem: część zadań opakowuje powód
# anulowania w wyróżnienie („> ## Dlaczego anulowane"). Reguła jest o tym,
# czy powód jest zapisany, a nie o tym, jak został sformatowany.
SEKCJA_ANULOWANIA = re.compile(r"^>?\s*#+ Dlaczego anulowane\s*$", re.M)
STAN_ZAMKNIETY = re.compile(r"\*\*Stan:\*\*.*?(UKOŃCZONE|ANULOWANE)", re.S)
STAN_ANULOWANY = re.compile(r"\*\*Stan:\*\*.*?ANULOWANE", re.S)


def bledy() -> list[str]:
    znalezione: list[str] = []

    for plik in sorted(DONE.glob("*.md")):
        tresc = plik.read_text(encoding="utf-8")
        wzgledna = plik.relative_to(KORZEN)

        anulowane = bool(STAN_ANULOWANY.search(tresc))
        rozliczone = SEKCJA_ROZLICZENIA.search(tresc) or (
            anulowane and SEKCJA_ANULOWANIA.search(tresc)
        )

        if not rozliczone:
            oczekiwane = (
                "'Co zostało zrobione' albo 'Dlaczego anulowane'"
                if anulowane
                else "'Co zostało zrobione'"
            )
            znalezione.append(
                f"{wzgledna}: brak sekcji {oczekiwane} — zadanie bez "
                f"rozliczenia nie trafia do DONE/"
            )
        if not STAN_ZAMKNIETY.search(tresc):
            znalezione.append(
                f"{wzgledna}: stan nie mówi UKOŃCZONE ani ANULOWANE"
            )

    for plik in sorted(AKTYWNE.glob("[0-9]*.md")):
        tresc = plik.read_text(encoding="utf-8")
        wzgledna = plik.relative_to(KORZEN)

        if not NAGLOWEK_ZADANIA.search(tresc):
            znalezione.append(
                f"{wzgledna}: nagłówek musi mieć format '# TODO-NNN — Tytuł'"
            )
        # Zadanie zamknięte, ale wciąż leżące poza DONE/, to najczęstszy sposób,
        # w jaki katalog przestaje odpowiadać rzeczywistości.
        #
        # Anulowane liczy się tak samo jak ukończone i wcześniej tak nie było:
        # warunek zwalniał je z przenoszenia, więc TODO-010 leżało dobę na liście
        # zadań do zrobienia, mimo że anulowała je decyzja D-012 dzień wcześniej.
        # Zauważył to człowiek, pytając, czemu nie robimy go przed 011 — czyli
        # dokładnie tym kosztem, któremu ten skrypt ma zapobiegać.
        if STAN_ZAMKNIETY.search(tresc):
            stan = "anulowane" if STAN_ANULOWANY.search(tresc) else "ukończone"
            znalezione.append(
                f"{wzgledna}: oznaczone jako {stan}, ale leży poza DONE/ "
                f"— przenieś przez `git mv`"
            )

    return znalezione


def main() -> int:
    problemy = bledy()
    if not problemy:
        print("Zadania w porządku: rozliczenia na miejscu, stany zgodne z katalogiem.")
        return 0

    print(f"Znaleziono {len(problemy)} problemow:\n")
    for problem in problemy:
        print(f"  - {problem}")
    print("\nSzczegoly zasady: AGENTS.md -> sekcja 'Definicja ukonczenia'.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
