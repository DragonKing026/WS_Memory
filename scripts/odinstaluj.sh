#!/usr/bin/env bash
#
# Deinstalator WS_Memory (TODO-018).
#
# Usuwa to, co postawił `scripts/instaluj.sh`: kontenery, wolumeny, obrazy,
# wygenerowany `.env` i plik z hasłem administratora.
#
# **Jest trudniejszy niż instalator i ma taki być.** Instalator tworzy, ten
# kasuje bazę wiedzy — więc potwierdzenie polega na WPISANIU NAZWY instancji,
# a nie na naciśnięciu `t`. Klawisz naciska się odruchowo; nazwę trzeba
# przeczytać z ekranu i przepisać, czyli przez chwilę pomyśleć o tym, co się
# usuwa.
#
# Domyślnie ZOSTAJĄ dwie rzeczy:
#
#   kopie zapasowe  — w `kopie/`. Kasowanie kopii razem z danymi zamienia
#                     „odinstalowałem" w „nie ma do czego wrócić".
#   model embeddingów — wolumen ~2,3 GB, pobierany godzinami. Przy ponownej
#                     instalacji oszczędza całe to czekanie, a nie zawiera
#                     żadnych Twoich danych.
#
# Na jedno i drugie są osobne, jawne opcje.
#
# Użycie:
#   ./scripts/odinstaluj.sh                  # kontenery, wolumeny danych, .env
#   ./scripts/odinstaluj.sh --na-sucho       # wypisz, co by zniknęło
#   ./scripts/odinstaluj.sh --z-kopiami      # usuń także kopie zapasowe
#   ./scripts/odinstaluj.sh --z-modelem      # usuń także wolumen modelu
#   ./scripts/odinstaluj.sh --z-obrazami     # usuń także zbudowane obrazy
#   ./scripts/odinstaluj.sh --wszystko       # trzy powyższe naraz
#   ./scripts/odinstaluj.sh --potwierdz=NAZWA
#                                            # bez pytania (dla skryptów)
#   ./scripts/odinstaluj.sh --projekt=NAZWA  # gdy nazwy projektu nie da się ustalić
#
# Kody wyjścia: 0 = zrobione, 1 = przerwane, 2 = zły argument.
#
# Polskie cudzysłowy w komunikatach są zamierzone.
# shellcheck disable=SC1111

set -euo pipefail

KORZEN="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${KORZEN}"

NA_SUCHO=0
Z_KOPIAMI=0
Z_MODELEM=0
Z_OBRAZAMI=0
POTWIERDZENIE=''
PROJEKT_Z_ARGUMENTU=''

for arg in "$@"; do
  case "${arg}" in
    --na-sucho) NA_SUCHO=1 ;;
    --z-kopiami) Z_KOPIAMI=1 ;;
    --z-modelem) Z_MODELEM=1 ;;
    --z-obrazami) Z_OBRAZAMI=1 ;;
    --wszystko) Z_KOPIAMI=1; Z_MODELEM=1; Z_OBRAZAMI=1 ;;
    --potwierdz=*) POTWIERDZENIE="${arg#--potwierdz=}" ;;
    --projekt=*) PROJEKT_Z_ARGUMENTU="${arg#--projekt=}" ;;
    -h|--help) sed -n '3,36p' "${BASH_SOURCE[0]}" | sed 's/^#\{1\} \{0,1\}//'; exit 0 ;;
    *) echo "Nieznany argument: ${arg}" >&2; exit 2 ;;
  esac
done

if [ -t 1 ]; then
  ZIELONY=$'\e[32m'; CZERWONY=$'\e[31m'; ZOLTY=$'\e[33m'; JASNY=$'\e[1m'; KONIEC=$'\e[0m'
else
  ZIELONY=''; CZERWONY=''; ZOLTY=''; JASNY=''; KONIEC=''
fi

naglowek() { printf '\n%s── %s %s\n' "${JASNY}" "$*" "${KONIEC}"; }
powiedz()  { printf '   %s\n' "$*"; }
udalo()    { printf '   %s✓%s %s\n' "${ZIELONY}" "${KONIEC}" "$*"; }
ostrzez()  { printf '   %s!%s %s\n' "${ZOLTY}" "${KONIEC}" "$*"; }
usunie()   { printf '   %s✗%s %s\n' "${CZERWONY}" "${KONIEC}" "$*"; }

