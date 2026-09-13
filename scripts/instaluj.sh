#!/usr/bin/env bash
#
# Instalator WS_Memory (TODO-018).
#
# Stawia instancję od zera: pyta o to, czego nie da się zgadnąć, sam generuje
# sekrety, zapisuje `.env`, podnosi stos, wykonuje migracje, zakłada konto
# administratora i SPRAWDZA, czy to wszystko naprawdę działa.
#
# Dlaczego w ogóle istnieje: dotąd postawienie instancji wymagało przejścia
# ośmiu kroków z `docs/05-deployment.md`, z których każdy da się pominąć,
# a większość milczy, gdy się to zrobi. Raz skończyło się to instancją, do
# której nikt nie znał hasła — bo nigdzie go nie zapisano.
#
# Dwie drogi instalacji NIE są równorzędne i skrypt tego nie ukrywa:
#
#   docker  — droga wspierana. Siedem usług z przypiętymi wersjami, w tym
#             Postgres z pgvector, serwer embeddingów i MemPalace.
#   system  — instalacja samej aplikacji na TWOICH składnikach. Skrypt nie
#             zainstaluje Postgresa z pgvector ani modelu embeddingów na
#             dowolnej dystrybucji; sprawdzi, czego brakuje, i powie, jak to
#             zdobyć. Obiecywanie więcej byłoby obietnicą niemożliwą do
#             dotrzymania na maszynie, której się nie widziało.
#
# Sekretów skrypt NIE pyta. Sekret wpisany z palca jest słaby albo zapisany
# w drugim miejscu; `APP_SECRET`, hasła do bazy, `JWT_PASSPHRASE` i token MCP
# są generowane.
#
# Użycie:
#   ./scripts/instaluj.sh                          # rozmowa z pytaniami
#   ./scripts/instaluj.sh --droga=docker           # bez pytania o drogę
#   ./scripts/instaluj.sh --plik-odpowiedzi=x.conf # bez człowieka
#   ./scripts/instaluj.sh --zapisz-odpowiedzi=x.conf
#                                                  # zapisz odpowiedzi (bez sekretów)
#   ./scripts/instaluj.sh --na-sucho               # sprawdzenia i pytania, zero zmian
#   ./scripts/instaluj.sh --tylko-sprawdzenie      # same wymagania wstępne
#   ./scripts/instaluj.sh --tylko-konfiguracja     # zapisz .env i nie ruszaj Dockera
#   ./scripts/instaluj.sh --zachowaj-env           # dokończ instalację na istniejącym .env
#
# Kody wyjścia: 0 = gotowe, 1 = porażka, 2 = zły argument, 3 = brak wymagań.
#
# Odinstalowanie: ./scripts/odinstaluj.sh

# Polskie cudzysłowy („ ”) w komunikatach są zamierzone — to teksty czytane przez
# człowieka, a nie składnia powłoki. shellcheck słusznie pyta o nie w kodzie
# angielskim, gdzie zwykle są literówką; tutaj byłyby literówką dopiero po
# zamianie na proste.
# shellcheck disable=SC1111

set -euo pipefail

KORZEN="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${KORZEN}"

# ─── Argumenty ───────────────────────────────────────────────────────────────

DROGA=''
PLIK_ODPOWIEDZI=''
ZAPISZ_ODPOWIEDZI=''
NA_SUCHO=0
TYLKO_SPRAWDZENIE=0
TYLKO_KONFIGURACJA=0
ZACHOWAJ_ENV=0

for arg in "$@"; do
  case "${arg}" in
    --droga=*) DROGA="${arg#--droga=}" ;;
    --plik-odpowiedzi=*) PLIK_ODPOWIEDZI="${arg#--plik-odpowiedzi=}" ;;
    --zapisz-odpowiedzi=*) ZAPISZ_ODPOWIEDZI="${arg#--zapisz-odpowiedzi=}" ;;
    --na-sucho) NA_SUCHO=1 ;;
    --tylko-sprawdzenie) TYLKO_SPRAWDZENIE=1 ;;
    --tylko-konfiguracja) TYLKO_KONFIGURACJA=1 ;;
    --zachowaj-env) ZACHOWAJ_ENV=1 ;;
    -h|--help) sed -n '3,40p' "${BASH_SOURCE[0]}" | sed 's/^#\{1\} \{0,1\}//'; exit 0 ;;
    *) echo "Nieznany argument: ${arg}" >&2; exit 2 ;;
  esac
done

if [ -n "${DROGA}" ] && [ "${DROGA}" != 'docker' ] && [ "${DROGA}" != 'system' ]; then
  echo "--droga= przyjmuje 'docker' albo 'system'." >&2
  exit 2
fi

# ─── Wygląd ──────────────────────────────────────────────────────────────────

if [ -t 1 ]; then
  ZIELONY=$'\e[32m'; CZERWONY=$'\e[31m'; ZOLTY=$'\e[33m'; JASNY=$'\e[1m'; KONIEC=$'\e[0m'
