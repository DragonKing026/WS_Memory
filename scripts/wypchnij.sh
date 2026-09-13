#!/usr/bin/env bash
# Wypchnij zmiany dopiero wtedy, gdy przejdą lokalnie — i poczekaj na CI.
#
# Powód: pchanie i przechodzenie do następnej rzeczy oznacza, że o czerwonym
# przebiegu dowiaduje się ktoś inny, kilka commitów później. Ten skrypt najpierw
# uruchamia u siebie **to samo**, co „Szybkie sprawdzenie” w CI, a po wypchnięciu
# czeka na wynik i pokazuje ogon logu tego kroku, który padł.
#
#   ./scripts/wypchnij.sh              # sprawdź, wypchnij, poczekaj
#   ./scripts/wypchnij.sh --tylko-lokalnie   # sam sprawdź, nie pchaj
#   ./scripts/wypchnij.sh --bez-czekania     # sprawdź i wypchnij, nie czekaj
#
# Backend i frontend biegną w kontenerach, bo tam jest środowisko: host nie ma
# ani PHP, ani pnpm, a różnica między „u mnie działa” a „na CI działa” to
# dokładnie ta, którą ten skrypt ma usuwać.

set -uo pipefail

KORZEN="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${KORZEN}"

TYLKO_LOKALNIE=0
BEZ_CZEKANIA=0
for arg in "$@"; do
  case "${arg}" in
    --tylko-lokalnie) TYLKO_LOKALNIE=1 ;;
    --bez-czekania) BEZ_CZEKANIA=1 ;;
    -h|--help) sed -n '2,20p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "Nieznany argument: ${arg}" >&2; exit 2 ;;
  esac
done

if [ -t 1 ]; then
  ZIELONY=$'\e[32m'; CZERWONY=$'\e[31m'; SZARY=$'\e[90m'; KONIEC=$'\e[0m'
else
  ZIELONY=''; CZERWONY=''; SZARY=''; KONIEC=''
fi

PORAZKI=0
LOGI="$(mktemp -d)"
trap 'rm -rf "${LOGI}"' EXIT

# Uruchamia jedno sprawdzenie, chowając wyjście — pokazujemy je tylko, gdy padnie.
# Cisza przy powodzeniu jest celowa: dwadzieścia linii „OK” sprawia, że jedna
# linia „PADŁO” ginie.
sprawdz() {
  local nazwa="$1"; shift
  local plik="${LOGI}/$(echo "${nazwa}" | tr -c 'a-zA-Z0-9' '_').log"

  # Dopełnienie liczone w znakach, nie bajtach: „ł" i „ń" zajmują po dwa bajty,
  # więc %-34s rozjeżdża kolumnę dokładnie na polskich nazwach.
  local dlugosc
  dlugosc=$(printf '%s' "${nazwa}" | wc -m)
  printf '  %s%*s' "${nazwa}" $((34 - dlugosc)) ''

  if "$@" >"${plik}" 2>&1; then
    printf '%sOK%s\n' "${ZIELONY}" "${KONIEC}"
  else
    printf '%sPADŁO%s\n' "${CZERWONY}" "${KONIEC}"
    sed 's/^/      /' "${plik}" | tail -25
    PORAZKI=$((PORAZKI + 1))
  fi
}

echo "Sprawdzenia lokalne (to samo, co „Szybkie sprawdzenie” w CI):"

# Wyszukiwanie i sprawdzanie w środku kontenera: ścieżki hosta („backend/src")
# nie istnieją po tamtej stronie, gdzie katalog jest zamontowany jako /app.
sprawdz "składnia PHP" docker compose exec -T backend sh -c \
  "find src tests migrations -name '*.php' -print0 | xargs -0 -n1 -P4 php -l >/dev/null"
sprawdz "PHPUnit" docker compose exec -T backend php vendor/bin/phpunit
sprawdz "PHPStan" docker compose exec -T backend php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress
sprawdz "składnia konfiguracji Symfony" docker compose exec -T backend php bin/console lint:yaml config --parse-tags
sprawdz "typy frontendu" docker compose exec -T frontend pnpm typecheck
sprawdz "testy frontendu" docker compose exec -T frontend pnpm test
sprawdz "budowanie frontendu" docker compose exec -T frontend pnpm build
sprawdz "spójność dokumentacji" ./scripts/sprawdz-dokumentacje.py
sprawdz "rozliczenia zadań" ./scripts/sprawdz-zadania.py
sprawdz "składnia YAML workflowów" python3 -c "
import pathlib, sys, yaml
for p in sorted(pathlib.Path('.github/workflows').glob('*.yml')):
    yaml.safe_load(p.read_text(encoding='utf-8'))
