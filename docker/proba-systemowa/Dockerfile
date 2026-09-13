# Maszyna testowa dla instalacji „w systemie" (TODO-018 punkt 7).
#
# Droga systemowa dotyka systemd, nginxa i użytkowników systemowych — czyli
# rzeczy, których nie da się sprawdzić na maszynie roboczej bez zmieniania jej
# na trwałe. Bez celu testowego jedynym wyjściem byłoby napisanie kilkuset linii
# kodu instalującego usługi i **nieuruchomienie go ani razu**, a to dokładnie
# ten rodzaj skryptu, który zawodzi w połowie i zostawia stan nie do opisania.
#
# Ten obraz jest maszyną, na której ta droga ma się instalować: Debian 13
# z systemd jako PID 1, PHP 8.4, Postgresem z pgvector, nginxem i Node'em.
# Wszystko z pakietów dystrybucji, bo taka jest przesłanka tej drogi — instalator
# ma korzystać z tego, co już jest, a nie budować własnego świata.
#
# Czego tu NIE MA i nie będzie: serwera embeddingów. Model waży 2,3 GB, a jego
# obecność w obrazie testowym oznaczałaby sprawdzanie instalatora na maszynie
# lepiej wyposażonej niż typowy serwer. Instalator ma go WYKRYĆ i powiedzieć,
# czego brakuje — i to zachowanie też trzeba sprawdzić.
#
# Użycie: docker/proba-systemowa/uruchom.sh
FROM debian:13

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
        systemd systemd-sysv \
        php8.4-fpm php8.4-cli php8.4-pgsql php8.4-intl php8.4-mbstring \
        php8.4-xml php8.4-curl php8.4-zip \
        postgresql-17 postgresql-17-pgvector \
        nginx nodejs npm composer \
        ca-certificates curl git iproute2 openssl python3 sudo \
    && rm -rf /var/lib/apt/lists/*

# W kontenerze te jednostki nie mają czego obsługiwać, a próbują i psują start.
# Maskowanie jest tańsze niż tłumaczenie później, dlaczego `systemctl status`
# świeci na czerwono na rzeczach niezwiązanych z instalacją.
RUN systemctl mask \
        systemd-udevd.service systemd-udev-trigger.service \
        systemd-modules-load.service sys-kernel-config.mount \
        sys-kernel-debug.mount sys-kernel-tracing.mount \
        systemd-journald-audit.socket

# systemd kończy pracę na SIGRTMIN+3, nie na SIGTERM. Bez tego `docker stop`
# czeka dziesięć sekund i zabija kontener — ten sam problem, co z MemPalace
# i SIGINT-em (patrz docker-compose.yml).
STOPSIGNAL SIGRTMIN+3

CMD ["/sbin/init"]