else
  ZIELONY=''; CZERWONY=''; ZOLTY=''; JASNY=''; KONIEC=''
fi

naglowek() { printf '\n%s── %s %s\n' "${JASNY}" "$*" "${KONIEC}"; }
powiedz()  { printf '   %s\n' "$*"; }
udalo()    { printf '   %s✓%s %s\n' "${ZIELONY}" "${KONIEC}" "$*"; }
ostrzez()  { printf '   %s!%s %s\n' "${ZOLTY}" "${KONIEC}" "$*"; }
zawiedz()  { printf '   %s✗%s %s\n' "${CZERWONY}" "${KONIEC}" "$*" >&2; }

przerwij() {
  printf '\n%sInstalacja przerwana.%s %s\n' "${CZERWONY}" "${KONIEC}" "$*" >&2
  printf 'Stan pośredni sprząta ./scripts/odinstaluj.sh\n' >&2
  exit 1
}

# ─── Wymagania wstępne ───────────────────────────────────────────────────────
#
# Wszystkie braki naraz, a nie po jednym. Skrypt, który zatrzymuje się na
# pierwszym, każe człowiekowi przechodzić instalację tyle razy, ile ma braków
# — i za każdym razem zmienia coś w systemie, zanim się zatrzyma. Dlatego to
# sprawdzenie jest PRZED jakąkolwiek zmianą.

BRAKI=()
brak() { BRAKI+=("$1"); }

wersja_nie_starsza() {
  # "czy $1 >= $2", porównanie po składowych. sort -V wystarcza i nie wymaga
  # niczego spoza coreutils.
  [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -1)" = "$2" ]
}

wolne_miejsce_gb() {
  df -BG --output=avail "${KORZEN}" 2>/dev/null | tail -1 | tr -dc '0-9'
}

port_zajety() {
  # ss jest w iproute2, obecnym wszędzie; bez niego nie zgadujemy, tylko mówimy,
  # że nie wiemy — cichy fałsz jest gorszy niż jawna niewiedza.
  command -v ss >/dev/null 2>&1 || return 2
  ss -ltn "sport = :$1" 2>/dev/null | grep -q LISTEN
}

sprawdz_docker() {
  if ! command -v docker >/dev/null 2>&1; then
    brak "Nie ma polecenia 'docker'. Zainstaluj Docker Engine: https://docs.docker.com/engine/install/"
    return
  fi

  if ! docker compose version >/dev/null 2>&1; then
    brak "Nie ma wtyczki 'docker compose' (v2). Pakiet docker-compose-plugin."
    return
  fi

  if ! docker info >/dev/null 2>&1; then
    brak "Docker jest, ale ten użytkownik nie ma do niego dostępu. Dodaj się do grupy 'docker' i zaloguj ponownie (albo uruchom przez sudo)."
    return
  fi

  local wersja
  wersja="$(docker compose version --short 2>/dev/null | tr -dc '0-9.' || echo 0)"
  if ! wersja_nie_starsza "${wersja}" "2.20"; then
    brak "docker compose ${wersja} jest za stary — potrzeba 2.20 lub nowszego (profile i zależności zdrowotne)."
  fi
}

sprawdz_wspolne() {
  command -v openssl >/dev/null 2>&1 || brak "Nie ma 'openssl' — bez niego nie wygeneruję sekretów."
  command -v curl >/dev/null 2>&1 || brak "Nie ma 'curl' — bez niego nie sprawdzę, czy instancja odpowiada."

  local miejsce
  miejsce="$(wolne_miejsce_gb || echo 0)"
  if [ -n "${miejsce}" ] && [ "${miejsce}" -lt 12 ]; then
    # 12 GB nie jest zapasem: same obrazy to ~6 GB, model embeddingów ~2,3 GB,
    # a baza z wektorami rośnie od pierwszego dnia.
    brak "Za mało miejsca: ${miejsce} GB wolnego, a potrzeba co najmniej 12 GB (obrazy ~6 GB, model ~2,3 GB, baza)."
  fi
}

sprawdz_porty() {
  local port="$1"
  case "$(port_zajety "${port}"; echo $?)" in
    0) brak "Port ${port} jest już zajęty. Podaj inny (NGINX_PORT) albo zwolnij ten." ;;
    2) ostrzez "Nie ma polecenia 'ss', więc nie sprawdziłem, czy port ${port} jest wolny." ;;
  esac
}