# Nazwę projektu ustala wspólna funkcja z scripts/wspolne/projekt.sh — ta sama,
# której używa instalator. Historia tego kawałka to dwa błędy tej samej rodziny,
# oba znalezione uruchomieniem, nie czytaniem: nazwa z katalogu (a plik ustawia
# `name: ws-memory`) i `docker compose config` jako jedyne źródło (nie działa
# bez `.env`, czyli akurat po przerwanej instalacji). Obie kończyły się tak
# samo: filtr nie znajdował żadnego wolumenu, a skrypt meldował „odinstalowane".
#
# Gdy nie da się ustalić — odmawiamy. Skrypt, który kasuje, ma prawo nie
# wiedzieć; nie ma prawa zgadywać.
# shellcheck source=scripts/wspolne/projekt.sh
. "${KORZEN}/scripts/wspolne/projekt.sh"

if [ -n "${PROJEKT_Z_ARGUMENTU}" ]; then
  PROJEKT="${PROJEKT_Z_ARGUMENTU}"
elif ! PROJEKT="$(ustal_projekt_compose "${KORZEN}")"; then
  printf '%sPrzerwane.%s Nie umiem ustalić nazwy projektu Compose.\n' "${CZERWONY}" "${KONIEC}" >&2
  printf 'Podaj ją: --projekt=NAZWA (zobaczysz ją w: docker compose ls)\n' >&2
  exit 1
fi

NAZWA="${PROJEKT}"

wykonaj() {
  if [ "${NA_SUCHO}" -eq 1 ]; then
    powiedz "(na sucho) pominięte: $*"
    return 0
  fi
  "$@"
}

# ─── Co tu w ogóle jest ──────────────────────────────────────────────────────
#
# Wypisujemy stan zastany, zanim cokolwiek zniknie. Deinstalator, który od razu
# kasuje, każe potwierdzać usunięcie czegoś, czego się nie widziało.

# Każde z tych podstawień musi mieć `|| true`, i to nie jest ostrożność na wyrost.
# `docker compose ps` bez `.env` kończy się błędem, a przy `set -e` całe
# podstawienie ubijało skrypt — bez jednej linijki na wyjściu. Deinstalator
# milczący to deinstalator, po którym nie wiadomo, czy cokolwiek zrobił.
#
# Kontenery liczymy po etykiecie projektu, a nie przez `docker compose ps`,
# z tego samego powodu: etykieta działa też wtedy, gdy pliku compose nie da się
# odczytać.
KONTENERY="$(docker ps -aq --filter "label=com.docker.compose.project=${PROJEKT}" 2>/dev/null | wc -l | tr -d ' ' || true)"
WOLUMENY="$(docker volume ls -q --filter "label=com.docker.compose.project=${PROJEKT}" 2>/dev/null || true)"
OBRAZY="$(docker images -q 'ws-memory/backend' 2>/dev/null || true)"

# Wolumen modelu trzymamy osobno, bo jest jedyny, którego domyślnie NIE ruszamy.
WOLUMEN_MODELU="$(printf '%s\n' "${WOLUMENY}" | grep -E 'embeddings-cache$' || true)"
WOLUMENY_DANYCH="$(printf '%s\n' "${WOLUMENY}" | grep -vE 'embeddings-cache$' | grep -v '^$' || true)"

printf '%s\n' "${JASNY}WS_Memory — deinstalator${KONIEC}"
printf 'Instancja: %s\n' "${NAZWA}"
printf 'Katalog:   %s\n' "${KORZEN}"
[ "${NA_SUCHO}" -eq 1 ] && ostrzez "NA SUCHO: nic nie zostanie usunięte."

naglowek "Zostanie USUNIĘTE"

usunie "Kontenery projektu ${PROJEKT}: ${KONTENERY}"

if [ -n "${WOLUMENY_DANYCH}" ]; then
  while IFS= read -r w; do
    [ -n "${w}" ] || continue
    usunie "Wolumen: ${w}$([ "${w}" = "${PROJEKT}_postgres-dane" ] && printf ' %s(BAZA WIEDZY — dokumenty, pamięć, konta)%s' "${CZERWONY}" "${KONIEC}")"
  done <<<"${WOLUMENY_DANYCH}"
else
  powiedz "Wolumeny danych: nie ma żadnego"
fi

