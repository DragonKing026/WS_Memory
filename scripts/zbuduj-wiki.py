#!/usr/bin/env python3
"""Buduje zawartość wiki GitHuba z dokumentacji w repozytorium.

Wiki jest **renderowanym lustrem**, nie drugim źródłem prawdy. Powód jest ten
sam, dla którego istnieje `sprawdz-dokumentacje.py`: dwa miejsca z tą samą
treścią rozjeżdżają się zawsze, a rozjazd w dokumentacji jest gorszy od jej
braku — nieaktualny opis bywa traktowany jako fakt, także przez modele AI.

Dlatego każda strona nosi ostrzeżenie, że ręczna edycja zostanie nadpisana,
a workflow wypycha całość po każdym scaleniu do `main`.

Użycie:
    ./scripts/zbuduj-wiki.py <katalog wyjściowy>
"""
from __future__ import annotations

import re
import shutil
import sys
from pathlib import Path

KORZEN = Path(__file__).resolve().parent.parent

# Źródło → (nazwa strony wiki, tytuł w nawigacji, grupa)
STRONY: dict[str, tuple[str, str, str]] = {
    "README.md": ("Home", "Start", "pl"),
    "AGENTS.md": ("Kontrakt-projektu", "Kontrakt projektu", "pl"),
    "docs/01-architektura.md": ("01-Architektura", "Architektura", "pl"),
    "docs/02-model-danych.md": ("02-Model-danych", "Model danych", "pl"),
    "docs/03-mcp-gateway.md": ("03-Gateway-MCP", "Gateway MCP", "pl"),
    "docs/04-plugin.md": ("04-Plugin", "Plugin", "pl"),
    "docs/05-deployment.md": ("05-Deployment", "Deployment", "pl"),
    "docs/06-decyzje.md": ("06-Decyzje", "Decyzje techniczne", "pl"),
    "docs/07-frontend.md": ("07-Frontend", "Frontend", "pl"),
    "docs/08-backend.md": ("08-Backend", "Backend", "pl"),
    "docs/09-ci.md": ("09-CI", "Ciągła integracja", "pl"),
    "CHANGELOG.md": ("Historia-zmian", "Historia zmian", "pl"),
    "AGENTS.en.md": ("EN-Project-contract", "Project contract", "en"),
    "README.en.md": ("EN-Overview", "Overview", "en"),
    "docs/en/01-architecture.md": ("EN-01-Architecture", "Architecture", "en"),
    "docs/en/02-data-model.md": ("EN-02-Data-model", "Data model", "en"),
    "docs/en/03-mcp-gateway.md": ("EN-03-MCP-gateway", "MCP gateway", "en"),
    "docs/en/04-plugin.md": ("EN-04-Plugin", "Plugin", "en"),
    "docs/en/05-deployment.md": ("EN-05-Deployment", "Deployment", "en"),
    "docs/en/06-decisions.md": ("EN-06-Decisions", "Decisions", "en"),
    "docs/en/07-frontend.md": ("EN-07-Frontend", "Frontend", "en"),
    "docs/en/08-backend.md": ("EN-08-Backend", "Backend", "en"),
    "docs/en/09-ci.md": ("EN-09-CI", "Continuous integration", "en"),
}

OSTRZEZENIE_PL = (
    "> ⚠️ **Strona generowana z repozytorium — ręczna edycja zostanie "
    "nadpisana.**\n> Źródło: [`{zrodlo}`](https://github.com/DragonKing026/"
    "WS_Memory/blob/main/{zrodlo}). Poprawki wnoś tam."
)
OSTRZEZENIE_EN = (
    "> ⚠️ **This page is generated from the repository — manual edits will be "
    "overwritten.**\n> Source: [`{zrodlo}`](https://github.com/DragonKing026/"
    "WS_Memory/blob/main/{zrodlo}). Make corrections there."
)