sprawdz_system() {
  # Ta droga nie instaluje składników — wykrywa je i mówi, czego brak.
  command -v php >/dev/null 2>&1 || brak "Nie ma PHP. Potrzeba PHP 8.4 z rozszerzeniami: pdo_pgsql, intl, ctype, iconv."
  if command -v php >/dev/null 2>&1; then
    local php_wersja
    php_wersja="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo 0)"
    wersja_nie_starsza "${php_wersja}" "8.4" || brak "PHP ${php_wersja} jest za stary — potrzeba 8.4."
    php -m 2>/dev/null | grep -qi pdo_pgsql || brak "PHP nie ma rozszerzenia pdo_pgsql."
    php -m 2>/dev/null | grep -qi intl || brak "PHP nie ma rozszerzenia intl."
  fi

  command -v composer >/dev/null 2>&1 || brak "Nie ma Composera."
  command -v node >/dev/null 2>&1 || brak "Nie ma Node.js (potrzebny do zbudowania frontendu)."
  command -v psql >/dev/null 2>&1 || brak "Nie ma klienta psql — nie sprawdzę bazy ani nie wykonam migracji."
  command -v nginx >/dev/null 2>&1 || brak "Nie ma nginxa."
  command -v systemctl >/dev/null 2>&1 || brak "Nie ma systemd — jednostki usług nie mają się na czym oprzeć."

  # Te dwa są sednem: bez nich aplikacja się uruchomi i będzie CICHO nie działać.
  brak "SPRAWDŹ SAM: czy Postgres ma rozszerzenie pgvector (CREATE EXTENSION vector)?"
  brak "SPRAWDŹ SAM: czy masz serwer embeddingów zgodny z OpenAI pod jakimś adresem? Bez niego wyszukiwanie zwraca puste wyniki i NIE zgłasza błędu (D-003)."
}

# ─── Pytania ─────────────────────────────────────────────────────────────────
#
# Odpowiedzi trzymamy w tablicy asocjacyjnej, żeby plik odpowiedzi i rozmowa
# wchodziły w to samo miejsce. Nazwy kluczy są nazwami zmiennych z .env.

declare -A ODP=()

wczytaj_plik_odpowiedzi() {
  local plik="$1"
  [ -f "${plik}" ] || przerwij "Nie ma pliku odpowiedzi: ${plik}"

  local linia klucz wartosc
  while IFS= read -r linia; do
    case "${linia}" in ''|'#'*) continue ;; esac
    klucz="${linia%%=*}"
    wartosc="${linia#*=}"
    # Cudzysłowy zdejmujemy, bo plik odpowiedzi bywa pisany ręcznie, a wartość
    # ze spacją bez nich nie przetrwa. Do .env trafi z powrotem w cudzysłowach.
    wartosc="${wartosc%\"}"; wartosc="${wartosc#\"}"
    ODP["${klucz}"]="${wartosc}"
  done <"${plik}"
}

# Pyta, chyba że odpowiedź już jest (plik odpowiedzi). Trzeci argument to
# wartość domyślna, czwarty — 1, gdy pusta odpowiedź jest dopuszczalna.
zapytaj() {
  local klucz="$1" pytanie="$2" domyslna="${3:-}" wolno_puste="${4:-0}" odpowiedz

  if [ -n "${ODP[${klucz}]:-}" ]; then
    return 0
  fi

  if [ ! -t 0 ]; then
    if [ -n "${domyslna}" ] || [ "${wolno_puste}" = "1" ]; then
      ODP["${klucz}"]="${domyslna}"
      return 0
    fi
    przerwij "Brak odpowiedzi na „${pytanie}” (${klucz}), a wejście nie jest terminalem. Uzupełnij plik odpowiedzi."
  fi

  while true; do
    if [ -n "${domyslna}" ]; then
      read -r -p "   ${pytanie} [${domyslna}]: " odpowiedz || true
      odpowiedz="${odpowiedz:-${domyslna}}"
    else
      read -r -p "   ${pytanie}: " odpowiedz || true
    fi

    if [ -n "${odpowiedz}" ] || [ "${wolno_puste}" = "1" ]; then
      ODP["${klucz}"]="${odpowiedz}"
      return 0
    fi

    zawiedz "To pytanie musi mieć odpowiedź."
  done
}

potwierdz() {
  local pytanie="$1" odpowiedz
  [ -t 0 ] || return 1
  read -r -p "   ${pytanie} [t/N]: " odpowiedz || true
  case "${odpowiedz}" in [tTyY]*) return 0 ;; *) return 1 ;; esac
}

# ─── Sekrety ─────────────────────────────────────────────────────────────────

losowy_sekret() { openssl rand -base64 33 | tr -d '/+=\n' | cut -c1-32; }

# ─── .env ────────────────────────────────────────────────────────────────────
#
# Budowany z `.env.example`, a nie pisany od zera, i to jest cała różnica.
# Lista zmiennych i ich objaśnienia żyją w tamtym pliku; instalator, który
# trzymałby własną kopię, rozjechałby się z nim przy pierwszej zmianie.
# Stąd też ostrzeżenie o zmiennej, której instalator nie zna: to sygnał, że
# ktoś dołożył zmienną i o instalatorze nie pomyślał.

ustaw_w_env() {
  local plik="$1" klucz="$2" wartosc="$3" cytat=''
  case "${wartosc}" in *' '*) cytat='"' ;; esac

  if grep -q "^${klucz}=" "${plik}"; then
    python3 - "${plik}" "${klucz}" "${cytat}${wartosc}${cytat}" <<'PY'
import sys
plik, klucz, wartosc = sys.argv[1], sys.argv[2], sys.argv[3]
linie = open(plik).read().split('\n')
for i, l in enumerate(linie):
    if l.startswith(klucz + '='):
        linie[i] = f'{klucz}={wartosc}'
        break
