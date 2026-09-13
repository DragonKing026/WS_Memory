#!/usr/bin/env bash
#
# Agent aktualizacji MemPalace — uruchamiany cyklicznie NA HOŚCIE (TODO-015).
#
# Dlaczego na hoście, a nie w kontenerze: aktualizacja pałaca to przebudowa
# obrazu i wymiana kontenera, czyli dostęp do Dockera. Kontener z gniazdem
# Dockera ma władzę równoważną rootowi na maszynie, a backend obsługuje ruch
# z sieci — więc dowolne RCE w Symfony albo przejęcie konta administratora
# kończyłoby się przejęciem hosta. Dlatego backend niczego nie wykonuje: tylko
# ZAPISUJE ZLECENIE do bazy, a ten skrypt je podejmuje. Włamanie do aplikacji
# pozwala wtedy co najwyżej zlecić aktualizację do wersji, która istnieje
# na PyPI.
#
# Z aplikacją rozmawiamy przez `docker compose exec backend php bin/console`,
# a nie po HTTP — nie ma wtedy endpointu do chronienia ani drugiego mechanizmu
# uwierzytelniania obok sesji użytkownika.
#
# Przebieg jednego uruchomienia:
#   puls → zlecenie → walidacja wersji → KOPIA ZAPASOWA → .env → przebudowa
#   → podniesienie → test semantyki → wynik (albo wycofanie i wynik)
#
# Bez udanej kopii zapasowej nie robimy nic: aktualizacja pałaca dotyka
# wektorów i bywa nieodwracalna. Test semantyki po aktualizacji jest
# obowiązkowy, bo zepsuta trafność wyszukiwania jest CICHA (D-003) — usługa
# odpowiada 200 na /healthz, tylko przestaje znajdować.
#
# Użycie:
#   ./scripts/aktualizator.sh                  # normalny przebieg (z timera systemd)
#   ./scripts/aktualizator.sh --na-sucho       # cała ścieżka decyzyjna, zero zmian
#   ./scripts/aktualizator.sh --na-sucho --wersja=3.9.0
#                                              # jw., z udawanym zleceniem
#
# Kody wyjścia: 0 = zrobione albo nie było nic do zrobienia, 1 = porażka
# (widoczna w `systemctl status` i w dzienniku zlecenia), 2 = zły argument.
#
# Instalacja jako usługa: docker/systemd/README.md

set -euo pipefail

KORZEN="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${KORZEN}"

# ─── Argumenty ───────────────────────────────────────────────────────────────

NA_SUCHO=0
WERSJA_Z_ARGUMENTU=''
for arg in "$@"; do
  case "${arg}" in
    --na-sucho) NA_SUCHO=1 ;;
    --wersja=*) WERSJA_Z_ARGUMENTU="${arg#--wersja=}" ;;
    -h|--help) sed -n '3,36p' "${BASH_SOURCE[0]}" | sed 's/^#\{1\} \{0,1\}//'; exit 0 ;;
    *) echo "Nieznany argument: ${arg}" >&2; exit 2 ;;
  esac
done

# Wersja z wiersza poleceń ma sens tylko przy udawanym zleceniu. W normalnym
# przebiegu cel aktualizacji pochodzi WYŁĄCZNIE z bazy — inaczej powstałaby
# druga, nieudokumentowana droga do `pip install mempalace==...`.
if [ "${NA_SUCHO}" -eq 0 ] && [ -n "${WERSJA_Z_ARGUMENTU}" ]; then
  echo "--wersja= działa tylko razem z --na-sucho: w normalnym przebiegu wersję podaje zlecenie z bazy." >&2
  exit 2
fi

# ─── Stałe i stan ────────────────────────────────────────────────────────────

# Ten sam wzorzec obowiązuje w backendzie przy zapisie zlecenia. Walidacja po
# obu stronach nie wynika z nieufności do backendu — jedna warstwa walidacji to
# zero warstw w dniu, w którym ta jedna ma błąd. A tu wartość trafia do
# polecenia wykonywanego na hoście.
WZORZEC_WERSJI='^[0-9]+\.[0-9]+\.[0-9]+$'
# Identyfikator też jedzie do wiersza poleceń (`ws:updater:finish <id>`), więc
# przechodzi przez sito na tych samych zasadach.
WZORZEC_ID='^[A-Za-z0-9_-]{1,64}$'

