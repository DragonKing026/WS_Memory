#!/bin/bash
# Inicjalizacja bazy — uruchamiana raz, przy pustym katalogu danych.
#
# Jedna baza, dwa schematy, dwie role (D-002):
#   palace → tabele MemPalace (backend pgvector)
#   ws     → dane aplikacji (Doctrine)
# Każda rola widzi domyślnie tylko swój schemat, więc pomyłka w kodzie nie
# sięgnie cudzych tabel. Dzięki jednej bazie backupem całego systemu jest
# jeden pg_dump.
set -euo pipefail

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<SQL
CREATE EXTENSION IF NOT EXISTS vector;

CREATE SCHEMA IF NOT EXISTS palace;
CREATE SCHEMA IF NOT EXISTS ws;

CREATE ROLE mempalace LOGIN PASSWORD '${MEMPALACE_DB_PASSWORD}';
CREATE ROLE ws_app    LOGIN PASSWORD '${WS_DB_PASSWORD}';

GRANT USAGE, CREATE ON SCHEMA palace TO mempalace;
GRANT USAGE, CREATE ON SCHEMA ws     TO ws_app;

-- Rozszerzenie vector mieszka w public; obie role muszą widzieć jego typy.
GRANT USAGE ON SCHEMA public TO mempalace, ws_app;

-- Domyślna ścieżka wyszukiwania: każda rola pracuje we własnym schemacie bez
-- kwalifikowania nazw, a public zostaje dla typu vector.
ALTER ROLE mempalace SET search_path = palace, public;
ALTER ROLE ws_app    SET search_path = ws, public;

-- Aplikacja czyta metadane pałaca (listowanie, statystyki), ale nigdy do
-- niego nie pisze — każdy zapis idzie przez MemPalace (D-004).
GRANT USAGE ON SCHEMA palace TO ws_app;
ALTER DEFAULT PRIVILEGES FOR ROLE mempalace IN SCHEMA palace
  GRANT SELECT ON TABLES TO ws_app;
SQL

echo "WS_Memory: schematy palace i ws utworzone, rozszerzenie vector aktywne"
