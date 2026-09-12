#!/usr/bin/env bash
#
# Test integracyjny: czy polskie zapytanie znajduje polską treść?
#
# To jedyny test, który uzasadnia całą warstwę embeddingów. Domyślny model
# MemPalace (minilm) jest trenowany wyłącznie na angielskim — przeszedłby zapis
# i wyszukiwanie bez błędu, tylko nie znalazłby treści opisanej innymi słowami.
# Ten test to wykrywa, bo szuka frazy bez wspólnych słów z zapisaną treścią.
#
# Użycie:  ./test/semantyka.sh
# Wynik:   kod 0 = fundament działa, kod 1 = nie działa (z podanym powodem)

set -euo pipefail
cd "$(dirname "$0")/.."

TRESC="Umowa najmu lokalu wymaga aneksu przy zmianie stawki czynszu"
ZAPYTANIE="zmiana opłaty za wynajem — jakie dokumenty"
SZUKANA_FRAZA="aneksu przy zmianie stawki czynszu"
WING="test-semantyka"
ROOM="documentation"

czerwony() { printf '\033[31m%s\033[0m\n' "$1"; }
zielony()  { printf '\033[32m%s\033[0m\n' "$1"; }
krok()     { printf '\n\033[1m→ %s\033[0m\n' "$1"; }
niepowodzenie() { czerwony "NIE PRZESZEDŁ: $1"; exit 1; }

if [ -f .env ]; then
  set -a; . ./.env; set +a
fi
TOKEN="${MEMPALACE_MCP_HTTP_TOKEN:-}"
[ -n "$TOKEN" ] || niepowodzenie "brak MEMPALACE_MCP_HTTP_TOKEN — skopiuj .env.example do .env"

# Buduje obiekt JSON z par „klucz wartość". Przez pythona, bo treść jest po
# polsku i zawiera znaki, które w ręcznie sklejanym JSON-ie dają niepoprawne
# żądanie.
argumenty_json() {
  python3 -c '
import json, sys
pary = sys.argv[1:]
obiekt = {}
for klucz, wartosc in zip(pary[::2], pary[1::2]):
    obiekt[klucz] = int(wartosc) if wartosc.isdigit() else wartosc
print(json.dumps(obiekt))
' "$@"
}

# Wywołanie narzędzia MCP z wnętrza sieci Compose. Z hosta się nie da i tak ma
# być: żadna usługa poza nginxem nie wystawia portu (D-006).
mcp() {
  local narzedzie="$1" argumenty="$2"
  docker compose exec -T mempalace curl -sS --max-time 120 \
    -X POST "http://localhost:8765/mcp" \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer ${TOKEN}" \
    -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"${narzedzie}\",\"arguments\":${argumenty}}}"
}

sprawdz() { printf '%s' "$1" | ./test/sprawdz_odpowiedz.py "$2"; }

# ---------------------------------------------------------------- 1. żywotność
krok "1/4 Czy usługa pamięci odpowiada?"
KOD=$(docker compose exec -T mempalace curl -sS --max-time 10 -o /dev/null \
        -w '%{http_code}' http://localhost:8765/healthz 2>/dev/null || echo "brak")
[ "$KOD" = "200" ] || niepowodzenie "mempalace nie odpowiada na /healthz (kod: $KOD) — uruchom: docker compose up -d"
zielony "   usługa odpowiada"

# ------------------------------------------------------------------- 2. zapis
krok "2/4 Zapis polskiej treści do pałaca"
ODP_ZAPIS=$(mcp mempalace_add_drawer "$(argumenty_json \
  wing "$WING" room "$ROOM" content "$TRESC" added_by test-semantyka)") \
  || niepowodzenie "zapis nie powiódł się — usługa nie odpowiedziała"

WYNIK_ZAPISU=$(sprawdz "$ODP_ZAPIS" "drawer")
case "$WYNIK_ZAPISU" in
  BŁĄD:*) niepowodzenie "$WYNIK_ZAPISU" ;;
esac
zielony "   treść zapisana"

# ------------------------------------------------ 3. wyszukanie innymi słowami
krok "3/4 Szukanie frazą bez wspólnych słów z treścią"
echo "   zapisano: „${TRESC}\""
echo "   szukam:   „${ZAPYTANIE}\""
ODP_SZUKANIE=$(mcp mempalace_search "$(argumenty_json \
  query "$ZAPYTANIE" wing "$WING" limit 5)") \
  || niepowodzenie "wyszukiwanie nie powiodło się"

# --------------------------------------------------------------- 4. weryfikacja
krok "4/4 Czy zapisana treść jest w wynikach?"
ZNALEZIONO=$(sprawdz "$ODP_SZUKANIE" "$SZUKANA_FRAZA")
case "$ZNALEZIONO" in
  TAK) ;;
  BŁĄD:*) niepowodzenie "$ZNALEZIONO" ;;
  NIE) niepowodzenie "polskie zapytanie NIE znalazło polskiej treści.
   Model embeddingów nie rozumie polskiego — sprawdź, czy
   MEMPALACE_EMBEDDING_MODEL=openai-compat i czy usługa embeddings serwuje
   model wielojęzyczny (BAAI/bge-m3), a nie domyślny minilm." ;;
  *) niepowodzenie "nieoczekiwany wynik sprawdzenia: $ZNALEZIONO" ;;
esac
zielony "   znaleziona"

# Dowód, że trafienie jest semantyczne, a nie leksykalne: przy zerowym wyniku
# BM25 wyszukiwarka nie miała się o co zaczepić słowami — zadziałało znaczenie.
PODOBIENSTWO=$(printf '%s' "$ODP_SZUKANIE" | python3 -c '
import json, sys
odp = json.load(sys.stdin)
tresc = json.loads(odp["result"]["content"][0]["text"])
w = tresc["results"][0]
print("   podobieństwo {}, dopasowanie słów (BM25) {}".format(
    w["similarity"], w["bm25_score"]))
' 2>/dev/null || true)
[ -n "$PODOBIENSTWO" ] && echo "$PODOBIENSTWO"

printf '\n'
zielony "PRZESZEDŁ — polskie zapytanie innymi słowami znajduje polską treść."