"
# Nie ma tego w CI, bo CI nie używa compose do uruchomienia stosu — ale zepsuty
# plik compose raz już poszedł na main i nikt tego nie zauważył, bo polecenia
# miały wyciszone błędy.
sprawdz "składnia docker compose" docker compose config --quiet

if [ "${PORAZKI}" -gt 0 ]; then
  printf '\n%s%d sprawdzeń padło — nie pcham.%s\n' "${CZERWONY}" "${PORAZKI}" "${KONIEC}"
  exit 1
fi

printf '\n%sWszystko przechodzi lokalnie.%s\n' "${ZIELONY}" "${KONIEC}"
[ "${TYLKO_LOKALNIE}" -eq 1 ] && exit 0

if [ -z "$(git log '@{upstream}..HEAD' --oneline 2>/dev/null)" ]; then
  echo "Nie ma czego wypchnąć."
  exit 0
fi

echo
git push || exit 1
SHA="$(git rev-parse HEAD)"
[ "${BEZ_CZEKANIA}" -eq 1 ] && exit 0

printf '\nCzekam na CI dla %s…\n' "${SHA:0:7}"

# GitHub potrzebuje chwili, zanim przebiegi w ogóle się pojawią; pytanie od razu
# zwróciłoby pustą listę, którą łatwo wziąć za „wszystko gotowe”.
sleep 20

for _ in $(seq 1 120); do
  STAN="$(gh run list --limit 20 --json headSha,status,conclusion,name \
    --jq "[.[] | select(.headSha == \"${SHA}\")]" 2>/dev/null)" || STAN='[]'

  W_TOKU="$(echo "${STAN}" | python3 -c 'import json,sys; print(sum(1 for r in json.load(sys.stdin) if r["status"] != "completed"))')"
  LACZNIE="$(echo "${STAN}" | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))')"

  if [ "${LACZNIE}" -gt 0 ] && [ "${W_TOKU}" -eq 0 ]; then
    break
  fi

  printf '%s  %s przebiegów, %s w toku…%s\r' "${SZARY}" "${LACZNIE}" "${W_TOKU}" "${KONIEC}"
  sleep 15
done

echo
# Bez ucieczek w f-stringu: kod Pythona jedzie w pojedynczych cudzysłowach basha,
# więc „\"" zostałoby wzięte dosłownie i wywróciło parser.
echo "${STAN}" | python3 -c '
import json, sys
przebiegi = json.load(sys.stdin)
dobre = ("success", "neutral", "skipped")
for r in przebiegi:
    stan = r["conclusion"] or r["status"]
    print("  " + stan.ljust(12) + " " + r["name"])
sys.exit(1 if any(r["conclusion"] not in dobre for r in przebiegi) else 0)
'
WYNIK=$?

if [ "${WYNIK}" -ne 0 ]; then
  # Najpierw pytamy, czy to w ogóle nasza wina. Trzy razy jednego dnia
  # (2026-09-13) czerwony przebieg okazał się awarią GitHuba — raz push do wiki
  # z błędem 500, raz analiza przyrostowa CodeQL, raz wysyłka wyników padająca
  # we wszystkich trzech językach naraz podczas krytycznej awarii Actions.
  # Za każdym razem ustalenie tego zajmowało kilka minut grzebania w logach,
  # w których przyczyny nie ma.
  STATUS="$(curl -sf --max-time 10 https://www.githubstatus.com/api/v2/summary.json 2>/dev/null)" || STATUS=''
  if [ -n "${STATUS}" ]; then
    echo "${STATUS}" | python3 -c '
import json, sys
dane = json.load(sys.stdin)
chore = [k["name"] for k in dane.get("components", []) if k.get("status") != "operational"]
awarie = [i["name"] for i in dane.get("incidents", []) if i.get("status") != "resolved"]
if chore or awarie:
    print("\n  UWAGA: GitHub ma teraz problemy — sprawdź, czy to nie to.")
    for n in chore:
        print(f"    niesprawne: {n}")
    for n in awarie:
        print(f"    awaria: {n}")
' || true
  fi

  printf '\n%sCoś padło. Ogon logu:%s\n' "${CZERWONY}" "${KONIEC}"
  for id in $(gh run list --limit 20 --json headSha,databaseId,conclusion \
      --jq ".[] | select(.headSha == \"${SHA}\" and .conclusion != \"success\" and .conclusion != \"neutral\" and .conclusion != \"skipped\") | .databaseId"); do
    gh run view "${id}" --log-failed 2>/dev/null | tail -20 | sed 's/^/    /'
    echo
  done
  exit 1
fi

printf '%sCI zielone.%s\n' "${ZIELONY}" "${KONIEC}"
