# MemPalace jako sidecar WS_Memory (D-001).
#
# Obraz celowo minimalny: ta usługa NIE MIELI niczego (D-012) — obsługuje
# wyszukiwanie semantyczne i zapis publikowanych szuflad. Mielenie dzieje się
# na maszynach użytkowników.
#
# Wersja jest przypięta świadomie: aktualizacja MemPalace to osobna operacja
# z backupem i testem semantyki (docs/05-deployment.md).

FROM python:3.14-slim

ARG MEMPALACE_VERSION=3.9.0

# curl — healthcheck, oczekiwanie na zależności i diagnostyka z wnętrza sieci
# Compose (żadna usługa nie ma portu na hoście, więc to jedyne wejście).
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

# psycopg — backend pgvector importuje go leniwie przy pierwszym połączeniu
# (mempalace/backends/pgvector.py). Bez niego usługa wstaje i pada dopiero
# przy pierwszym zapisie, co jest gorsze niż błąd przy budowaniu obrazu.
RUN pip install --no-cache-dir \
        "mempalace==${MEMPALACE_VERSION}" \
        "psycopg[binary]"

ENV HOME=/data \
    MEMPALACE_PALACE_PATH=/data/palace \
    PYTHONUNBUFFERED=1

RUN groupadd --gid 1000 mempalace \
    && useradd --uid 1000 --gid 1000 --home-dir /data --create-home mempalace

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER mempalace
WORKDIR /data
VOLUME ["/data"]

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["mempalace", "serve", "--host", "0.0.0.0", "--port", "8765"]
