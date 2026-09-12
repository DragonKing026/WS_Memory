#!/usr/bin/env python3
"""Sprawdza odpowiedź JSON-RPC z serwera MCP.

Wydzielone z testu powłokowego celowo: mieszanie heredoc z here-stringiem w
jednym wywołaniu `python3 -` powoduje, że program i dane walczą o to samo
wejście standardowe, a test przewraca się z mylącym komunikatem zamiast
pokazać prawdziwy wynik.

Użycie:
    sprawdz_odpowiedz.py <szukana-fraza> < odpowiedz.json

Wypisuje: TAK / NIE / BŁĄD: <powód>, i kończy się kodem 0 zawsze — o wyniku
decyduje wywołujący, żeby mógł podać własny komunikat.
"""
import json
import sys


def main() -> int:
    if len(sys.argv) < 2:
        print("BŁĄD: brak szukanej frazy w argumentach")
        return 0

    fraza = sys.argv[1]
    surowe = sys.stdin.read().strip()

    if not surowe:
        print("BŁĄD: serwer nie zwrócił nic")
        return 0

    try:
        odpowiedz = json.loads(surowe)
    except ValueError as e:
        print(f"BŁĄD: odpowiedź nie jest poprawnym JSON-em ({e}); "
              f"pierwsze 200 znaków: {surowe[:200]}")
        return 0

    if "error" in odpowiedz:
        print(f"BŁĄD: serwer zwrócił błąd JSON-RPC: {odpowiedz['error']}")
        return 0

    # Narzędzia MCP zwracają wynik jako tekst w result.content[].text —
    # zwykle jest to zserializowany JSON, ale szukamy w nim po prostu frazy.
    czesci = odpowiedz.get("result", {}).get("content", [])
    tekst = "".join(c.get("text", "") for c in czesci)

    if not tekst:
        print("BŁĄD: odpowiedź nie zawiera treści (result.content jest puste)")
        return 0

    # MemPalace nie zgłasza awarii w kopercie JSON-RPC — odpowiedź jest
    # „poprawna", a błąd siedzi w treści narzędzia razem z pustą listą wyników.
    # Bez tego sprawdzenia niedostępny serwer embeddingów wygląda dokładnie tak
    # samo jak model, który nie rozumie polskiego. To dwie zupełnie różne
    # awarie i mylenie ich kosztuje godziny.
    try:
        wewnetrzne = json.loads(tekst)
    except ValueError:
        wewnetrzne = None
    if isinstance(wewnetrzne, dict) and wewnetrzne.get("error"):
        print(f"BŁĄD: narzędzie zwróciło błąd: {wewnetrzne['error']}")
        return 0

    print("TAK" if fraza in tekst else "NIE")
    return 0


if __name__ == "__main__":
    sys.exit(main())
