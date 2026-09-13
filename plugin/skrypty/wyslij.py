#!/usr/bin/env python3
"""Wysyła szuflady z lokalnego pałaca MemPalace do wspólnej bazy WS_Memory.

To jest klient mostka z TODO-012: strona serwerowa (`POST /api/publish`) czekała
gotowa, ale nie miał jej kto zawołać poza testami. Ten skrypt jest tym, kto woła.

Dwie rzeczy o tym, jak czyta lokalny pałac, są decyzjami, nie szczegółami.

**Przez API pałaca, nigdy wprost z jego bazy.** Lokalny pałac to Chroma
w `~/.mempalace/palace` i dałoby się ją otworzyć wprost. Nie robimy tego:
schemat pałaca należy do MemPalace i jego kształt to szczegół implementacyjny
zależności (D-004). Rozmawiamy więc z `mempalace-mcp` po JSON-RPC — dokładnie
tak, jak rozmawia z nim agent AI.

**Treść bierzemy po jednej szufladzie.** `mempalace_list_drawers` zwraca
`content_preview`, czyli tekst ucięty. Wysłanie podglądu zamiast treści dałoby
bazę wiedzy pełną urwanych zdań, a wygląda to identycznie jak dane prawdziwe —
awaria cicha. Stąd `mempalace_get_drawer` na każdą szufladę osobno.

Idempotencja jest po stronie serwera: para (replika, identyfikator szuflady) ma
unikalność w `ws.memory_entries`, więc powtórna wysyłka aktualizuje wiersz,
zamiast tworzyć drugi. Dzięki temu przerwany przebieg powtarza się bezkarnie.

Użycie:
    plugin/skrypty/wyslij.py --skrzydlo wing_websystems --przestrzen baza-wiedzy
    plugin/skrypty/wyslij.py --skrzydlo sessions --od 2026-09-01 --podglad
    plugin/skrypty/wyslij.py --skrzydlo 2.0 --pokoj diary --limit 50

Konfiguracja (kolejność: argument, zmienna środowiskowa, konfiguracja wtyczki, plik):
    --adres / WS_URL / CLAUDE_PLUGIN_OPTION_URL      adres instancji
    --token / WS_TOKEN / CLAUDE_PLUGIN_OPTION_TOKEN  token agenta
    ~/.ws-memory/konfiguracja.json  {"url": ..., "token": ..., "replica": ...}

Zwykle nie uruchamia się tego ręcznie: woła go polecenie `/ws-publish`
z wtyczki, które dostaje adres i token z jej konfiguracji.

Kody wyjścia: 0 = wysłane, 1 = porażka, 2 = zły argument, 3 = brak konfiguracji.
"""

from __future__ import annotations

import argparse
import json
import os
import socket
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any

KATALOG = Path.home() / ".ws-memory"
PLIK_KONFIGURACJI = KATALOG / "konfiguracja.json"

# Ile szuflad w jednym żądaniu. Serwer liczy wektor dla każdej, więc partia jest
# kompromisem: za duża to żądanie, które wchodzi w limit czasu i którego nie da
# się bezpiecznie powtórzyć w kawałkach; za mała to jedno żądanie HTTP na
# szufladę. Dwadzieścia pięć przy 100 tysiącach szuflad daje cztery tysiące
# żądań i każde z nich wraca w kilka sekund.
PARTIA = 25


class Blad(Exception):
    """Coś, co człowiek ma zobaczyć jako zdanie, a nie jako ślad stosu."""


# ── Lokalny pałac ────────────────────────────────────────────────────────────