[ -f "${KORZEN}/.env" ] && usunie "Plik .env (hasła do bazy, sekrety aplikacji)"
[ -f "${KORZEN}/backend/var/dostep-administratora.txt" ] && usunie "Plik z hasłem administratora"
[ -f "${KORZEN}/var/instalacja.conf" ] && usunie "Ślad instalacji (var/instalacja.conf)"

if [ "${Z_MODELEM}" -eq 1 ] && [ -n "${WOLUMEN_MODELU}" ]; then
  usunie "Wolumen modelu embeddingów (~2,3 GB, pobieranie od nowa trwa godzinami)"
fi

if [ "${Z_OBRAZAMI}" -eq 1 ] && [ -n "${OBRAZY}" ]; then
  usunie "Zbudowane obrazy ws-memory/backend"
fi

if [ "${Z_KOPIAMI}" -eq 1 ] && [ -d "${KORZEN}/kopie" ]; then
  usunie "Kopie zapasowe w kopie/ ($(find "${KORZEN}/kopie" -type f 2>/dev/null | wc -l | tr -d ' ') plików)"
fi

naglowek "ZOSTANIE"

[ "${Z_KOPIAMI}" -eq 0 ] && powiedz "Kopie zapasowe w kopie/ — usuwa je dopiero --z-kopiami"
[ "${Z_MODELEM}" -eq 0 ] && [ -n "${WOLUMEN_MODELU}" ] && powiedz "Wolumen modelu embeddingów — usuwa go dopiero --z-modelem"
[ "${Z_OBRAZAMI}" -eq 0 ] && powiedz "Zbudowane obrazy — usuwa je dopiero --z-obrazami"
powiedz "Kod źródłowy i repozytorium git — deinstalator nie kasuje kodu"
powiedz "Twój lokalny pałac MemPalace (jeśli masz go poza tym stosem)"

# ─── Potwierdzenie ───────────────────────────────────────────────────────────

if [ "${NA_SUCHO}" -eq 0 ]; then
  naglowek "Potwierdzenie"

  if [ -n "${POTWIERDZENIE}" ]; then
    if [ "${POTWIERDZENIE}" != "${NAZWA}" ]; then
      printf '\n%sPrzerwane.%s --potwierdz=%s nie zgadza się z nazwą instancji (%s).\n' \
        "${CZERWONY}" "${KONIEC}" "${POTWIERDZENIE}" "${NAZWA}" >&2
      exit 1
    fi
    ostrzez "Potwierdzone argumentem --potwierdz."
  else
    if [ ! -t 0 ]; then
      printf '\n%sPrzerwane.%s Bez terminala potwierdzenie trzeba podać: --potwierdz=%s\n' \
        "${CZERWONY}" "${KONIEC}" "${NAZWA}" >&2
      exit 1
    fi

    powiedz "Żeby usunąć, przepisz nazwę instancji: ${JASNY}${NAZWA}${KONIEC}"
    read -r -p "   > " wpisane || true

    if [ "${wpisane}" != "${NAZWA}" ]; then
      printf '\n%sPrzerwane.%s Nic nie zostało usunięte.\n' "${ZIELONY}" "${KONIEC}"
      exit 1
    fi
  fi
fi

# ─── Kopia przed usunięciem ──────────────────────────────────────────────────
#
# Robiona zawsze, także gdy ktoś prosi o usunięcie kopii — bo `--z-kopiami`
# znaczy „usuń stare kopie", a nie „nie rób ostatniej". Nieudana kopia nie
# zatrzymuje usuwania: powodem bywa baza, która już nie wstaje, a wtedy
# odmowa usunięcia zostawiałaby człowieka bez wyjścia.

naglowek "Kopia zapasowa przed usunięciem"

if docker compose ps --format '{{.Service}}' 2>/dev/null | grep -q '^postgres$'; then
  PLIK_KOPII="${KORZEN}/kopie/przed-usunieciem-$(date '+%Y%m%d-%H%M%S').sql.gz"
  mkdir -p "${KORZEN}/kopie"

  if [ "${NA_SUCHO}" -eq 1 ]; then
    powiedz "(na sucho) pominięte: pg_dumpall do ${PLIK_KOPII}"
  elif docker compose exec -T postgres pg_dumpall -U postgres 2>/dev/null | gzip >"${PLIK_KOPII}"; then
    udalo "Zapisana: ${PLIK_KOPII} ($(du -h "${PLIK_KOPII}" | cut -f1))"
  else
    rm -f "${PLIK_KOPII}"
    ostrzez "Nie udało się zrobić kopii — baza nie odpowiada. Usuwam dalej."
  fi
