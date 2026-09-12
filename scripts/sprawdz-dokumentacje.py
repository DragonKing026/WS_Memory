#!/usr/bin/env python3
"""Sprawdza spójność dokumentacji polskiej i angielskiej.

Nie zastąpi przeczytania tekstu — żaden skrypt nie wie, czy opis odpowiada
rzeczywistości. Wyłapuje natomiast to, co da się wyłapać mechanicznie, a co
w praktyce rozjeżdża się najczęściej:

  1. plik w docs/ bez odpowiednika w docs/en/,
  2. rozjazd numerów decyzji D-0xx między wersjami,
  3. brak nagłówka wskazującego oryginał w pliku angielskim,
  4. odsyłacz do pliku, którego nie ma.

Uruchamiane przez `make sprawdz-dokumentacje`.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

KORZEN = Path(__file__).resolve().parent.parent
DOCS = KORZEN / "docs"
DOCS_EN = DOCS / "en"

# Polska nazwa pliku → angielska. Nazwy różnią się celowo: dokumenty są
# czytane, nie importowane, więc każda wersja ma nazwę w swoim języku.
ODPOWIEDNIKI = {
    "01-architektura.md": "01-architecture.md",
    "02-model-danych.md": "02-data-model.md",
    "03-mcp-gateway.md": "03-mcp-gateway.md",
    "04-plugin.md": "04-plugin.md",
    "05-deployment.md": "05-deployment.md",
    "06-decyzje.md": "06-decisions.md",
    "07-frontend.md": "07-frontend.md",
    "08-backend.md": "08-backend.md",
    "09-ci.md": "09-ci.md",
}

PARY_POZA_DOCS = {
    "AGENTS.md": "AGENTS.en.md",
    "README.md": "README.en.md",
    "TODO/README.md": "TODO/README.en.md",
}

NAGLOWEK_TLUMACZENIA = re.compile(r"^> Translated from ", re.M)
NUMER_DECYZJI = re.compile(r"^## (D-\d{3})\b", re.M)


def bledy() -> list[str]:
    znalezione: list[str] = []

    # 1. Każdy dokument z docs/ ma odpowiednik.
    for polski in sorted(DOCS.glob("*.md")):
        angielski = ODPOWIEDNIKI.get(polski.name)
        if angielski is None:
            znalezione.append(
                f"{polski.relative_to(KORZEN)}: brak wpisu w mapie ODPOWIEDNIKI "
                f"— dopisz go w scripts/sprawdz-dokumentacje.py"
            )
            continue
        if not (DOCS_EN / angielski).exists():
            znalezione.append(
                f"docs/en/{angielski}: brakuje — odpowiednik "
                f"{polski.relative_to(KORZEN)} istnieje"
            )

    for polski_nazwa, angielski_nazwa in PARY_POZA_DOCS.items():
        if (KORZEN / polski_nazwa).exists() and not (KORZEN / angielski_nazwa).exists():
            znalezione.append(f"{angielski_nazwa}: brakuje odpowiednika {polski_nazwa}")

    # 2. Numery decyzji muszą się zgadzać — to wspólny punkt odniesienia
    #    obu wersji i rozjazd tutaj jest najbardziej mylący.
    pl = DOCS / "06-decyzje.md"
    en = DOCS_EN / "06-decisions.md"
    if pl.exists() and en.exists():
        numery_pl = set(NUMER_DECYZJI.findall(pl.read_text(encoding="utf-8")))
        numery_en = set(NUMER_DECYZJI.findall(en.read_text(encoding="utf-8")))
        for brakujacy in sorted(numery_pl - numery_en):
            znalezione.append(f"docs/en/06-decisions.md: brak decyzji {brakujacy}")
        for nadmiarowy in sorted(numery_en - numery_pl):
            znalezione.append(
                f"docs/en/06-decisions.md: decyzja {nadmiarowy} nie istnieje "
                f"w wersji polskiej"
            )

    # 3. Każdy plik angielski deklaruje swój oryginał — bez tego czytelnik nie
    #    wie, która wersja rozstrzyga.
    angielskie = list(DOCS_EN.rglob("*.md"))
    angielskie += [KORZEN / n for n in PARY_POZA_DOCS.values() if (KORZEN / n).exists()]
    for plik in angielskie:
        if not NAGLOWEK_TLUMACZENIA.search(plik.read_text(encoding="utf-8")):
            znalezione.append(
                f"{plik.relative_to(KORZEN)}: brak nagłówka "
                "'Translated from ...' wskazującego polski oryginał"
            )

    return znalezione


def main() -> int:
    problemy = bledy()
    if not problemy:
        print("Dokumentacja spójna: odpowiedniki na miejscu, numery decyzji zgodne.")
        return 0

    print(f"Znaleziono {len(problemy)} problemów:\n")
    for problem in problemy:
        print(f"  • {problem}")
    print("\nSzczegoly zasady: AGENTS.md -> sekcja 'Definicja ukonczenia'.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