class Palac:
    """Lokalny MemPalace, przez jego własny serwer MCP.

    Jeden proces na cały przebieg, a nie jeden na wywołanie: uruchomienie
    `mempalace-mcp` wczytuje Chromę, co przy pałacu na 777 MB trwa sekundy.
    Przy stu tysiącach szuflad byłoby to sto tysięcy takich startów.
    """

    def __init__(self, polecenie: str = "mempalace-mcp") -> None:
        try:
            self.proces = subprocess.Popen(
                [polecenie],
                stdin=subprocess.PIPE,
                stdout=subprocess.PIPE,
                stderr=subprocess.DEVNULL,
                text=True,
                bufsize=1,
            )
        except FileNotFoundError as brak:
            raise Blad(
                f"Nie ma polecenia „{polecenie}”. Czy MemPalace jest zainstalowany "
                "(pip install mempalace) i czy jest w PATH?"
            ) from brak

        self.numer = 0
        self._uzgodnij()

    def _uzgodnij(self) -> None:
        self._wywolaj(
            "initialize",
            {
                "protocolVersion": "2024-11-05",
                "capabilities": {},
                "clientInfo": {"name": "ws-memory-wyslij", "version": "1"},
            },
        )
        self._powiadom("notifications/initialized")

    def _powiadom(self, metoda: str) -> None:
        assert self.proces.stdin is not None
        self.proces.stdin.write(json.dumps({"jsonrpc": "2.0", "method": metoda}) + "\n")
        self.proces.stdin.flush()

    def _wywolaj(self, metoda: str, parametry: dict[str, Any]) -> dict[str, Any]:
        assert self.proces.stdin is not None and self.proces.stdout is not None

        self.numer += 1
        zadanie = {"jsonrpc": "2.0", "id": self.numer, "method": metoda, "params": parametry}
        self.proces.stdin.write(json.dumps(zadanie) + "\n")
        self.proces.stdin.flush()

        while True:
            linia = self.proces.stdout.readline()
            if not linia:
                raise Blad("Lokalny pałac przestał odpowiadać (proces MCP się zakończył).")

            try:
                odpowiedz = json.loads(linia)
            except json.JSONDecodeError:
                # MemPalace potrafi wypisać na stdout coś, co nie jest odpowiedzią
                # JSON-RPC. Pomijamy zamiast przerywać: to nie nasza część kontraktu.
                continue

            if odpowiedz.get("id") != self.numer:
                continue

            if "error" in odpowiedz:
                raise Blad(f"Pałac odmówił ({metoda}): {odpowiedz['error']}")

            return odpowiedz.get("result", {})

    def narzedzie(self, nazwa: str, parametry: dict[str, Any]) -> dict[str, Any]:
        wynik = self._wywolaj("tools/call", {"name": nazwa, "arguments": parametry})
        tresc = wynik.get("content", [])

        if not tresc or not isinstance(tresc, list):
            raise Blad(f"Puste wywołanie narzędzia {nazwa}.")

        tekst = tresc[0].get("text", "")
        try:
            return json.loads(tekst)
        except json.JSONDecodeError as zly:
            raise Blad(f"Narzędzie {nazwa} zwróciło coś, co nie jest JSON-em: {tekst[:200]}") from zly

    def zamknij(self) -> None:
        if self.proces.stdin is not None:
            self.proces.stdin.close()
        self.proces.terminate()
        try:
            self.proces.wait(timeout=10)
        except subprocess.TimeoutExpired:
            self.proces.kill()


# ── Serwer WS_Memory ─────────────────────────────────────────────────────────


def wyslij_partie(
    adres: str,
    token: str,
    replika: str,
    przestrzen: str | None,
    szuflady: list[dict[str, Any]],
    podglad: bool,
) -> dict[str, Any]:
    ciało = {
        "replica": replika,
        "drawers": szuflady,
        "preview": podglad,
    }
    if przestrzen:
        ciało["space"] = przestrzen

    zadanie = urllib.request.Request(
        adres.rstrip("/") + "/api/publish",
        data=json.dumps(ciało).encode("utf-8"),
        headers={"Content-Type": "application/json", "Authorization": f"Bearer {token}"},
        method="POST",
    )

    try:
        with urllib.request.urlopen(zadanie, timeout=300) as odpowiedz:
            return json.loads(odpowiedz.read().decode("utf-8"))
    except urllib.error.HTTPError as blad:
        tresc = blad.read().decode("utf-8", errors="replace")
        # Kody są częścią kontraktu (patrz PublishController): 503 znaczy
        # „wróć później", reszta znaczy „nie powtarzaj tego samego".
        if blad.code == 503:
            raise Blad(f"Serwer chwilowo niedostępny (503). Powtórz później. {tresc[:300]}") from blad
        raise Blad(f"Serwer odrzucił partię ({blad.code}): {tresc[:500]}") from blad
    except urllib.error.URLError as blad:
        raise Blad(f"Nie mogę połączyć się z {adres}: {blad.reason}") from blad


# ── Konfiguracja ─────────────────────────────────────────────────────────────