FRONTMATTER = re.compile(r"\A---\n.*?\n---\n+", re.S)
ODNOSNIK = re.compile(r"\[([^\]]+)\]\(([^)\s]+?)(?:#[^)]*)?\)")


def nazwa_strony_dla(sciezka: str, zrodlo: str) -> str | None:
    """Rozwiązuje odnośnik względny na nazwę strony wiki, albo zwraca None."""
    if sciezka.startswith(("http://", "https://", "#", "mailto:")):
        return None

    katalog = Path(zrodlo).parent
    kandydat = (katalog / sciezka).as_posix()
    # normalizacja ../ w ścieżce
    kandydat = Path(kandydat).resolve().relative_to(KORZEN).as_posix() \
        if (KORZEN / kandydat).exists() else kandydat.lstrip("./")

    wpis = STRONY.get(kandydat) or STRONY.get(sciezka.lstrip("./"))
    return wpis[0] if wpis else None


def przepisz_odnosniki(tresc: str, zrodlo: str) -> str:
    """Zamienia ścieżki plików na nazwy stron wiki.

    Bez tego każdy odnośnik w wiki prowadziłby donikąd — a dokumentacja,
    w której nie da się kliknąć dalej, przestaje być czytana.
    """
    def zamien(m: re.Match[str]) -> str:
        etykieta, cel = m.group(1), m.group(2)
        strona = nazwa_strony_dla(cel, zrodlo)
        if strona:
            return f"[{etykieta}]({strona})"
        if cel.startswith(("http", "#", "mailto:")):
            return m.group(0)
        # Pozostałe pliki repozytorium (skrypty, TODO) → odnośnik na GitHuba,
        # żeby czytelnik wiki nie trafiał w pustkę.
        return f"[{etykieta}](https://github.com/DragonKing026/WS_Memory/blob/main/{cel.lstrip('./')})"

    return ODNOSNIK.sub(zamien, tresc)


def zbuduj_sidebar() -> str:
    pl = [f"  - [{tytul}]({strona})" for zrodlo, (strona, tytul, grupa)
          in STRONY.items() if grupa == "pl" and strona != "Home"]
    en = [f"  - [{tytul}]({strona})" for zrodlo, (strona, tytul, grupa)
          in STRONY.items() if grupa == "en"]

    return "\n".join([
        "### WS_Memory",
        "",
        "- [Start](Home)",
        "",
        "**Dokumentacja**",
        *pl,
        "",
        "**English**",
        *en,
        "",
        "---",
        "",
        "_Wiki jest generowana z katalogu `docs/`._",
        "_Edycje ręczne są nadpisywane._",
        "",
    ])


def main() -> int:
    if len(sys.argv) < 2:
        print("Użycie: zbuduj-wiki.py <katalog wyjściowy>", file=sys.stderr)
        return 2

    wyjscie = Path(sys.argv[1])
    wyjscie.mkdir(parents=True, exist_ok=True)

    # Usuwamy stare strony (poza katalogiem .git wiki), żeby zniknięcie
    # dokumentu w repozytorium zniknęło też w wiki.
    for stary in wyjscie.glob("*.md"):
        stary.unlink()

    zbudowane = 0
    for zrodlo, (strona, tytul, grupa) in STRONY.items():
        plik = KORZEN / zrodlo
        if not plik.exists():
            print(f"  pomijam {zrodlo} — brak pliku")
            continue

        tresc = FRONTMATTER.sub("", plik.read_text(encoding="utf-8"))
        tresc = przepisz_odnosniki(tresc, zrodlo)

        szablon = OSTRZEZENIE_EN if grupa == "en" else OSTRZEZENIE_PL
        naglowek = szablon.format(zrodlo=zrodlo)

        (wyjscie / f"{strona}.md").write_text(
            f"{naglowek}\n\n{tresc}", encoding="utf-8"
        )
        zbudowane += 1

    (wyjscie / "_Sidebar.md").write_text(zbuduj_sidebar(), encoding="utf-8")

    print(f"Zbudowano {zbudowane} stron wiki w {wyjscie}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
