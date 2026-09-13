#!/usr/bin/env bash
#
# Ustalanie nazwy projektu Compose — jedna funkcja dla instalatora i deinstalatora.
#
# Wspólny plik, a nie kopia w każdym skrypcie, bo ta akurat logika ma już na
# koncie błąd: nazwa brana z katalogu nie zgadza się z `name: ws-memory`
# z `docker-compose.yml`, przez co filtr wolumenów nie znajdował żadnego,
# a deinstalator kończył słowem „odinstalowane", zostawiając bazę wiedzy.
# Druga kopia tej logiki to druga szansa na ten sam błąd — w skrypcie, który
# kasuje dane.
#
# Kolejność jest kolejnością samego Compose i była sprawdzona, nie założona:
#   1. `docker compose config` — uwzględnia wszystko, ale NIE DZIAŁA bez `.env`
#      (plik compose ma zmienne wymagane przez `${...:?}`), czyli akurat po
#      przerwanej instalacji;
#   2. `COMPOSE_PROJECT_NAME` — bije `name:` z pliku (sprawdzone);
#   3. `name:` z `docker-compose.yml`;
#   4. brak odpowiedzi — i wtedy pytający ma odmówić, a nie zgadywać.

# Wypisuje nazwę projektu na standardowe wyjście. Kod 1, gdy nie umie ustalić.
ustal_projekt_compose() {
  local korzen="$1" z_compose z_pliku

  z_compose="$(cd "${korzen}" && docker compose config --format json 2>/dev/null \
    | python3 -c 'import json,sys; print(json.load(sys.stdin)["name"])' 2>/dev/null || true)"
  if [ -n "${z_compose}" ]; then
    printf '%s' "${z_compose}"
    return 0
  fi

  if [ -n "${COMPOSE_PROJECT_NAME:-}" ]; then
    printf '%s' "${COMPOSE_PROJECT_NAME}"
    return 0
  fi

  z_pliku="$(sed -n 's/^name:[[:space:]]*//p' "${korzen}/docker-compose.yml" 2>/dev/null \
    | head -1 | tr -d '"'"'"' ' || true)"
  if [ -n "${z_pliku}" ]; then
    printf '%s' "${z_pliku}"
    return 0
  fi

  return 1
}

# Czy port jest zajęty przez kontener TEGO projektu.
#
# Bez tego rozróżnienia instalator nie umie dokończyć własnej przerwanej
# instalacji: port trzyma jego własny nginx z poprzedniego przebiegu, a kontrola
# portów melduje konflikt i każe podać inny. Sprawdzone przebiegiem — dokładnie
# tak się to zachowywało.
port_naszego_projektu() {
  local projekt="$1" port="$2"

  docker ps --filter "label=com.docker.compose.project=${projekt}" --format '{{.Ports}}' 2>/dev/null \
    | grep -q ":${port}->"
}
