#!/usr/bin/env bash
# Wypchnij zmiany gałęzią zadania i pull requestem — dopiero gdy przejdą lokalnie.
#
# Powód: pchanie i przechodzenie do następnej rzeczy oznacza, że o czerwonym
# przebiegu dowiaduje się ktoś inny, kilka commitów później. Ten skrypt najpierw
# uruchamia u siebie **to samo**, co „Szybkie sprawdzenie” w CI, a po wypchnięciu
# czeka na wynik i pokazuje ogon logu tego kroku, który padł.
#
# Powód zmiany modelu pracy: poprzednia wersja pchała prosto na `main` i
# przechodziła przez ruleset „Ochrona gałęzi głównej” rolą administratora —
# w wyjściu zostawał ślad „Bypassed rule violations for refs/heads/main”.
# Reguła obchodzona przy każdym commicie nie chroni niczego. Teraz jedno
# zadanie to jedna gałąź, jeden pull request i jeden merge, a wymagane checki
# przechodzą tak samo jak każdemu innemu. Skrypt nigdzie nie używa `--admin`.
#
#   ./scripts/wypchnij.sh                     # sprawdź, wypchnij, PR, poczekaj, scal
#   ./scripts/wypchnij.sh todo-017-cos-tam    # to samo, z narzuconą nazwą gałęzi
#   ./scripts/wypchnij.sh --tylko-lokalnie    # sam sprawdź, nie pchaj
#   ./scripts/wypchnij.sh --bez-czekania      # sprawdź, wypchnij, otwórz PR i wyjdź
#
# Gałęzie nazywamy jak zadania z katalogu TODO (`todo-015-aktualizacja-mempalace`),
# a dla zmian bez zadania — krótkim slugiem z tematu commita.
#
# Backend i frontend biegną w kontenerach, bo tam jest środowisko: host nie ma
# ani PHP, ani pnpm, a różnica między „u mnie działa” a „na CI działa” to
# dokładnie ta, którą ten skrypt ma usuwać.

set -euo pipefail

KORZEN="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# shellcheck source=scripts/wspolne/projekt.sh
. "${KORZEN}/scripts/wspolne/projekt.sh"
cd "${KORZEN}"

TYLKO_LOKALNIE=0
BEZ_CZEKANIA=0
GALAZ_Z_ARGUMENTU=''
for arg in "$@"; do
  case "${arg}" in
    --tylko-lokalnie) TYLKO_LOKALNIE=1 ;;
    --bez-czekania) BEZ_CZEKANIA=1 ;;
    -h|--help) sed -n '2,26p' "${BASH_SOURCE[0]}"; exit 0 ;;
    -*) echo "Nieznany argument: ${arg}" >&2; exit 2 ;;
    *)
      # Argument bez myślnika to nazwa gałęzi. Dwie nazwy znaczą, że ktoś się
      # pomylił — a zgadywanie, którą miał na myśli, kończy się gałęzią o nazwie
      # nie do znalezienia.
      if [ -n "${GALAZ_Z_ARGUMENTU}" ]; then
        echo "Podaj najwyżej jedną nazwę gałęzi (druga to: ${arg})." >&2
        exit 2
      fi
      GALAZ_Z_ARGUMENTU="${arg}"
      ;;
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

  # Dopełnienie liczone w znakach, nie bajtach: „ł” i „ń” zajmują po dwa bajty,
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