open(plik, 'w').write('\n'.join(linie))
PY
  else
    printf '%s=%s%s%s\n' "${klucz}" "${cytat}" "${wartosc}" "${cytat}" >>"${plik}"
  fi
}

sprawdz_pokrycie_env() {
  # Każda zmienna z .env.example musi być przez instalator pytana, generowana
  # albo świadomie zostawiona z wartością przykładową. Nieznana = ostrzeżenie.
  local klucz
  for klucz in $(grep -oE '^[A-Z_]+=' "${KORZEN}/.env.example" | tr -d '='); do
    case " ${PYTANE[*]} ${GENEROWANE[*]} ${ZOSTAWIONE[*]} " in
      *" ${klucz} "*) ;;
      *) ostrzez "Zmienna ${klucz} jest w .env.example, ale instalator jej nie zna — zostaje wartość przykładowa. Dopisz ją do instalatora." ;;
    esac
  done
}

PYTANE=(WS_PUBLIC_URL NGINX_BIND NGINX_PORT APP_ENV BACKEND_TARGET FRONTEND_TARGET
        MAILER_DSN MAIL_FROM MAIL_FROM_NAME MEMPALACE_VERSION WS_DEFAULT_SPACE_SLUG
        WS_DEFAULT_SPACE_NAME)
GENEROWANE=(POSTGRES_PASSWORD MEMPALACE_DB_PASSWORD WS_DB_PASSWORD
            MEMPALACE_MCP_HTTP_TOKEN APP_SECRET JWT_PASSPHRASE)
# Wartości z .env.example, które są dobre jak są: limity zasobów, model
# embeddingów, ustawienia wydajności. Zmienia je ten, kto wie, po co.
ZOSTAWIONE=(POSTGRES_DB EMBEDDING_MODEL EMBEDDING_IMAGE EMBEDDING_MEM_LIMIT
            EMBEDDING_CPUS POSTGRES_MEM_LIMIT MEMPALACE_MEM_LIMIT BACKEND_MEM_LIMIT
            FRONTEND_MEM_LIMIT WORKER_MEM_LIMIT EMBEDDING_MAX_BATCH_TOKENS
            EMBEDDING_MAX_CLIENT_BATCH EMBEDDING_TOKENIZATION_WORKERS
            MEMPALACE_ENTITY_LANGUAGES WS_DEFAULT_SPACE_ROLE WS_DEV_PORT)

# ─── Ślad instalacji ─────────────────────────────────────────────────────────
#
# Deinstalator ma umieć posprzątać także po instalacji PRZERWANEJ w połowie,
# więc ślad powstaje przed pierwszą zmianą, a nie po ostatniej. Plik jest
# jawnym opisem tego, co ten skrypt utworzył — nie stanem, na którym cokolwiek
# polega.

SLAD="${KORZEN}/var/instalacja.conf"

zapisz_slad() {
  mkdir -p "$(dirname "${SLAD}")"
  {
    printf 'droga=%s\n' "${DROGA}"
    printf 'katalog=%s\n' "${KORZEN}"
    # Nazwę projektu podaje Compose — `docker-compose.yml` ustawia `name:`,
    # więc katalog nazywa się inaczej. Wpisanie tu nazwy katalogu dałoby ślad
    # wskazujący na projekt, którego nie ma.
    printf 'projekt_compose=%s\n' "$(docker compose config --format json 2>/dev/null \
      | python3 -c 'import json,sys; print(json.load(sys.stdin)["name"])' 2>/dev/null \
      || basename "${KORZEN}" | tr '[:upper:]' '[:lower:]')"
    printf 'zaczeto=%s\n' "$(date '+%Y-%m-%d %H:%M:%S')"
    printf 'stan=%s\n' "$1"
  } >"${SLAD}"
}

# ─── Przebieg: Docker ────────────────────────────────────────────────────────

PLIK_DOSTEPU="${KORZEN}/backend/var/dostep-administratora.txt"

czekaj_na_zdrowie() {
  local usluga="$1" limit="${2:-300}" czekano=0 stan
  powiedz "Czekam, aż ${usluga} będzie zdrowa (do ${limit} s)…"

  while [ "${czekano}" -lt "${limit}" ]; do
    stan="$(docker compose ps --format '{{.Service}} {{.Health}}' 2>/dev/null | awk -v u="${usluga}" '$1==u {print $2}')"
    case "${stan}" in
      healthy) udalo "${usluga}: zdrowa"; return 0 ;;
      unhealthy) zawiedz "${usluga}: niezdrowa"; return 1 ;;
    esac
    sleep 5
    czekano=$((czekano + 5))
  done

  zawiedz "${usluga}: nie doczekałem się w ${limit} s. Zobacz: docker compose logs ${usluga}"
  return 1
}

w_backendzie() { docker compose exec -T backend "$@"; }

