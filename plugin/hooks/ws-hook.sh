#!/usr/bin/env bash
#
# WS_Memory — jeden skrypt hooka na wszystkie zdarzenia.
#
# Nazwa zdarzenia przychodzi argumentem, nie osobnym plikiem (D-013): pakowanie
# dla innego klienta AI ma być nowym manifestem, a nie nowym kodem.
#
# Twarda reguła tego skryptu: **nie czyta transkryptu i nic z rozmowy nie
# wysyła.** Mieleniem rozmów zajmuje się lokalny MemPalace, na tej maszynie
# (D-012). Jedyne, co ten skrypt wysyła na serwer, to wywołanie `ws_status`
# bez argumentów.
#
# Drugie: hook nigdy nie przerywa pracy. Brak sieci, brak tokena, padnięty
# serwer — sesja startuje normalnie, tylko bez wstrzykniętego kontekstu.
# Baza wiedzy nie jest pojedynczym punktem awarii dla codziennej pracy.

set -uo pipefail

zdarzenie="${1:-}"

# Konfiguracja przychodzi z `userConfig` wtyczki, nie ze zmiennych ustawianych
# ręcznie. Token nie występuje w żadnym pliku tego repozytorium.
adres="${CLAUDE_PLUGIN_OPTION_URL:-}"
token="${CLAUDE_PLUGIN_OPTION_TOKEN:-}"

LIMIT_SEKUND=5

# Cokolwiek się stanie — kończymy zerem. Kod niezerowy z hooka SessionStart
# potrafi zablokować start sesji, a to jest cena nieproporcjonalna do wartości
# jednego akapitu kontekstu.
milczaco_wyjdz() { exit 0; }

wstrzyknij() {
  # Udokumentowany kształt wyjścia SessionStart. Zwykłe stdout trafia do logu,
  # a nie do kontekstu — dlatego JSON, nie echo.
  python3 -c '
import json, sys
print(json.dumps({
    "hookSpecificOutput": {
        "hookEventName": "SessionStart",
        "additionalContext": sys.stdin.read(),
    }
}))' 2>/dev/null || true
}

status_sesji() {
  [ -n "$adres" ] && [ -n "$token" ] || milczaco_wyjdz
  command -v curl >/dev/null 2>&1 || milczaco_wyjdz
  command -v python3 >/dev/null 2>&1 || milczaco_wyjdz

  odpowiedz=$(
    curl -sS --max-time "$LIMIT_SEKUND" \
      -X POST "${adres%/}/mcp" \
      -H 'Content-Type: application/json' \
      -H "Authorization: Bearer ${token}" \
      -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"ws_status","arguments":{}}}' \
      2>/dev/null
  ) || milczaco_wyjdz

  [ -n "$odpowiedz" ] || milczaco_wyjdz

  # Wynik idzie do zmiennej, a nie prosto w potok do wstrzyknięcia: filtr
  # potrafi się poddać (błąd JSON-RPC, odpowiedź nie do sparsowania), a wtedy
  # potok i tak dowiózłby pustą ramkę. Wstrzyknięty pusty kontekst wygląda dla
  # agenta jak „baza nie ma nic", czyli mówi nieprawdę.
  kontekst=$(printf '%s' "$odpowiedz" | python3 -c '
import json, sys

try:
    odpowiedz = json.load(sys.stdin)
except Exception:
    sys.exit(1)

# Gateway odpowiada błędem JSON-RPC, a nie udanym wynikiem z polem błędu
# (D-023). Milczymy: „nie udało się sprawdzić" wstrzyknięte jako kontekst
# byłoby dla agenta gorsze niż brak kontekstu.
if "error" in odpowiedz:
    sys.exit(1)

try:
    stan = json.loads(odpowiedz["result"]["content"][0]["text"])
except Exception:
    sys.exit(1)

przestrzenie = stan.get("spaces") or []

wiersze = ["# Firmowa baza wiedzy Web Systems (WS_Memory)", ""]

if przestrzenie:
    wiersze.append("Ten token ma dostęp do następujących przestrzeni:")
    wiersze.append("")
    for p in przestrzenie:
        # Slug prywatnej przestrzeni to identyfikator właściciela, więc sam
        # w sobie nic nie mówi. Nazwa zostaje, bo agent potrzebuje jej
        # dosłownie do zapisu — dokładamy tylko to, czym ta przestrzeń jest.
        nazwa = (
            "Twoja przestrzeń prywatna (`{}`)".format(p.get("slug", "?"))
            if p.get("private")
            else "**{}**".format(p.get("slug", "?"))
        )
        wiersze.append(
            "- {nazwa} — rola: {rola}, wpisów: {wpisy}".format(
                nazwa=nazwa,
                rola=p.get("role") or "brak",
                wpisy=p.get("entries", 0),
            )
        )
else:
    wiersze.append(
        "Ten token nie ma jeszcze dostępu do żadnej przestrzeni. "
        "Zapytaj administratora, zanim zaczniesz cokolwiek zapisywać."
    )

domyslna = stan.get("default_write_space")
wiersze.append("")
if domyslna:
    wiersze.append(
        "Zapis bez wskazanej przestrzeni ląduje w `{}`. "
        "Dokument dla zespołu musi mieć przestrzeń podaną jawnie.".format(domyslna)
    )
else:
    wiersze.append(
        "Ten token nie ma domyślnej przestrzeni zapisu — każdy zapis musi "
        "wskazywać przestrzeń jawnie."
    )

if stan.get("scoped"):
    wiersze.append("")
    wiersze.append(
        "Token jest **zawężony** wobec uprawnień właściciela: część jego "
        "przestrzeni jest tu niewidoczna."
    )

wiersze += [
    "",
    "**Zanim odpowiesz o przeszłych ustaleniach, decyzjach, projektach albo "
    "osobach — poszukaj.** Najpierw `ws_search` (wiedza zespołu), potem "
    "`mempalace_search` (lokalny pałac). Nie zgaduj: zła odpowiedź wygląda "
    "tak samo jak dobra i wchodzi do kolejnych decyzji jako fakt.",
    "",
    "Pełny protokół: skill `ws-memory-recall` albo zasób MCP "
    "`ws-memory://protokol-recall`.",
]

print("\n".join(wiersze))
' 2>/dev/null) || milczaco_wyjdz

  [ -n "$kontekst" ] || milczaco_wyjdz

  printf '%s' "$kontekst" | wstrzyknij
}

case "$zdarzenie" in
  session-start) status_sesji ;;
  # Nowe zdarzenia dopisuje się tutaj. Nieznanego nie traktujemy jak błędu:
  # manifest bywa nowszy niż skrypt i nie ma powodu, żeby to psuło sesję.
  *) milczaco_wyjdz ;;
esac

exit 0
