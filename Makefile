# WS_Memory — jedno wejście do najczęstszych czynności.
#
# Wszystko dzieje się w kontenerach: nie zakładamy, że ktokolwiek ma lokalnie
# PHP 8.4 z właściwym zestawem rozszerzeń.

SHELL := /bin/bash
COMPOSE := docker compose
BAZA_TESTOWA := ws_memory_test

.PHONY: pomoc start stop test test-semantyka sprawdz-dokumentacje migracje konsola logi

pomoc:  ## Lista poleceń
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | sed 's/:.*##/ —/' | sort

start:  ## Podnieś stos
	$(COMPOSE) up -d

stop:  ## Zatrzymaj stos (dane zostają)
	$(COMPOSE) down

migracje:  ## Wykonaj migracje bazy
	$(COMPOSE) exec -T backend php bin/console doctrine:migrations:migrate --no-interaction

test: baza-testowa  ## Testy backendu (PHPUnit)
	$(COMPOSE) exec -T backend php vendor/bin/phpunit

test-semantyka:  ## Dowód, że polskie zapytanie znajduje polską treść
	./test/semantyka.sh

sprawdz-dokumentacje:  ## Spójność wersji polskiej i angielskiej dokumentacji
	@./scripts/sprawdz-dokumentacje.py

# Baza testowa powstaje rolą nadrzędną, bo ws_app celowo nie ma prawa
# tworzyć baz ani schematów — to samo ograniczenie, które chroni schemat
# palace przed zapisem z aplikacji.
baza-testowa:
	@$(COMPOSE) exec -T postgres psql -U postgres -tc \
		"SELECT 1 FROM pg_database WHERE datname='$(BAZA_TESTOWA)'" | grep -q 1 \
		|| $(COMPOSE) exec -T postgres createdb -U postgres -O postgres $(BAZA_TESTOWA)
	@$(COMPOSE) exec -T postgres psql -U postgres -d $(BAZA_TESTOWA) -q -c \
		"CREATE SCHEMA IF NOT EXISTS ws AUTHORIZATION ws_app; \
		 GRANT USAGE ON SCHEMA public TO ws_app;"
	@$(COMPOSE) exec -T backend php bin/console doctrine:migrations:migrate \
		--no-interaction --env=test 2>&1 | tail -2

konsola:  ## Powłoka w kontenerze backendu
	$(COMPOSE) exec backend bash

logi:  ## Podgląd logów
	$(COMPOSE) logs -f --tail=50