KATALOG_KOPII="${KORZEN}/kopie"
SKRYPT_SEMANTYKI="${KORZEN}/test/semantyka.sh"
# Ile czekać na zdrowy kontener po podniesieniu. Pałac ma start_period 120 s
# i trzydzieści prób co 10 s, więc pięć minut to minimum, a nie zapas.
LIMIT_CZEKANIA=600

ID_ZLECENIA=''
NAZWA_ZALEZNOSCI=''
WERSJA_DOCELOWA=''
WERSJA_OBECNA=''
PLIK_KOPII=''
# Podnoszone dopiero po podmianie .env: przed nią nie ma czego wycofywać.
WYMAGA_WYCOFANIA=0
OSTRZEZENIA=0

if [ -t 1 ]; then
  ZIELONY=$'\e[32m'; CZERWONY=$'\e[31m'; ZOLTY=$'\e[33m'; KONIEC=$'\e[0m'
else
  ZIELONY=''; CZERWONY=''; ZOLTY=''; KONIEC=''
fi

znacznik_czasu() { date '+%Y%m%d-%H%M%S'; }

# ─── Dziennik ────────────────────────────────────────────────────────────────
#
# Cały przebieg zbieramy do pliku, bo to on jedzie na standardowe wejście
# `ws:updater:finish` i staje się treścią, którą panel pokazuje administratorowi.
# Jednocześnie wszystko leci na standardowe wyjście, żeby `journalctl` i ręczne
# uruchomienie pokazywały to samo.

DZIENNIK="$(mktemp "${TMPDIR:-/tmp}/ws-memory-aktualizator.XXXXXX.log")"
trap 'rm -f "${DZIENNIK}"' EXIT

zapisz() {
  local linia="[$(date '+%Y-%m-%d %H:%M:%S')] $*"
  printf '%s\n' "${linia}" >>"${DZIENNIK}"
  printf '%s\n' "${linia}"
}

ostrzez() {
  OSTRZEZENIA=$((OSTRZEZENIA + 1))
  zapisz "UWAGA: $*"
}

# Wykonuje polecenie, pokazuje i zapisuje jego wyjście. Używane do wszystkiego,
# czego wyjście jest tylko do czytania (przebudowa, podnoszenie, testy).
# `pipefail` sprawia, że kod wyjścia jest kodem polecenia, nie `tee`.
wykonaj() {
  zapisz "\$ $*"
  "$@" 2>&1 | sed 's/^/    /' | tee -a "${DZIENNIK}"
}

# To samo, ale pomijane na sucho — tu trafia wszystko, co ZMIENIA system.
wykonaj_zmiane() {
  if [ "${NA_SUCHO}" -eq 1 ]; then
    zapisz "(na sucho) pomijam: $*"
    return 0
  fi
  wykonaj "$@"
}

# ─── Zabezpieczenie przed równoległym uruchomieniem ──────────────────────────
#
# Timer tyka co minutę, a jeden przebieg z przebudową obrazu trwa dłużej.
# Nakładanie się przebiegów jest więc normalne, nie awarią — dlatego kod 0.
# Dwa agenty naraz podmieniałyby .env i przebudowywały ten sam obraz, co jest
# najprostszą drogą do systemu w stanie, którego nikt nie potrafi opisać.

# flock jest potrzebny zanim zacznie się cokolwiek innego, więc sprawdzamy go
# tutaj, a nie razem z pozostałymi narzędziami niżej — i bez maszynerii
# dziennika, bo nie ma jeszcze czego zapisywać.
command -v flock >/dev/null 2>&1 \
  || { echo "Brak narzędzia flock na hoście — bez blokady nie uruchamiam agenta." >&2; exit 1; }

mkdir -p "${KATALOG_KOPII}"
exec 9>"${KATALOG_KOPII}/.blokada"
if ! flock -n 9; then
  echo "Poprzednie uruchomienie agenta jeszcze trwa — kończę bez działania."
  exit 0