def wczytaj_konfiguracje(argumenty: argparse.Namespace) -> tuple[str, str, str]:
    z_pliku: dict[str, Any] = {}
    if PLIK_KONFIGURACJI.exists():
        z_pliku = json.loads(PLIK_KONFIGURACJI.read_text(encoding="utf-8"))

    # `CLAUDE_PLUGIN_OPTION_*` to konfiguracja wtyczki podana przy jej instalacji
    # — ten sam adres i token, których używa serwer MCP z `plugin.json`. Dzięki
    # temu `/ws-publish` działa od razu po instalacji wtyczki i nie ma drugiego
    # miejsca, w którym trzyma się to samo poświadczenie.
    adres = (
        argumenty.adres
        or os.environ.get("WS_URL")
        or os.environ.get("CLAUDE_PLUGIN_OPTION_URL")
        or z_pliku.get("url")
    )
    token = (
        argumenty.token
        or os.environ.get("WS_TOKEN")
        or os.environ.get("CLAUDE_PLUGIN_OPTION_TOKEN")
        or z_pliku.get("token")
    )
    # Nazwa repliki musi być STAŁA dla tej maszyny: to po niej serwer poznaje,
    # że druga wysyłka tej samej szuflady jest tą samą szufladą. Nazwa hosta
    # jest stabilna i nie wymaga niczego zapisywać.
    replika = argumenty.replika or os.environ.get("WS_REPLICA") or z_pliku.get("replica") or socket.gethostname()

    if not adres or not token:
        raise Blad(
            "Brak adresu albo tokena. Podaj --adres i --token, ustaw WS_URL i WS_TOKEN "
            f"albo zapisz je w {PLIK_KONFIGURACJI}:\n"
            '  {"url": "http://127.0.0.1:8080", "token": "wsm_...", "replica": "laptop-artur"}'
        )

    return adres, token, replika


# ── Przebieg ─────────────────────────────────────────────────────────────────


def zbierz_szuflade(palac: Palac, podsumowanie: dict[str, Any]) -> dict[str, Any] | None:
    """Jedna szuflada w postaci, której oczekuje `/api/publish`."""
    identyfikator = podsumowanie.get("drawer_id")
    if not identyfikator:
        return None

    pelna = palac.narzedzie("mempalace_get_drawer", {"drawer_id": identyfikator})
    tresc = pelna.get("content") or pelna.get("document") or ""
    if not tresc.strip():
        return None

    metadane = pelna.get("metadata") or podsumowanie.get("metadata") or {}

    szuflada: dict[str, Any] = {
        "id": identyfikator,
        "wing": pelna.get("wing") or podsumowanie.get("wing") or metadane.get("wing") or "",
        "content": tresc,
    }

    for klucz_nasz, klucz_ich in (("room", "room"), ("filedAt", "filed_at"), ("sourcePath", "source_file")):
        wartosc = pelna.get(klucz_ich) or podsumowanie.get(klucz_ich) or metadane.get(klucz_ich)
        if isinstance(wartosc, str) and wartosc.strip():
            szuflada[klucz_nasz] = wartosc

    temat = metadane.get("topic")
    if isinstance(temat, str) and temat.strip():
        szuflada["title"] = temat

    return szuflada