# Nazwa gałęzi z tematu commita. Musi przejść przez `git`, przez adres pull
# requesta i przez ludzkie oczy, więc: małe litery, bez polskich znaków, bez
# niczego, co wymagałoby cytowania w powłoce.
zrob_slug() {
  local tekst="$1"

  # Typ commita („fix: ”, „ci(wiki)!: ”) nie niesie w nazwie gałęzi żadnej
  # informacji — gałąź i tak dotyczy jednego zadania.
  tekst="$(printf '%s' "${tekst}" | sed -E 's/^[a-z]+(\([^)]*\))?!?: *//')"

  # Podmiana znak po znaku, każdy osobnym wyrażeniem — nie klasą `[ąĄ]` ani
  # `iconv //TRANSLIT`. Klasa w locale C rozpada się na bajty (wszystkie polskie
  # litery zaczynają się od 0xC4/0xC5, więc jedna klasa psułaby pozostałe),
  # a iconv zależy od locale i na obcym systemie zwraca „?”.
  tekst="$(printf '%s' "${tekst}" | sed \
    -e 's/ą/a/g' -e 's/Ą/a/g' -e 's/ć/c/g' -e 's/Ć/c/g' \
    -e 's/ę/e/g' -e 's/Ę/e/g' -e 's/ł/l/g' -e 's/Ł/l/g' \
    -e 's/ń/n/g' -e 's/Ń/n/g' -e 's/ó/o/g' -e 's/Ó/o/g' \
    -e 's/ś/s/g' -e 's/Ś/s/g' -e 's/ź/z/g' -e 's/Ź/z/g' \
    -e 's/ż/z/g' -e 's/Ż/z/g')"

  # `cut`, a nie `head`: `head` zamyka potok i zabija git-a SIGPIPE, co przy
  # `pipefail` wywraca cały skrypt. Ta pułapka już raz tu siedziała.
  local slug
  slug="$(printf '%s' "${tekst}" \
    | tr 'A-Z' 'a-z' \
    | tr -c 'a-z0-9' '-' \
    | sed -e 's/--*/-/g' -e 's/^-//' -e 's/-$//' \
    | cut -c1-50 \
    | sed 's/-$//')"

  # Temat złożony wyłącznie ze znaków, które wypadły — lepiej cokolwiek niż
  # pusta nazwa, na której `git branch` wysypie się bez wyjaśnienia.
  [ -n "${slug}" ] || slug='zmiana'
  printf '%s' "${slug}"
}

# ─── Krok 1: czyste drzewo robocze ───────────────────────────────────────────
# Sprawdzenia biegną na plikach, a wypychamy commity. Na brudnym drzewie zielone
# światło dotyczyłoby kodu, którego nikt nie zobaczy w pull requeście — a do tego
# `git reset --hard` niżej zjadłby te zmiany bez pytania.
if [ -n "$(git status --porcelain)" ]; then
  printf '%sDrzewo robocze nie jest czyste — nie ruszam.%s Zacommituj albo schowaj:\n' \
    "${CZERWONY}" "${KONIEC}"
  git status --short | sed 's/^/  /'
  exit 1
fi

GALAZ="$(git rev-parse --abbrev-ref HEAD)"

# Od świeżości origin/main zależy i „czy jest co pchać”, i lista commitów
# w opisie pull requesta. Nieświeży ref pokazałby commity scalone dawno temu.
#
# Porażka nie przerywa: sprawdzenia lokalne (a zwłaszcza --tylko-lokalnie) mają
# działać w pociągu bez sieci. Na starym refie policzymy najwyżej o kilka
# commitów za dużo, a push i tak wcześniej czy później powie prawdę.
if ! git fetch --quiet origin main; then
  printf '%sNie dociągnąłem origin/main — liczę na tym, co lokalnie.%s\n' \
    "${SZARY}" "${KONIEC}"
fi

# ─── Kroki 2 i 3: zejście z main-a na gałąź zadania ──────────────────────────
if [ "${GALAZ}" = "main" ]; then
  PRZED_ORIGINEM="$(git rev-list --count origin/main..HEAD)"

  if [ "${PRZED_ORIGINEM}" -gt 0 ] && [ "${TYLKO_LOKALNIE}" -eq 0 ]; then
    NOWA="${GALAZ_Z_ARGUMENTU}"
    if [ -z "${NOWA}" ]; then
      NOWA="$(zrob_slug "$(git log -1 --format=%s)")"
    fi

    if [ "${NOWA}" = "main" ]; then
      echo "Gałąź zadania nie może nazywać się main." >&2
      exit 2
    fi
    if git show-ref --quiet --verify "refs/heads/${NOWA}"; then
      echo "Gałąź ${NOWA} już istnieje — podaj inną nazwę argumentem." >&2
      exit 2
    fi

    # Kolejność ma znaczenie: najpierw gałąź zapamiętuje HEAD, dopiero potem main
    # się cofa. `reset --hard` jest tu bezpieczny, bo te commity nigdy nie były
    # wypchnięte (origin/main ich nie zna) i po utworzeniu gałęzi nie są niczyimi
    # sierotami.
    git branch "${NOWA}"
    git reset --quiet --hard origin/main
    git switch --quiet "${NOWA}"
    GALAZ="${NOWA}"

    # Głośno, bo skrypt właśnie przepisał komuś HEAD. Cicha zmiana gałęzi to
    # najlepszy sposób, żeby następny commit poszedł nie tam, gdzie autor myśli.
    printf '\n%s─── ZDJĄŁEM TWOJE COMMITY Z MAIN-A ───%s\n' "${ZIELONY}" "${KONIEC}"
    printf '  gałąź zadania:   %s\n' "${GALAZ}"
    printf '  przeniesione:    %s commit(y) z main\n' "${PRZED_ORIGINEM}"
    printf '  lokalny main:    cofnięty do origin/main (nic wypchniętego nie ucierpiało)\n'
    printf '  jesteś teraz na: %s\n\n' "${GALAZ}"

  elif [ "${PRZED_ORIGINEM}" -gt 0 ]; then
    # --tylko-lokalnie niczego nie wypycha, więc nie ma po co przestawiać gałęzi.
    # Kto poprosił o same sprawdzenia, nie oczekuje, że skrypt przepisze mu HEAD.
    printf '%sJesteś na main z %s lokalnymi commitami — gałąź zadania powstanie przy prawdziwym wypchnięciu.%s\n\n' \
      "${SZARY}" "${PRZED_ORIGINEM}" "${KONIEC}"

  elif [ "${TYLKO_LOKALNIE}" -eq 0 ]; then
    echo "Jesteś na main i nie ma czego wypchnąć."
    exit 0
  fi