instaluj_docker() {
  naglowek "Podnoszę stos"
  if [ "${NA_SUCHO}" -eq 1 ]; then
    powiedz "(na sucho) pominięte: docker compose up -d --build"
  else
    docker compose up -d --build || przerwij "Nie udało się zbudować albo podnieść usług."
    # Postgres najpierw, bo bez niego migracje nie mają dokąd pójść. Pałac ma
    # start_period 120 s, więc pięć minut to minimum, nie zapas.
    czekaj_na_zdrowie postgres 180 || przerwij "Baza nie wstała."
    czekaj_na_zdrowie backend 300 || przerwij "Backend nie wstał."
  fi

  naglowek "Klucze JWT"
  if [ "${NA_SUCHO}" -eq 1 ]; then
    powiedz "(na sucho) pominięte: lexik:jwt:generate-keypair"
  elif w_backendzie test -f config/jwt/private.pem 2>/dev/null; then
    udalo "Klucze już są — zostawiam. Nadpisanie unieważniłoby wszystkie wydane tokeny."
  else
    w_backendzie php bin/console lexik:jwt:generate-keypair --no-interaction \
      || przerwij "Nie udało się wygenerować kluczy JWT."
    udalo "Wygenerowane."
  fi

  naglowek "Migracje bazy"
  if [ "${NA_SUCHO}" -eq 1 ]; then
    powiedz "(na sucho) pominięte: doctrine:migrations:migrate"
  else
    w_backendzie php bin/console doctrine:migrations:migrate --no-interaction \
      || przerwij "Migracje nie przeszły."
    udalo "Schemat aktualny."
  fi

  naglowek "Konto administratora"
  zaloz_administratora
}

zaloz_administratora() {
  local email="${ODP[ADMIN_EMAIL]}" nazwa="${ODP[ADMIN_NAME]:-}" wyjscie haslo

  if [ "${NA_SUCHO}" -eq 1 ]; then
    powiedz "(na sucho) pominięte: ws:user:create ${email} --admin"
    return 0
  fi

  # Konto może już istnieć — instalator uruchomiony drugi raz nie ma prawa
  # niczego z nim zrobić. Hasła też nie zmienia: po to jest ws:user:password,
  # świadomie wywoływane przez człowieka, a nie po cichu przez instalator.
  if w_backendzie php bin/console dbal:run-sql \
       "SELECT 1 FROM ws.users WHERE email = '${email//\'/\'\'}'" 2>/dev/null | grep -q '1'; then
    ostrzez "Konto ${email} już istnieje — nie ruszam go."
    ostrzez "Jeśli nie znasz hasła: docker compose exec backend php bin/console ws:user:password ${email}"
    return 0
  fi

  wyjscie="$(w_backendzie php bin/console ws:user:create "${email}" "${nazwa}" --admin 2>&1)" \
    || { printf '%s\n' "${wyjscie}"; przerwij "Nie udało się założyć konta administratora."; }

  haslo="$(printf '%s' "${wyjscie}" | awk '/Hasło:/{getline; gsub(/^[ \t]+|[ \t]+$/, ""); print; exit}')"
  [ -n "${haslo}" ] || przerwij "Konto powstało, ale nie umiem odczytać hasła z wyjścia polecenia. Ustaw je: ws:user:password ${email}"

  zapisz_dostep "${email}" "${haslo}"
  udalo "Konto ${email} założone."
}

