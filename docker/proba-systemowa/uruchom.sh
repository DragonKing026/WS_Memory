#!/usr/bin/env bash
#
# Podnosi maszynę testową dla instalacji „w systemie" i wpuszcza do niej kod.
#
# Kontener z systemd wymaga `--privileged` i własnej przestrzeni cgroup — to
# jest maszyna testowa na własnym komputerze, nie usługa. Nie ma jej
# w `docker-compose.yml` właśnie po to, żeby nie dało się jej podnieść przez
# przypadek razem ze stosem.
#
# Repozytorium wjeżdża jako kopia, nie jako montowanie: instalacja systemowa
# zmienia prawa i tworzy pliki, a montowanie oznaczałoby, że robi to w Twoim
# katalogu roboczym.
#
# Użycie:
#   docker/proba-systemowa/uruchom.sh          # zbuduj, uruchom, wejdź
#   docker/proba-systemowa/uruchom.sh --stop   # zatrzymaj i usuń
#
# Polskie cudzysłowy w komunikatach są zamierzone.
# shellcheck disable=SC1111

set -euo pipefail

KORZEN="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
NAZWA=ws-memory-proba-systemowa
OBRAZ=ws-memory/proba-systemowa

if [ "${1:-}" = '--stop' ]; then
  docker rm -f "${NAZWA}" >/dev/null 2>&1 || true
  echo "Zatrzymane i usunięte."
  exit 0
fi

echo "Buduję obraz maszyny testowej…"
docker build -q -t "${OBRAZ}" -f "${KORZEN}/docker/proba-systemowa/Dockerfile" \
  "${KORZEN}/docker/proba-systemowa"

docker rm -f "${NAZWA}" >/dev/null 2>&1 || true

echo "Podnoszę maszynę z systemd…"
docker run -d --name "${NAZWA}" \
  --privileged --cgroupns=host \
  -v /sys/fs/cgroup:/sys/fs/cgroup:rw \
  --tmpfs /run --tmpfs /run/lock \
  "${OBRAZ}" >/dev/null

# Bez tego kolejne polecenia trafiają w system, który jeszcze się nie uruchomił,
# a `systemctl` odpowiada „Failed to connect to bus".
echo -n "Czekam na systemd"
for _ in $(seq 30); do
  if docker exec "${NAZWA}" systemctl is-system-running 2>/dev/null | grep -qE 'running|degraded'; then
    echo " — gotowe."
    break
  fi
  echo -n '.'
  sleep 2
done

echo "Wgrywam kod (kopia, nie montowanie)…"
docker exec "${NAZWA}" mkdir -p /opt/ws-memory
docker cp "${KORZEN}/." "${NAZWA}:/opt/ws-memory/"

cat <<INFO

Maszyna testowa stoi: ${NAZWA}

  docker exec -it ${NAZWA} bash
  cd /opt/ws-memory && ./scripts/instaluj.sh --droga=system --tylko-sprawdzenie

Zatrzymanie: docker/proba-systemowa/uruchom.sh --stop
INFO