elif [ -n "${GALAZ_Z_ARGUMENTU}" ] && [ "${GALAZ_Z_ARGUMENTU}" != "${GALAZ}" ]; then
  # Przemianowanie gałęzi w połowie zadania rozjechałoby ją z pull requestem,
  # który już na niej wisi — więc tylko mówimy, że argument poszedł do kosza.
  printf '%sJesteś już na gałęzi %s — pomijam podaną nazwę %s.%s\n\n' \
    "${SZARY}" "${GALAZ}" "${GALAZ_Z_ARGUMENTU}" "${KONIEC}"
fi

# Sprawdzenia frontendu nie mogą zależeć od tego, w jakim trybie stoi instancja.
#
# Przy `FRONTEND_TARGET=prod` kontener frontendu to nginx ze statycznym `dist/`
# — nie ma w nim ani Node'a, ani pnpm, więc trzy sprawdzenia padały na
# „executable file not found in $PATH" i skrypt odmawiał wypchnięcia. Padały
# przy tym z powodu, który nie ma nic wspólnego z jakością zmiany, a to jest
# najgorszy rodzaj czerwonego światła: uczy ignorowania czerwonych świateł.
#
# Gdy pnpm jest na miejscu, korzystamy z działającego kontenera (szybciej,
# `node_modules` już rozpakowane). Gdy go nie ma — jednorazowy kontener
# z obrazu dev, z tym samym wolumenem `node_modules`. Nie jest to pominięcie
# sprawdzenia: to samo polecenie, inne miejsce uruchomienia.
w_frontendzie() {
  if docker compose exec -T frontend sh -c 'command -v pnpm' >/dev/null 2>&1; then
    docker compose exec -T frontend pnpm "$@"
    return
  fi

  local projekt
  projekt="$(ustal_projekt_compose "${KORZEN}" 2>/dev/null || echo ws-memory)"

  # Nazwa OBRAZU jest w `docker-compose.yml` wpisana na stałe (`ws-memory/frontend`)
  # i nie zależy od nazwy projektu — w przeciwieństwie do nazwy WOLUMENU, którą
  # Compose prefiksuje projektem. Pomylenie tych dwóch daje błąd „no such image"
  # na instancji o innej nazwie projektu.
  if ! docker image inspect ws-memory/frontend:dev >/dev/null 2>&1; then
    echo "Brak obrazu ws-memory/frontend:dev — zbuduj go: docker compose build frontend" >&2
    return 1
  fi

  docker run --rm --entrypoint pnpm \
    -v "${KORZEN}/frontend:/app" \
    -v "${projekt}_frontend_node_modules:/app/node_modules" \
    -w /app ws-memory/frontend:dev "$@"
}

# ─── Krok 4: sprawdzenia lokalne ─────────────────────────────────────────────
echo "Sprawdzenia lokalne (to samo, co „Szybkie sprawdzenie” w CI):"

# Wyszukiwanie i sprawdzanie w środku kontenera: ścieżki hosta („backend/src”)
# nie istnieją po tamtej stronie, gdzie katalog jest zamontowany jako /app.
sprawdz "składnia PHP" docker compose exec -T backend sh -c \
  "find src tests migrations -name '*.php' -print0 | xargs -0 -n1 -P4 php -l >/dev/null"