fi

# ─── Zgłaszanie wyniku i wycofanie ───────────────────────────────────────────

zglos_wynik() {
  local stan="$1"
  if [ "${NA_SUCHO}" -eq 1 ]; then
    zapisz "(na sucho) pomijam: ws:updater:finish ${ID_ZLECENIA} --status=${stan}"
    return 0
  fi
  # Dziennik idzie na standardowe wejście. Czytany jest w tym momencie, więc
  # linie dopisane później (już po zgłoszeniu) do panelu nie dotrą — i dobrze,
  # bo panel ma widzieć dokładnie to, co doprowadziło do wyniku.
  if docker compose exec -T backend php bin/console \
       ws:updater:finish "${ID_ZLECENIA}" --status="${stan}" \
       <"${DZIENNIK}" >/dev/null 2>>"${DZIENNIK}"; then
    zapisz "Wynik zgłoszony do aplikacji: ${stan}"
  else
    # Najgorszy przypadek: zrobiliśmy coś z systemem, a panel o tym nie wie.
    # Dziennik musi wtedy przeżyć skrypt, inaczej ślad ginie razem z /tmp.
    local zachowany="${KATALOG_KOPII}/dziennik-$(znacznik_czasu)-${stan}.log"
    cp "${DZIENNIK}" "${zachowany}" 2>/dev/null || true
    zapisz "NIE UDAŁO SIĘ zgłosić wyniku do aplikacji (${stan}). Panel tego nie pokaże — dziennik zostaje w ${zachowany}"
  fi
}

# Wycofanie cofa WERSJĘ OBRAZU, nie zawartość bazy. Poprzedni obraz jest
# w Dockerze pod starym tagiem (`ws-memory/mempalace:<wersja>`), więc przebudowa
# schodzi z pamięci podręcznej i trwa sekundy. Jeśli jednak nowa wersja pałaca
# zmieniła schemat `palace`, stary obraz może go nie zrozumieć — wtedy trzeba
# odtworzyć kopię zapasową ręcznie. Dlatego nie próbujemy odtwarzać jej
# automatycznie: przywracanie wektorów w tle, bez człowieka patrzącego na wynik,
# jest groźniejsze niż zatrzymanie się z jasnym komunikatem.
wycofaj() {
  zapisz "WYCOFANIE: wracam do MEMPALACE_VERSION=${WERSJA_OBECNA}"
  if ! ustaw_wersje_w_env "${WERSJA_OBECNA}"; then
    zapisz "NIE UDAŁO SIĘ przywrócić .env. Wpisz ręcznie MEMPALACE_VERSION=${WERSJA_OBECNA} i wykonaj: docker compose up -d --wait mempalace"
    return 0
  fi
  WYMAGA_WYCOFANIA=0
  if ! wykonaj docker compose build mempalace; then
    zapisz "Przebudowa poprzedniej wersji NIE POWIODŁA SIĘ — pałac może nie wstać. Sprawdź ręcznie: docker compose up -d --wait mempalace"
    return 0
  fi
  if ! wykonaj docker compose up -d --wait --wait-timeout "${LIMIT_CZEKANIA}" mempalace; then
    zapisz "Poprzednia wersja NIE WSTAŁA po wycofaniu. Pałac jest nieczynny — sprawdź: docker compose logs --tail=100 mempalace"
    return 0
  fi
  zapisz "Wycofano: działa ponownie MemPalace ${WERSJA_OBECNA}"
  if [ -n "${PLIK_KOPII}" ]; then
    zapisz "Jeśli pałac po wycofaniu zachowuje się dziwnie, schemat mógł zostać zmieniony przez nowszą wersję. Kopia z przed aktualizacji: ${PLIK_KOPII}"
    zapisz "Odtworzenie (świadomie ręczne): docker compose exec -T postgres sh -c 'PGPASSWORD=\"\$POSTGRES_PASSWORD\" pg_restore -U postgres -d \"\$POSTGRES_DB\" --clean --if-exists --schema=palace' < ${PLIK_KOPII}"
  fi
}