zapisz_dostep() {
  local email="$1" haslo="$2"

  mkdir -p "$(dirname "${PLIK_DOSTEPU}")"
  # Prawa PRZED treścią: plik utworzony z domyślną maską i dopiero potem
  # zawężony jest przez chwilę czytelny dla wszystkich, a to wystarczy.
  umask 077
  : >"${PLIK_DOSTEPU}"
  chmod 600 "${PLIK_DOSTEPU}"

  cat >"${PLIK_DOSTEPU}" <<PLIK
# Konto administratora globalnego WS_Memory
# Utworzone $(date '+%Y-%m-%d %H:%M') przez scripts/instaluj.sh
#
# Ten plik leży w backend/var/, który jest ignorowany przez gita.
# NIE przenoś go nigdzie indziej w repozytorium.

adres     ${ODP[WS_PUBLIC_URL]:-http://127.0.0.1:${ODP[NGINX_PORT]:-8080}}
e-mail    ${email}
hasło     ${haslo}

# Hasło wygenerowane losowo. Zmień je w aplikacji, gdy tylko zechcesz —
# wtedy skasuj ten plik, bo przestanie być prawdziwy, a plik z nieaktualnym
# hasłem jest gorszy niż jego brak.
PLIK
}

# ─── Sprawdzenie po instalacji ───────────────────────────────────────────────
#
# Instalator, który kończy się słowem „gotowe" bez sprawdzenia, przenosi
# porażkę na pierwszego użytkownika — a najgroźniejsza awaria tego systemu jest
# CICHA: przy niedziałających embeddingach wyszukiwanie zwraca poprawną
# odpowiedź, tylko pustą (D-003). Dlatego ostatni test pyta o TREŚĆ odpowiedzi,
# a nie o kod HTTP.

NIEUDANE_SPRAWDZENIA=0

sprawdzenie() {
  local opis="$1"; shift
  if "$@" >/dev/null 2>&1; then
    udalo "${opis}"
  else
    zawiedz "${opis}"
    NIEUDANE_SPRAWDZENIA=$((NIEUDANE_SPRAWDZENIA + 1))
  fi
}

adres_lokalny() { printf 'http://127.0.0.1:%s' "${ODP[NGINX_PORT]:-8080}"; }

logowanie_dziala() {
  local email="${ODP[ADMIN_EMAIL]}" haslo
  haslo="$(awk '/^hasło/{print $2}' "${PLIK_DOSTEPU}" 2>/dev/null || true)"
  [ -n "${haslo}" ] || return 1

  curl -fsS -X POST "$(adres_lokalny)/api/login" \
    -H 'Content-Type: application/json' \
    -d "$(python3 -c 'import json,sys; print(json.dumps({"email": sys.argv[1], "password": sys.argv[2]}))' "${email}" "${haslo}")" \
    | grep -q '"token"'
}

palac_odpowiada() {
  w_backendzie php bin/console dbal:run-sql 'SELECT 1' >/dev/null 2>&1 \
    && curl -fsS "$(adres_lokalny)/api/health" | grep -q '"mempalace"'
}

sprawdz_po_instalacji() {
  naglowek "Sprawdzam, czy to naprawdę działa"

  if [ "${NA_SUCHO}" -eq 1 ]; then
    powiedz "(na sucho) pominięte."
    return 0
  fi

  sprawdzenie "Strona odpowiada" curl -fsS -o /dev/null "$(adres_lokalny)/"
  sprawdzenie "API zdrowia odpowiada" curl -fsS -o /dev/null "$(adres_lokalny)/api/health"
  sprawdzenie "Pałac odpowiada" palac_odpowiada
  sprawdzenie "Logowanie danymi z pliku dostępu działa" logowanie_dziala

  # Ten jest najważniejszy i trwa najdłużej: sprawdza, czy polskie zapytanie
  # znajduje polską treść. Bez niego instalacja może wyglądać na udaną
  # i po prostu nic nie znajdować.
  if [ -x "${KORZEN}/test/semantyka.sh" ]; then
    if "${KORZEN}/test/semantyka.sh" >/dev/null 2>&1; then
      udalo "Wyszukiwanie znaczeniem zwraca sensowny wynik"
    else
      zawiedz "Wyszukiwanie znaczeniem NIE działa — a to awaria cicha: aplikacja odpowiada 200 i nic nie znajduje."
      NIEUDANE_SPRAWDZENIA=$((NIEUDANE_SPRAWDZENIA + 1))
    fi
  else
    ostrzez "Nie ma test/semantyka.sh — pomijam najważniejsze sprawdzenie."
  fi
}

# ─── Przebieg główny ─────────────────────────────────────────────────────────

printf '%s\n' "${JASNY}WS_Memory — instalator${KONIEC}"
printf 'Katalog: %s\n' "${KORZEN}"
[ "${NA_SUCHO}" -eq 1 ] && ostrzez "NA SUCHO: nic nie zostanie zmienione."

[ -n "${PLIK_ODPOWIEDZI}" ] && { wczytaj_plik_odpowiedzi "${PLIK_ODPOWIEDZI}"; powiedz "Odpowiedzi z: ${PLIK_ODPOWIEDZI}"; }

# ─── Droga ───────────────────────────────────────────────────────────────────

if [ -z "${DROGA}" ]; then
  DROGA="${ODP[DROGA]:-}"
fi

if [ -z "${DROGA}" ]; then
  naglowek "Jak instalujemy"
  powiedz "1) docker — droga wspierana: wszystko w kontenerach, wersje przypięte"
  powiedz "2) system — sama aplikacja na Twoim Postgresie, PHP i nginxie"
  powiedz ""
  powiedz "Druga droga NIE zainstaluje Postgresa z pgvector ani serwera embeddingów."
  zapytaj DROGA "Wybierz drogę (docker/system)" "docker"
  DROGA="${ODP[DROGA]}"
fi

case "${DROGA}" in
  docker|system) ;;
  *) przerwij "Nieznana droga: ${DROGA}" ;;
esac

ODP[DROGA]="${DROGA}"

# ─── Wymagania ───────────────────────────────────────────────────────────────

naglowek "Sprawdzam wymagania"
sprawdz_wspolne
[ "${DROGA}" = 'docker' ] && sprawdz_docker
[ "${DROGA}" = 'system' ] && sprawdz_system

if [ "${DROGA}" = 'system' ]; then
  # Powiedziane, zanim ktokolwiek cokolwiek zainstaluje, i powiedziane wprost:
  # ta droga jest niedokończona (TODO-018 punkt 7). Skrypt, który zaczyna ją
  # i przerywa w połowie, zostawia stan, którego nikt nie umie opisać.
  zawiedz "Droga „system” jest na razie WYŁĄCZNIE sprawdzeniem wymagań."
  zawiedz "Instalacja jednostek systemd i konfiguracji nginxa jeszcze nie powstała (TODO-018 punkt 7)."
  zawiedz "Zainstaluj przez Dockera albo poczekaj — udawanie, że to działa, zostawiłoby stan nie do posprzątania."