else
  powiedz "Baza nie działa — nie ma czego zrzucić."
fi

# ─── Usuwanie ────────────────────────────────────────────────────────────────

naglowek "Usuwam"

# `down` z wolumenami załatwia kontenery, sieć i wolumeny projektu naraz —
# poza tymi, które chcemy zachować, więc model odtwarzamy zaraz po.
if [ "${Z_MODELEM}" -eq 0 ] && [ -n "${WOLUMEN_MODELU}" ] && [ "${NA_SUCHO}" -eq 0 ]; then
  # Nazwany wolumen przetrwa `down -v` tylko wtedy, gdy Compose go nie zna jako
  # swojego — a zna. Więc zamiast kombinować: zrzucamy go do obrazu tymczasowego
  # przez zwykłe zatrzymanie i wyłączenie z usuwania nie jest możliwe. Dlatego
  # usuwamy wybiórczo, bez `-v`.
  wykonaj docker compose down --remove-orphans || ostrzez "docker compose down zgłosił błąd."

  if [ -n "${WOLUMENY_DANYCH}" ]; then
    while IFS= read -r w; do
      [ -n "${w}" ] || continue
      wykonaj docker volume rm "${w}" >/dev/null 2>&1 \
        && udalo "Usunięty wolumen ${w}" \
        || ostrzez "Nie udało się usunąć wolumenu ${w}"
    done <<<"${WOLUMENY_DANYCH}"
  fi

  udalo "Wolumen modelu zachowany — ponowna instalacja nie będzie go pobierać."
else
  wykonaj docker compose down -v --remove-orphans || ostrzez "docker compose down zgłosił błąd."
  udalo "Kontenery i wolumeny usunięte."
fi

if [ "${Z_OBRAZAMI}" -eq 1 ] && [ -n "${OBRAZY}" ]; then
  # shellcheck disable=SC2086
  wykonaj docker rmi -f ${OBRAZY} >/dev/null 2>&1 \
    && udalo "Obrazy usunięte." \
    || ostrzez "Nie udało się usunąć części obrazów (mogą być używane)."
fi

for plik in "${KORZEN}/.env" "${KORZEN}/backend/var/dostep-administratora.txt" "${KORZEN}/var/instalacja.conf"; do
  if [ -f "${plik}" ]; then
    wykonaj rm -f "${plik}" && udalo "Usunięty ${plik#"${KORZEN}"/}"
  fi
done

if [ "${Z_KOPIAMI}" -eq 1 ] && [ -d "${KORZEN}/kopie" ]; then
  wykonaj rm -rf "${KORZEN}/kopie" && udalo "Kopie zapasowe usunięte."
fi

# ─── Co zostało ──────────────────────────────────────────────────────────────

naglowek "Co zostało"

if [ "${NA_SUCHO}" -eq 1 ]; then
  powiedz "Na sucho: nic nie zostało usunięte."
  exit 0
fi

POZOSTALE_KONTENERY="$(docker ps -aq --filter "label=com.docker.compose.project=${PROJEKT}" 2>/dev/null | wc -l | tr -d ' ' || true)"
POZOSTALE_WOLUMENY="$(docker volume ls -q --filter "label=com.docker.compose.project=${PROJEKT}" 2>/dev/null | wc -l | tr -d ' ' || true)"

powiedz "Kontenery projektu: ${POZOSTALE_KONTENERY}"
powiedz "Wolumeny projektu:  ${POZOSTALE_WOLUMENY}"

[ -d "${KORZEN}/kopie" ] && powiedz "Kopie zapasowe:     $(find "${KORZEN}/kopie" -type f 2>/dev/null | wc -l | tr -d ' ') plików w kopie/"

printf '\n'
if [ "${POZOSTALE_KONTENERY}" = "0" ] && { [ "${POZOSTALE_WOLUMENY}" = "0" ] || [ "${Z_MODELEM}" -eq 0 ]; }; then
  printf '   %sOdinstalowane.%s Kod źródłowy został — instalujesz od nowa przez ./scripts/instaluj.sh\n' \
    "${ZIELONY}" "${KONIEC}"
else
  ostrzez "Coś zostało. Sprawdź: docker compose ps -a && docker volume ls"
fi