przerwij_porazka() {
  zapisz "PORAŻKA: $*"
  if [ "${WYMAGA_WYCOFANIA}" -eq 1 ]; then
    wycofaj
  fi
  if [ -n "${ID_ZLECENIA}" ]; then
    zglos_wynik failed
  else
    zapisz "Nie ma komu zgłosić porażki: zlecenie nie zostało podjęte."
  fi
  printf '%sPORAŻKA%s — szczegóły wyżej.\n' "${CZERWONY}" "${KONIEC}" >&2
  exit 1
}

# ─── Podmiana wersji w .env ──────────────────────────────────────────────────

ustaw_wersje_w_env() {
  local nowa="$1" tymczasowy linii_przed linii_po
  if [ "${NA_SUCHO}" -eq 1 ]; then
    zapisz "(na sucho) pomijam: MEMPALACE_VERSION=${nowa} w .env"
    return 0
  fi

  # awk przepisuje plik linia po linii i rusza dokładnie jedną. `sed -i`
  # zrobiłby to krócej, ale podmienia plik nowym i-węzłem, gubiąc właściciela
  # i uprawnienia — a w .env są hasła do bazy i token MCP. Plik tymczasowy
  # powstaje poza katalogiem projektu, żeby po awarii nie zostawał w repozytorium.
  tymczasowy="$(mktemp "${TMPDIR:-/tmp}/ws-memory-env.XXXXXX")"
  awk -v nowa="${nowa}" '
    /^MEMPALACE_VERSION=/ && podmieniono == 0 { print "MEMPALACE_VERSION=" nowa; podmieniono = 1; next }
    { print }
  ' .env >"${tymczasowy}"

  # Zanim nadpiszemy plik, od którego zależy start całego systemu: czy wynik ma
  # tyle samo linii i czy nowa wersja naprawdę w nim jest. Bez tego błąd w awk
  # zostawiłby okrojony .env, a compose przestałby wstawać z powodu
  # niezwiązanym z aktualizacją. `grep -c ''` liczy linie tak samo jak awk
  # liczy rekordy, także gdy plik nie kończy się znakiem nowej linii.
  linii_przed="$(grep -c '' .env)"
  linii_po="$(grep -c '' "${tymczasowy}")"
  if [ "${linii_przed}" != "${linii_po}" ] || ! grep -qx "MEMPALACE_VERSION=${nowa}" "${tymczasowy}"; then
    rm -f "${tymczasowy}"
    zapisz "Podmiana MEMPALACE_VERSION w .env nie dała oczekiwanego wyniku — plik pozostaje bez zmian."
    return 1
  fi

  # `cat >` zamiast `mv`: zachowuje i-węzeł, właściciela i uprawnienia .env.
  cat "${tymczasowy}" >.env
  rm -f "${tymczasowy}"
  zapisz "W .env ustawiono MEMPALACE_VERSION=${nowa}"
}

# ─── Krok 0: wymagania hosta ─────────────────────────────────────────────────
#
# Sprawdzamy przed pulsem, bo agent bez tych narzędzi nie wykona zlecenia,
# a zgłaszanie pulsu sugerowałoby panelowi, że wykona.

zapisz "── Agent aktualizacji MemPalace, katalog ${KORZEN}"
if [ "${NA_SUCHO}" -eq 1 ]; then
  zapisz "TRYB NA SUCHO: przechodzę całą ścieżkę decyzyjną, ale nie podmieniam .env, nie przebudowuję obrazu i nie zapisuję niczego w aplikacji."
fi

for narzedzie in docker jq awk; do
  command -v "${narzedzie}" >/dev/null 2>&1 \
    || przerwij_porazka "brak narzędzia ${narzedzie} na hoście — agent nie ma czym pracować"
done
docker compose version >/dev/null 2>&1 \
  || przerwij_porazka "docker compose nie odpowiada (brak wtyczki compose albo brak dostępu do gniazda Dockera) — agent musi działać jako użytkownik z dostępem do Dockera"
