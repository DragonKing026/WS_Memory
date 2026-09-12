#!/usr/bin/env bash
# Czeka na zależności, zanim odda sterowanie serwerowi.
#
# Powód: MemPalace przy pierwszym zapisie sonduje wymiar wektora, pytając
# serwer embeddingów. Jeśli ten jeszcze nie wstał, sondowanie się nie uda,
# a pałac zapamięta błędny stan. Zdrowotność sprawdzamy tutaj, bo obraz
# embeddingów nie zawiera narzędzi, którymi mógłby to zrobić sam.
set -euo pipefail

czekaj_na() {
  local nazwa="$1" url="$2" prob=0 limit="${3:-120}"
  printf 'czekam na %s ' "$nazwa"
  until curl -sSf --max-time 3 "$url" >/dev/null 2>&1; do
    prob=$((prob + 1))
    if [ "$prob" -ge "$limit" ]; then
      printf '\nBŁĄD: %s nie odpowiedziało po %s próbach (%s)\n' "$nazwa" "$limit" "$url" >&2
      exit 1
    fi
    printf '.'
    sleep 2
  done
  printf ' gotowe\n'
}

if [ -n "${EMBEDDINGS_HEALTH_URL:-}" ]; then
  czekaj_na "serwer embeddingów" "$EMBEDDINGS_HEALTH_URL"
fi

mkdir -p "${MEMPALACE_PALACE_PATH:-/data/palace}"
exec "$@"