sprawdz "PHPUnit" docker compose exec -T backend php vendor/bin/phpunit
sprawdz "PHPStan" docker compose exec -T backend php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress
sprawdz "składnia konfiguracji Symfony" docker compose exec -T backend php bin/console lint:yaml config --parse-tags
sprawdz "typy frontendu" w_frontendzie typecheck
sprawdz "testy frontendu" w_frontendzie test
sprawdz "budowanie frontendu" w_frontendzie build
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
if [ "${TYLKO_LOKALNIE}" -eq 1 ]; then
  exit 0
fi

# Bezpiecznik, nie uprzejmość: do tego miejsca nie ma prawa dojść nikt stojący
# na main-ie, a gdyby jednak doszedł, `git push` obszedłby ruleset dokładnie tak,
# jak ten skrypt miał przestać robić.
if [ "${GALAZ}" = "main" ]; then
  echo "Coś tu nie gra: wypychanie na main. Przerywam." >&2
  exit 1
fi

if [ "$(git rev-list --count origin/main..HEAD)" -eq 0 ]; then
  echo "Gałąź ${GALAZ} nie ma nic ponad origin/main — nie ma z czego robić pull requesta."
  exit 0
fi

# ─── Krok 5: wypchnięcie gałęzi ──────────────────────────────────────────────
echo
printf 'Wypycham gałąź %s…\n' "${GALAZ}"
if ! git push -u origin "${GALAZ}"; then
  printf '%sPush odrzucony.%s Jeśli historia gałęzi została świadomie przepisana:\n  git push --force-with-lease origin %s\n' \
    "${CZERWONY}" "${KONIEC}" "${GALAZ}"
  exit 1
fi

# ─── Krok 6: pull request ────────────────────────────────────────────────────
# Pytamy o otwarte PR-y tej gałęzi, a nie `gh pr view`: to drugie potrafi
# wyciągnąć PR zamknięty albo już scalony i skrypt czekałby na checki trupa.
NUMER="$(gh pr list --head "${GALAZ}" --state open --json number --jq '.[0].number // empty')"

if [ -z "${NUMER}" ]; then
  # Tytuł z **pierwszego** commita gałęzi, nie z ostatniego: pierwszy mówi, po co
  # ta gałąź powstała, ostatni zwykle mówi „popraw literówkę”.
  # `tail`, nie `head`, bo `head` urwałby potok git-owi w środku zdania i przy
  # `pipefail` zabił skrypt.
  TYTUL="$(git log --format='%s' "origin/main..${GALAZ}" | tail -1)"
  TRESC="$(git log --reverse --format='- %s' "origin/main..${GALAZ}")"

  ADRES="$(gh pr create --base main --head "${GALAZ}" --title "${TYTUL}" --body "${TRESC}")"
  printf 'Pull request otwarty: %s\n' "${ADRES}"
  NUMER="$(gh pr list --head "${GALAZ}" --state open --json number --jq '.[0].number // empty')"
else
  printf 'Pull request #%s już istnieje — dopchnąłem do niego commity.\n' "${NUMER}"
fi

if [ -z "${NUMER}" ]; then
  echo "Nie udało się ustalić numeru pull requesta — dalej bez niego nie pójdę." >&2
  exit 1
fi

if [ "${BEZ_CZEKANIA}" -eq 1 ]; then
  printf 'Nie czekam na checki (--bez-czekania). Scalenie zostaje na Tobie:\n  gh pr merge %s --merge --delete-branch\n' "${NUMER}"
  exit 0
fi

# ─── Krok 7: czekanie na WSZYSTKIE checki pull requesta ──────────────────────
# Świadomie bez listy nazw. Wymagane checki w rulesecie się zmieniają (teraz
# zbiera je jedna bramka „Wynik sprawdzenia”), a skrypt pilnujący sztywnej listy
# po każdej takiej zmianie milcząco przepuszcza to, czego już nie zna.
printf '\nCzekam na checki pull requesta #%s…\n' "${NUMER}"

# `gh pr checks` na pustej liście kończy się błędem, nie zerem — a checki
# pojawiają się dopiero kilkanaście sekund po otwarciu PR-a. Bez tej pętli
# normalny start wyglądałby jak awaria.
CHECKI='[]'
for _ in $(seq 1 30); do
  CHECKI="$(gh pr checks "${NUMER}" --json name,state,bucket,link 2>/dev/null || echo '[]')"
  if [ "$(echo "${CHECKI}" | jq 'length')" -gt 0 ]; then
    break
  fi
  printf '%s  czekam, aż GitHub zarejestruje checki…%s\r' "${SZARY}" "${KONIEC}"
  sleep 10