[ -f "${KORZEN}/.env" ] \
  || przerwij_porazka "brak .env w ${KORZEN} — bez niego nie ma czego podmieniać ani czym wstać"
[ -x "${SKRYPT_SEMANTYKI}" ] \
  || przerwij_porazka "brak wykonywalnego ${SKRYPT_SEMANTYKI} — bez testu semantyki aktualizacja byłaby nieweryfikowalna (D-003)"
touch "${KATALOG_KOPII}/.proba" 2>/dev/null \
  || przerwij_porazka "katalog ${KATALOG_KOPII} nie jest zapisywalny — nie da się zrobić kopii zapasowej"
rm -f "${KATALOG_KOPII}/.proba"

# Wersja działająca według .env. To ona jest celem wycofania — nie
# `fromVersion` ze zlecenia, bo zlecenie mówi, co aplikacja WIDZIAŁA, a .env
# mówi, z czego system NAPRAWDĘ wstaje.
WERSJA_OBECNA="$(awk -F= '/^MEMPALACE_VERSION=/ { print $2; exit }' .env | tr -d '[:space:]')"
# `awk ... exit` zamiast `grep | head`: `head` zamyka potok i zabija poprzednika
# SIGPIPE, co przy `pipefail` wywraca skrypt. Ta pułapka już raz tu siedziała.
[ -n "${WERSJA_OBECNA}" ] \
  || przerwij_porazka "w .env nie ma linii MEMPALACE_VERSION= — nie wiem, z czego system wstaje, więc nie wiem, do czego wycofać"
[[ "${WERSJA_OBECNA}" =~ ${WZORZEC_WERSJI} ]] \
  || przerwij_porazka "MEMPALACE_VERSION w .env („${WERSJA_OBECNA}”) nie jest wersją w formacie X.Y.Z — najpierw popraw plik ręcznie"
zapisz "Wersja działająca według .env: ${WERSJA_OBECNA}"

# ─── Krok 1: puls ────────────────────────────────────────────────────────────
#
# Puls jest jedynym dowodem dla panelu, że agent w ogóle istnieje. Bez niego
# panel MA pokazać „aktualizator niedostępny” zamiast przycisku, który nic nie
# robi — dlatego zgłaszamy go przy każdym przebiegu, także gdy nie ma zlecenia.

if [ "${NA_SUCHO}" -eq 1 ]; then
  zapisz "(na sucho) pomijam puls — ws:updater:heartbeat ZAPISUJE do bazy. Sprawdzam za to, czy polecenia agenta w ogóle istnieją:"
  if LISTA_POLECEN="$(docker compose exec -T backend php bin/console list --raw --no-ansi 2>/dev/null)"; then
    for polecenie in ws:updater:heartbeat ws:updater:claim ws:updater:finish; do
      if printf '%s\n' "${LISTA_POLECEN}" | grep -q "^${polecenie}\([[:space:]]\|$\)"; then
        zapisz "  jest: ${polecenie}"
      else
        ostrzez "backend nie zna polecenia ${polecenie} — dopóki go nie zna, panel nie zobaczy pulsu i nie przekaże zlecenia"
      fi
    done
  else
    ostrzez "nie udało się zapytać backendu o listę poleceń (kontener nie działa albo konsola pada) — agent nie miałby z kim rozmawiać"
  fi
else
  if ! wykonaj docker compose exec -T backend php bin/console ws:updater:heartbeat; then
    przerwij_porazka "puls nie doszedł do aplikacji — backend nie odpowiada, więc nie ma sensu podejmować zlecenia"
  fi
fi

# ─── Krok 2: zlecenie ────────────────────────────────────────────────────────
#
# `ws:updater:claim` wypisuje JSON jednego zlecenia albo nic. Nic znaczy: nie ma
# roboty — to najczęstszy przypadek, bo timer tyka co minutę, a aktualizacje są
# rzadkie. Kończymy wtedy kodem 0, żeby `systemctl status` nie świecił na
# czerwono przez cały rok.

