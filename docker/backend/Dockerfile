# WS_Memory — backend (Symfony 8 / PHP 8.4).
#
# Czyste API: brak Twiga i czegokolwiek renderującego interfejs (D-008).
# Ten sam obraz obsługuje trzy role — php-fpm dla żądań HTTP, konsumenta
# kolejki (worker) i polecenia konsoli — bo różni je tylko punkt wejścia.

ARG PHP_VERSION=8.4

# --- baza ---------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm AS baza

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libicu-dev libzip-dev libsodium-dev \
        # cgi-fcgi: jedyny sensowny sposób, by healthcheck Dockera odpytał
        # php-fpm. Ten mówi po FastCGI, nie po HTTP, więc curl tu nie pomoże.
        libfcgi-bin \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl opcache zip sodium \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Ping php-fpm — używany przez healthcheck.
RUN { \
        echo '[www]'; \
        echo 'ping.path = /ping'; \
        echo 'ping.response = pong'; \
    } > /usr/local/etc/php-fpm.d/zz-ping.conf

WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1

# --- deweloperski -------------------------------------------------------------
FROM baza AS dev

# Xdebug tylko tutaj: w obrazie produkcyjnym spowalnia każde żądanie nawet
# wtedy, gdy nikt się nie podpina.
RUN pecl install xdebug && docker-php-ext-enable xdebug \
    && { \
        echo 'xdebug.mode=off'; \
        echo 'xdebug.start_with_request=trigger'; \
        echo 'xdebug.client_host=host.docker.internal'; \
    } > /usr/local/etc/php/conf.d/zz-xdebug.ini

COPY --from=baza /usr/local/etc/php/php.ini-development /usr/local/etc/php/php.ini

# Źródła montowane wolumenem — obraz dev nie zawiera kodu, żeby zmiana pliku
# na hoście była widoczna od razu, bez przebudowy.

# --- produkcyjny --------------------------------------------------------------
FROM baza AS prod

COPY --from=baza /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini
RUN { \
        echo 'opcache.preload=/app/var/cache/prod/App_KernelProdContainer.preload.php'; \
        echo 'opcache.preload_user=www-data'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.max_accelerated_files=20000'; \
    } > /usr/local/etc/php/conf.d/zz-opcache.ini

COPY --chown=www-data:www-data backend/ /app/
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts \
    && composer dump-env prod \
    && php bin/console cache:warmup --env=prod \
    && chown -R www-data:www-data /app/var

# Instrukcje dla agentów AI — jedno źródło w plugin/shared (D-013), wystawiane
# przez gateway jako zasoby MCP. Kontekst budowania to korzeń repozytorium, więc
# katalog wchodzi tu wprost; w obrazie dev ta sama ścieżka jest wolumenem z hosta.
# Kopia, nie wolumen: obraz produkcyjny ma być samowystarczalny, a instrukcja
# wczytana z katalogu, którego ktoś zapomniał podmontować, byłaby pustym zasobem.
COPY plugin/shared/ /opt/ws-memory/instrukcje/
ENV WS_INSTRUCTIONS_DIR=/opt/ws-memory/instrukcje

USER www-data