done

if [ "$(echo "${CHECKI}" | jq 'length')" -eq 0 ]; then
  printf '\n%sPo pięciu minutach pull request #%s nie ma ani jednego checka.%s\n' \
    "${CZERWONY}" "${NUMER}" "${KONIEC}"
  echo "Nie scalam czegoś, czego nikt nie sprawdził. Zajrzyj: gh pr checks ${NUMER}"
  exit 1
fi

for _ in $(seq 1 120); do
  CHECKI="$(gh pr checks "${NUMER}" --json name,state,bucket,link 2>/dev/null || echo '[]')"
  LACZNIE="$(echo "${CHECKI}" | jq 'length')"
  W_TOKU="$(echo "${CHECKI}" | jq '[.[] | select(.bucket == "pending")] | length')"

  if [ "${LACZNIE}" -gt 0 ] && [ "${W_TOKU}" -eq 0 ]; then
    break
  fi

  printf '%s  %s checków, %s w toku…%s\r' "${SZARY}" "${LACZNIE}" "${W_TOKU}" "${KONIEC}"
  sleep 15
done

echo
# jq, a nie python3 -c: kod Pythona jedzie w pojedynczych cudzysłowach basha,
# więc ucieczka „\"” zostałaby wzięta dosłownie i wywróciła parser. Ta pułapka
# ugryzła ten plik już raz.
echo "${CHECKI}" | jq -r '.[] | "  " + ((.state | ascii_downcase) + "             ")[0:14] + .name'

# Pominięty check to „ta zmiana tego nie dotyczy”, a nie „nie sprawdziliśmy” —
# zakres rozstrzyga workflow, nie ten skrypt. Wszystko poza pass i skipping
# (fail, cancel, a po przekroczeniu limitu czekania także pending) blokuje merge.
ZLE="$(echo "${CHECKI}" | jq '[.[] | select(.bucket != "pass" and .bucket != "skipping")] | length')"

# ─── Krok 10: czerwono ───────────────────────────────────────────────────────
if [ "${ZLE}" -gt 0 ]; then
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

  printf '\n%sNie scalam. Nie przeszło:%s\n' "${CZERWONY}" "${KONIEC}"
  echo "${CHECKI}" | jq -r '.[]
    | select(.bucket != "pass" and .bucket != "skipping")
    | "  " + .name + " (" + .state + ")\n    " + (.link // "bez odnośnika")'

  printf '\n%sOgon logu:%s\n' "${CZERWONY}" "${KONIEC}"
  # Przebiegi pull requesta wiszą na SHA czoła gałęzi, nie na commicie scalenia,
  # więc HEAD wystarczy do znalezienia ich logów.
  SHA="$(git rev-parse HEAD)"
  for id in $(gh run list --limit 20 --json headSha,databaseId,conclusion \
      --jq ".[] | select(.headSha == \"${SHA}\" and .conclusion != \"success\" and .conclusion != \"neutral\" and .conclusion != \"skipped\") | .databaseId"); do
    gh run view "${id}" --log-failed 2>/dev/null | tail -20 | sed 's/^/    /'
    echo
  done
  exit 1
fi

# ─── Krok 8: merge ───────────────────────────────────────────────────────────
printf '%sChecki zielone.%s Scalam #%s…\n' "${ZIELONY}" "${KONIEC}" "${NUMER}"

# Merge commit, nie squash: właściciel commituje po każdym zamkniętym kroku
# i te commity **są** historią pracy, nie szumem do sklejenia w jedno.
# Bez `--admin` — obchodzenie rulesetu to dokładnie to, z czym ten skrypt zerwał.
if ! gh pr merge "${NUMER}" --merge --delete-branch; then
  printf '%sMerge odrzucony.%s Najczęstsza przyczyna: ruleset wymaga checka, który dla tej zmiany został pominięty, a pominięty check nigdy nie zgłasza się jako zielony.\n  gh pr view %s --web\n' \
    "${CZERWONY}" "${KONIEC}" "${NUMER}"
  exit 1
fi

# ─── Krok 9: powrót na main ──────────────────────────────────────────────────
# gh przy --delete-branch zwykle samo przenosi się na gałąź domyślną, ale
# „zwykle” nie wystarcza, gdy następny commit ma trafić w dobre miejsce.
git switch main
git pull --ff-only

printf '\n%sScalone.%s main: %s\n' "${ZIELONY}" "${KONIEC}" "$(git log -1 --oneline)"