if [ "${NA_SUCHO}" -eq 1 ]; then
  # Nie podejmujemy prawdziwego zlecenia: `claim` zmienia jego stan na podjęte.
  # Zlecenie oznaczone jako podjęte i nigdy niewykonane wisiałoby w panelu,
  # a to właśnie tryb, który ma nie zostawiać śladów.
  ID_ZLECENIA='na-sucho'
  NAZWA_ZALEZNOSCI='mempalace'
  WERSJA_DOCELOWA="${WERSJA_Z_ARGUMENTU:-${WERSJA_OBECNA}}"
  zapisz "(na sucho) nie wywołuję ws:updater:claim — udaję zlecenie: ${NAZWA_ZALEZNOSCI} ${WERSJA_OBECNA} → ${WERSJA_DOCELOWA}"
else
  zapisz "\$ docker compose exec -T backend php bin/console ws:updater:claim"
  set +e
  ZLECENIE="$(docker compose exec -T backend php bin/console ws:updater:claim 2>>"${DZIENNIK}")"
  KOD_CLAIM=$?
  set -e
  if [ "${KOD_CLAIM}" -ne 0 ]; then
    przerwij_porazka "ws:updater:claim zakończyło się kodem ${KOD_CLAIM}"
  fi

  # Puste wyjście (albo same białe znaki) = brak roboty.
  if [ -z "$(printf '%s' "${ZLECENIE}" | tr -d '[:space:]')" ]; then
    zapisz "Brak zlecenia — nie ma nic do zrobienia."
    exit 0
  fi

  printf '%s\n' "${ZLECENIE}" | jq -e . >/dev/null 2>&1 \
    || przerwij_porazka "ws:updater:claim wypisało coś, co nie jest JSON-em: ${ZLECENIE}"

  ID_ZLECENIA="$(printf '%s\n' "${ZLECENIE}" | jq -r '.id // empty')"
  NAZWA_ZALEZNOSCI="$(printf '%s\n' "${ZLECENIE}" | jq -r '.name // empty')"
  WERSJA_DOCELOWA="$(printf '%s\n' "${ZLECENIE}" | jq -r '.toVersion // empty')"
  WERSJA_ZE_ZLECENIA="$(printf '%s\n' "${ZLECENIE}" | jq -r '.fromVersion // empty')"

  # Identyfikator sprawdzamy PIERWSZY, bo bez niego nie mamy jak zgłosić
  # porażki — a porażka bez śladu w panelu to zlecenie, które wisi na zawsze.
  [[ "${ID_ZLECENIA}" =~ ${WZORZEC_ID} ]] \
    || { ID_ZLECENIA=''; przerwij_porazka "identyfikator zlecenia nie przechodzi walidacji — zlecenie odrzucone bez wykonania"; }

  zapisz "Podjęte zlecenie ${ID_ZLECENIA}: ${NAZWA_ZALEZNOSCI:-?} ${WERSJA_ZE_ZLECENIA:-?} → ${WERSJA_DOCELOWA:-?}"

  # Rozjazd między tym, co widziała aplikacja, a tym, co jest w .env, sam
  # w sobie jest informacją (ktoś zmienił .env i nie przebudował obrazu).
  # Nie przerywa: wycofujemy się do .env, więc rozjazd nam nie szkodzi.
  if [ -n "${WERSJA_ZE_ZLECENIA}" ] && [ "${WERSJA_ZE_ZLECENIA}" != "${WERSJA_OBECNA}" ]; then
    ostrzez "aplikacja uważa, że działa ${WERSJA_ZE_ZLECENIA}, a w .env jest ${WERSJA_OBECNA}. Wycofanie (gdyby było potrzebne) wróci do ${WERSJA_OBECNA}, bo to z niej system naprawdę wstaje."
  fi

  # Agent umie aktualizować pałac i tylko pałac. Nazwa spoza listy to nie błąd
  # do zignorowania — zlecenie trzeba domknąć, żeby nie wisiało jako podjęte.
  if [ "${NAZWA_ZALEZNOSCI}" != "mempalace" ]; then
    przerwij_porazka "nie umiem aktualizować zależności „${NAZWA_ZALEZNOSCI}” — ten agent obsługuje wyłącznie mempalace"
  fi
fi