fi

if [ "${#BRAKI[@]}" -gt 0 ]; then
  naglowek "Brakuje tego, bez czego nie ma sensu zaczynać"
  for b in "${BRAKI[@]}"; do zawiedz "${b}"; done
  printf '\nNic nie zostało zmienione.\n' >&2
  exit 3
fi

# Port sprawdzamy tu tylko przy samym sprawdzeniu — w pełnym przebiegu pytamy
# o niego niżej i sprawdzamy podany. Bez tego „wszystko na miejscu" byłoby
# nieprawdą na maszynie, gdzie coś już siedzi na 8080, a dowiedziałby się o tym
# dopiero ten, kto przeszedł całą rozmowę.
if [ "${TYLKO_SPRAWDZENIE}" -eq 1 ]; then
  port_do_sprawdzenia="${ODP[NGINX_PORT]:-8080}"
  sprawdz_porty "${port_do_sprawdzenia}"

  if [ "${#BRAKI[@]}" -gt 0 ]; then
    naglowek "Brakuje tego, bez czego nie ma sensu zaczynać"
    for b in "${BRAKI[@]}"; do zawiedz "${b}"; done
    printf '\nNic nie zostało zmienione.\n' >&2
    exit 3
  fi

  udalo "Wszystko na miejscu (port sprawdzony: ${port_do_sprawdzenia})."
  exit 0
fi

udalo "Wszystko na miejscu."
[ "${DROGA}" = 'system' ] && exit 3

# ─── Pytania ─────────────────────────────────────────────────────────────────

naglowek "Kilka pytań"
powiedz "Sekretów nie pytam — wygeneruję je sam."
powiedz ""

zapytaj NGINX_PORT "Port, pod którym aplikacja ma być widoczna" "8080"
sprawdz_porty "${ODP[NGINX_PORT]}"
if [ "${#BRAKI[@]}" -gt 0 ]; then
  for b in "${BRAKI[@]}"; do zawiedz "${b}"; done
  exit 3
fi

zapytaj NGINX_BIND "Adres, na którym nasłuchiwać (127.0.0.1 = tylko ta maszyna)" "127.0.0.1"
zapytaj WS_PUBLIC_URL "Publiczny adres instancji ze schematem (linki w mailach)" "http://127.0.0.1:${ODP[NGINX_PORT]}"

zapytaj TRYB "Tryb: prod (produkcja) czy dev (praca nad kodem)" "prod"
if [ "${ODP[TRYB]}" = 'prod' ]; then
  ODP[APP_ENV]='prod'; ODP[BACKEND_TARGET]='prod'; ODP[FRONTEND_TARGET]='prod'
else
  ODP[APP_ENV]='dev'; ODP[BACKEND_TARGET]='dev'; ODP[FRONTEND_TARGET]='dev'
fi

zapytaj ADMIN_EMAIL "Adres e-mail administratora (to będzie login)" ""
zapytaj ADMIN_NAME "Nazwa widoczna w interfejsie" "" 1

naglowek "Poczta wychodząca"
powiedz "Bez tego zaproszenia trzeba przekazywać ręcznie — instancja działa, ale nie wysyła nic."
zapytaj MAILER_DSN "DSN serwera poczty (Enter = na razie bez wysyłki)" "null://null"
if [ "${ODP[MAILER_DSN]}" != 'null://null' ]; then
  zapytaj MAIL_FROM "Adres nadawcy" ""
  zapytaj MAIL_FROM_NAME "Nazwa nadawcy" "Baza wiedzy"
else
  ODP[MAIL_FROM]="baza-wiedzy@localhost"
  ODP[MAIL_FROM_NAME]="Baza wiedzy"
  ostrzez "Poczta wyłączona. Włączysz ją później, wpisując MAILER_DSN w .env i restartując worker."
fi

naglowek "Wspólna przestrzeń"
powiedz "Każde nowe konto trafia do niej od razu — bez tego pierwszą rzeczą, jaką widzi nowa osoba, jest „nie należysz do żadnej przestrzeni”."
zapytaj WS_DEFAULT_SPACE_SLUG "Identyfikator wspólnej przestrzeni (pusty = wyłącz)" "wiedza" 1
zapytaj WS_DEFAULT_SPACE_NAME "Jej nazwa w interfejsie" "Baza wiedzy" 1

zapytaj MEMPALACE_VERSION "Wersja MemPalace" "3.9.0"

