#!/usr/bin/env python3
"""Sprawdza, czy ukończone zadania mają sekcję rozliczającą.

`AGENTS.md` wymaga, żeby plik przeniesiony do `TODO/DONE/` niósł sekcję
**Co zostało zrobione** z datą i faktami. Bez tego katalog `DONE/` staje się
listą tytułów, z której po miesiącu nie wynika nic — a to właśnie te sekcje
tłumaczą później, dlaczego coś wygląda tak, a nie inaczej.

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
STAN_ZAMKNIETY = re.compile(r"\*\*Stan:\*\*.*?(UKOŃCZONE|ANULOWANE)", re.S)


def bledy() -> list[str]:
    znalezione: list[str] = []

    for plik in sorted(DONE.glob("*.md")):
        tresc = plik.read_text(encoding="utf-8")
        wzgledna = plik.relative_to(KORZEN)

        if not SEKCJA_ROZLICZENIA.search(tresc):
            znalezione.append(
                f"{wzgledna}: brak sekcji 'Co zostało zrobione' — zadanie bez "
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
        # Zadanie oznaczone jako ukończone, ale wciąż leżące poza DONE/ to
        # najczęstszy sposób, w jaki katalog przestaje odpowiadać rzeczywistości.
        if STAN_ZAMKNIETY.search(tresc) and "ANULOWANE" not in tresc:
            znalezione.append(
                f"{wzgledna}: oznaczone jako ukończone, ale leży poza DONE/ "
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