# ─── Krok 3: walidacja wersji docelowej ──────────────────────────────────────
#
# Jedyne miejsce, w którym dane z aplikacji webowej wpływają na polecenie
# wykonywane na hoście: wersja trafia do .env, a stamtąd do
# `pip install mempalace==<wersja>` w Dockerfile. Wzorzec jest wąski celowo —
# dopuszcza wyłącznie trzy liczby i kropki, więc nie ma czym uciec z napisu.

if ! [[ "${WERSJA_DOCELOWA}" =~ ${WZORZEC_WERSJI} ]]; then
  przerwij_porazka "wersja docelowa „${WERSJA_DOCELOWA}” nie pasuje do wzorca X.Y.Z — przerywam, nic nie ruszam"
fi
zapisz "Wersja docelowa przeszła walidację: ${WERSJA_DOCELOWA}"

if [ "${WERSJA_DOCELOWA}" = "${WERSJA_OBECNA}" ]; then
  zapisz "Wersja docelowa jest równa obecnej — przebudowa i tak przejdzie (będzie z pamięci podręcznej), a test semantyki potwierdzi, że pałac działa."
fi

# ─── Krok 4: kopia zapasowa schematu palace ──────────────────────────────────
#
# PRZED czymkolwiek. Aktualizacja pałaca dotyka wektorów; bez kopii nie ma
# powrotu, a wycofanie samej wersji obrazu nie odtworzy zmienionego schematu.
# Bez udanej kopii NIE KONTYNUUJEMY — to jedyny nienegocjowalny warunek.
#
# Hasło bierzemy ze środowiska kontenera Postgresa (`$POSTGRES_PASSWORD` jest
# rozwijane w kontenerze, nie w tej powłoce), żeby nie pojawiło się w liście
# procesów hosta ani w dzienniku zlecenia widocznym w panelu.

PLIK_KOPII="${KATALOG_KOPII}/palace-$(znacznik_czasu)-z-${WERSJA_OBECNA}.dump"

if [ "${NA_SUCHO}" -eq 1 ]; then
  zapisz "(na sucho) pomijam pg_dump. Kopia trafiłaby do: ${PLIK_KOPII}"
  # Ścieżka zostaje wypisana, ale zmienną czyścimy: dalsze komunikaty nie mają
  # powoływać się na plik, którego na sucho nikt nie stworzył.
  PLIK_KOPII=''
  if wykonaj docker compose exec -T postgres pg_dump --version; then
    zapisz "Postgres odpowiada i ma pg_dump — kopia zapasowa byłaby wykonalna."
  else
    ostrzez "nie udało się uruchomić pg_dump w kontenerze postgres — w normalnym przebiegu agent zatrzymałby się w tym miejscu"
  fi
else
  zapisz "\$ docker compose exec -T postgres pg_dump --schema=palace --format=custom → ${PLIK_KOPII}"
  if ! docker compose exec -T postgres sh -c \
        'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump -U postgres -d "$POSTGRES_DB" --schema=palace --format=custom --no-password' \
        >"${PLIK_KOPII}" 2>>"${DZIENNIK}"; then
    rm -f "${PLIK_KOPII}"
    PLIK_KOPII=''
    przerwij_porazka "kopia zapasowa schematu palace nie powstała — przerywam PRZED jakąkolwiek zmianą"
  fi

  # Kod wyjścia 0 nie dowodzi, że plik jest kompletny (urwane przekierowanie,
  # brak miejsca). Format custom zaczyna się od znacznika „PGDMP”, więc
  # sprawdzenie pięciu bajtów i rozmiaru łapie i pustkę, i śmieci — bez
  # zależności od pg_restore, który przy potoku bywa kapryśny.
  ROZMIAR_KOPII="$(wc -c <"${PLIK_KOPII}" | tr -d '[:space:]')"
  if [ "$(head -c 5 "${PLIK_KOPII}")" != "PGDMP" ] || [ "${ROZMIAR_KOPII}" -lt 1024 ]; then
    zapisz "Kopia ma ${ROZMIAR_KOPII} bajtów i nie wygląda na zrzut Postgresa."
    rm -f "${PLIK_KOPII}"
    PLIK_KOPII=''
    przerwij_porazka "kopia zapasowa jest niewiarygodna — przerywam PRZED jakąkolwiek zmianą"
  fi
  zapisz "Kopia zapasowa gotowa: ${PLIK_KOPII} (${ROZMIAR_KOPII} bajtów)"