if [ -n "${ZAPISZ_ODPOWIEDZI}" ]; then
  # Bez sekretów — to jest plik do powtórzenia instalacji, nie kopia poświadczeń.
  {
    printf '# Odpowiedzi instalatora WS_Memory, %s\n' "$(date '+%Y-%m-%d %H:%M')"
    printf '# Sekretów tu nie ma: instalator generuje je za każdym razem od nowa.\n'
    for klucz in DROGA TRYB NGINX_PORT NGINX_BIND WS_PUBLIC_URL ADMIN_EMAIL ADMIN_NAME \
                 MAILER_DSN MAIL_FROM MAIL_FROM_NAME WS_DEFAULT_SPACE_SLUG \
                 WS_DEFAULT_SPACE_NAME MEMPALACE_VERSION; do
      printf '%s=%s\n' "${klucz}" "${ODP[${klucz}]:-}"
    done
  } >"${ZAPISZ_ODPOWIEDZI}"
  udalo "Odpowiedzi zapisane w ${ZAPISZ_ODPOWIEDZI}"
fi

# ─── .env ────────────────────────────────────────────────────────────────────

naglowek "Plik .env"

if [ -f "${KORZEN}/.env" ]; then
  ostrzez "Plik .env już istnieje — ta instancja była już instalowana."
  powiedz "Nadpisanie go zmieniłoby hasła do bazy, której dane już tam są, i instancja przestałaby wstawać."
  # Bez terminala nie ma kogo zapytać, a domyślną odpowiedzią na „czy nadpisać
  # konfigurację działającej instancji" musi być odmowa. `--zachowaj-env` jest
  # tą zgodą wyrażoną z góry — potrzebną, żeby dało się dokończyć instalację
  # przerwaną w połowie, bez człowieka przy klawiaturze.
  if [ "${ZACHOWAJ_ENV}" -eq 1 ]; then
    udalo "Zostawiam istniejący .env (--zachowaj-env)."
  elif [ "${NA_SUCHO}" -eq 0 ] && ! potwierdz "Zostawić istniejący .env i tylko dokończyć resztę?"; then
    przerwij "Nie ruszam .env. Dokończenie instalacji: --zachowaj-env. Od zera: ./scripts/odinstaluj.sh"
  else
    udalo "Zostawiam istniejący .env."
  fi
else
  if [ "${NA_SUCHO}" -eq 1 ]; then
    powiedz "(na sucho) pominięte: zapisanie .env"
  else
    zapisz_slad "w-trakcie"
    cp "${KORZEN}/.env.example" "${KORZEN}/.env"
    chmod 600 "${KORZEN}/.env"

    for klucz in "${GENEROWANE[@]}"; do
      ustaw_w_env "${KORZEN}/.env" "${klucz}" "$(losowy_sekret)"
    done

    for klucz in "${PYTANE[@]}"; do
      [ -n "${ODP[${klucz}]+jest}" ] && ustaw_w_env "${KORZEN}/.env" "${klucz}" "${ODP[${klucz}]}"
    done

    udalo "Zapisany, prawa 600, sekrety wygenerowane."
  fi
fi

sprawdz_pokrycie_env

# Zatrzymanie tutaj jest osobnym, sensownym zakończeniem, a nie furtką na testy:
# konfigurację przygotowuje się czasem gdzie indziej niż podnosi stos — w CI,
# w Ansible, na maszynie, która ma dostać obrazy z rejestru. Przy okazji jest to
# jedyny fragment instalatora, który da się sprawdzić bez stawiania siedmiu usług.
if [ "${TYLKO_KONFIGURACJA}" -eq 1 ]; then
  naglowek "Koniec (tylko konfiguracja)"
  powiedz "Plik .env gotowy. Stos podniesiesz sam: docker compose up -d --build"
  powiedz "Potem: migracje, klucze JWT i konto administratora — patrz docs/05-deployment.md"
  exit 0
fi

# ─── Instalacja ──────────────────────────────────────────────────────────────

instaluj_docker
[ "${NA_SUCHO}" -eq 0 ] && zapisz_slad "gotowe"

sprawdz_po_instalacji

# ─── Koniec ──────────────────────────────────────────────────────────────────

naglowek "Koniec"

if [ "${NA_SUCHO}" -eq 1 ]; then
  powiedz "Na sucho: nic nie zostało zmienione."
  exit 0
fi

if [ "${NIEUDANE_SPRAWDZENIA}" -gt 0 ]; then
  zawiedz "Instalacja zakończona, ale ${NIEUDANE_SPRAWDZENIA} sprawdzeń nie przeszło."
  zawiedz "Nie nazywam tego „gotowe” — zajrzyj wyżej i do: docker compose logs"
  exit 1
fi

printf '   %sInstancja działa.%s\n\n' "${ZIELONY}" "${KONIEC}"
powiedz "Adres:        $(adres_lokalny)"
[ "${ODP[WS_PUBLIC_URL]}" != "$(adres_lokalny)" ] && powiedz "Publicznie:   ${ODP[WS_PUBLIC_URL]}"
powiedz "Login:        ${ODP[ADMIN_EMAIL]}"
powiedz "Hasło:        w pliku ${PLIK_DOSTEPU} (prawa 600)"
printf '\n'
ostrzez "Hasło jest w tym pliku w postaci jawnej. Zmień je w aplikacji i skasuj plik."