def policz(
    raport: dict[str, Any],
    wyslane: int,
    pominiete_przez_serwer: int,
    partie: list[str],
) -> tuple[int, int]:
    """Odczytuje raport serwera.

    Nazwy pól są takie, jakie wypisuje `PublishReport::toArray()`: `written`
    i `skipped`. Pierwsza wersja czytała nieistniejące `stored` i wypisywała
    „zapisanych 0" po **udanej** wysyłce dwudziestu dwóch szuflad — czyli
    kłamała w najgorszą stronę, bo kazała powtarzać coś, co się udało.
    """
    wyslane += int(raport.get("written", 0))
    pominiete_przez_serwer += int(raport.get("skipped", 0))

    for partia in raport.get("batches", []):
        if isinstance(partia, dict) and partia.get("id"):
            partie.append(str(partia["id"]))

    return wyslane, pominiete_przez_serwer


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Wysyła szuflady z lokalnego pałaca do wspólnej bazy WS_Memory.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__,
    )
    parser.add_argument("--skrzydlo", help="tylko to skrzydło")
    parser.add_argument("--pokoj", help="tylko ten pokój")
    parser.add_argument("--od", help="szuflady od tej daty (włącznie), np. 2026-09-01")
    parser.add_argument("--do", dest="do_daty", help="szuflady przed tą datą (wyłącznie)")
    parser.add_argument("--przestrzen", help="przestrzeń docelowa; bez tego decyduje reguła lądowania")
    parser.add_argument("--limit", type=int, help="wyślij najwyżej tyle szuflad")
    parser.add_argument("--partia", type=int, default=PARTIA, help=f"ile szuflad w jednym żądaniu (domyślnie {PARTIA})")
    parser.add_argument("--podglad", action="store_true", help="nie zapisuj niczego, pokaż raport")
    parser.add_argument("--adres", help="adres instancji WS_Memory")
    parser.add_argument("--token", help="token agenta")
    parser.add_argument("--replika", help="nazwa tej kopii pałaca (domyślnie nazwa hosta)")
    argumenty = parser.parse_args()

    try:
        adres, token, replika = wczytaj_konfiguracje(argumenty)
    except Blad as blad:
        print(str(blad), file=sys.stderr)
        return 3

    filtr: dict[str, Any] = {}
    if argumenty.skrzydlo:
        filtr["wing"] = argumenty.skrzydlo
    if argumenty.pokoj:
        filtr["room"] = argumenty.pokoj
    if argumenty.od:
        filtr["since"] = argumenty.od
    if argumenty.do_daty:
        filtr["before"] = argumenty.do_daty

    palac = None
    wyslane = pominiete = pominiete_przez_serwer = 0
    partie: list[str] = []

    try:
        palac = Palac()

        pierwsza = palac.narzedzie("mempalace_list_drawers", {**filtr, "limit": 1, "offset": 0})
        wszystkich = int(pierwsza.get("total", 0))
        do_wyslania = min(wszystkich, argumenty.limit) if argumenty.limit else wszystkich

        print(f"Pałac lokalny: {wszystkich} szuflad pasuje do filtra, wysyłam {do_wyslania}.")
        print(f"Replika: {replika} → {adres}" + (f" (przestrzeń {argumenty.przestrzen})" if argumenty.przestrzen else ""))
        if argumenty.podglad:
            print("PODGLĄD: serwer nic nie zapisze.")
        print()

        przesuniecie = 0
        bufor: list[dict[str, Any]] = []

        while przesuniecie < do_wyslania:
            strona = palac.narzedzie(
                "mempalace_list_drawers",
                {**filtr, "limit": min(100, do_wyslania - przesuniecie), "offset": przesuniecie},
            )
            lista = strona.get("drawers", [])
            if not lista:
                break

            for podsumowanie in lista:
                przesuniecie += 1
                szuflada = zbierz_szuflade(palac, podsumowanie)
                if szuflada is None:
                    pominiete += 1
                    continue

                bufor.append(szuflada)

                if len(bufor) >= argumenty.partia:
                    wyslane, pominiete_przez_serwer = policz(
                        wyslij_partie(adres, token, replika, argumenty.przestrzen, bufor, argumenty.podglad),
                        wyslane,
                        pominiete_przez_serwer,
                        partie,
                    )
                    print(f"  {przesuniecie}/{do_wyslania} — zapisanych łącznie {wyslane}"
                          + (f", pominiętych przez filtr {pominiete_przez_serwer}" if pominiete_przez_serwer else ""))
                    bufor = []

        if bufor:
            wyslane, pominiete_przez_serwer = policz(
                wyslij_partie(adres, token, replika, argumenty.przestrzen, bufor, argumenty.podglad),
                wyslane,
                pominiete_przez_serwer,
                partie,
            )
            print(f"  {przesuniecie}/{do_wyslania} — zapisanych łącznie {wyslane}"
                  + (f", pominiętych przez filtr {pominiete_przez_serwer}" if pominiete_przez_serwer else ""))

    except Blad as blad:
        print(f"\nPRZERWANE: {blad}", file=sys.stderr)
        print("Powtórzenie tego samego polecenia jest bezpieczne — serwer nie tworzy duplikatów.", file=sys.stderr)
        return 1
    except KeyboardInterrupt:
        print("\nPrzerwane ręcznie. To, co poszło, zostaje; powtórzenie jest bezpieczne.", file=sys.stderr)
        return 1
    finally:
        if palac is not None:
            palac.zamknij()

    print()
    print(f"Zapisanych szuflad: {wyslane}")
    if pominiete_przez_serwer:
        print(f"Pominiętych przez filtr sekretów: {pominiete_przez_serwer}")
    if pominiete:
        print(f"Pominiętych lokalnie (pusta treść): {pominiete}")
    if partie:
        print(f"Partie publikacji: {len(partie)} (wycofanie: POST /api/publish/<id>/revert)")

    return 0


if __name__ == "__main__":
    sys.exit(main())