fi

# ─── Krok 5: podmiana wersji w .env ──────────────────────────────────────────

if ! ustaw_wersje_w_env "${WERSJA_DOCELOWA}"; then
  przerwij_porazka "nie udało się ustawić MEMPALACE_VERSION=${WERSJA_DOCELOWA} w .env"
fi
# Od tej chwili każda porażka pociąga za sobą wycofanie.
[ "${NA_SUCHO}" -eq 1 ] || WYMAGA_WYCOFANIA=1

# ─── Krok 6: przebudowa i podniesienie ───────────────────────────────────────
#
# Obraz jest tagowany wersją (`ws-memory/mempalace:<wersja>`), więc poprzedni
# zostaje w Dockerze i wycofanie schodzi z pamięci podręcznej.

if ! wykonaj_zmiane docker compose build mempalace; then
  przerwij_porazka "przebudowa obrazu mempalace ${WERSJA_DOCELOWA} nie powiodła się (najczęściej: takiej wersji nie ma na PyPI)"
fi

if ! wykonaj_zmiane docker compose up -d --wait --wait-timeout "${LIMIT_CZEKANIA}" mempalace; then
  przerwij_porazka "pałac w wersji ${WERSJA_DOCELOWA} nie stał się zdrowy w ${LIMIT_CZEKANIA} s"
fi

# ─── Krok 7: test semantyki ──────────────────────────────────────────────────
#
# Krok, bez którego cała ta operacja byłaby wiarą, a nie sprawdzeniem. Zepsuta
# trafność wyszukiwania jest cicha (D-003): usługa odpowiada, zapis przechodzi,
# tylko polskie zapytanie przestaje znajdować polską treść. Nikt by tego nie
# zauważył — aż do dnia, w którym ktoś nie znalazłby czegoś, co wie, że zapisał.

if [ "${NA_SUCHO}" -eq 1 ]; then
  zapisz "(na sucho) pomijam ${SKRYPT_SEMANTYKI} — test zapisuje szufladę do pałaca. Plik jest na miejscu i wykonywalny."
else
  if ! wykonaj "${SKRYPT_SEMANTYKI}"; then
    przerwij_porazka "test semantyki NIE PRZESZEDŁ po aktualizacji do ${WERSJA_DOCELOWA} — wyszukiwanie semantyczne przestało działać, więc wracam do ${WERSJA_OBECNA}"
  fi
  zapisz "Test semantyki przeszedł — wyszukiwanie semantyczne działa na wersji ${WERSJA_DOCELOWA}"
fi

# ─── Krok 8: wynik ───────────────────────────────────────────────────────────

WYMAGA_WYCOFANIA=0
zapisz "GOTOWE: MemPalace ${WERSJA_OBECNA} → ${WERSJA_DOCELOWA}. Kopia przed aktualizacją: ${PLIK_KOPII:-(na sucho nie powstała)}"
zglos_wynik succeeded

if [ "${NA_SUCHO}" -eq 1 ]; then
  printf '\n'
  if [ "${OSTRZEZENIA}" -gt 0 ]; then
    printf '%sNA SUCHO: ścieżka decyzyjna przeszła, ale z ostrzeżeniami (%s).%s\n' \
      "${ZOLTY}" "${OSTRZEZENIA}" "${KONIEC}"
    printf 'Host jest gotowy; brakujące elementy po stronie aplikacji są wypisane wyżej jako UWAGA.\n'
  else
    printf '%sNA SUCHO: ścieżka decyzyjna przeszła w całości.%s\n' "${ZIELONY}" "${KONIEC}"
  fi
else
  printf '%sZAKTUALIZOWANO do %s.%s\n' "${ZIELONY}" "${WERSJA_DOCELOWA}" "${KONIEC}"
fi
exit 0
