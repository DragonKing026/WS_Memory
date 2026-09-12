#!/usr/bin/env bash
# Wypełnia .env losowymi sekretami. Uruchom raz, po skopiowaniu .env.example.
set -euo pipefail
cd "$(dirname "$0")/.."

[ -f .env ] || { echo "Brak .env — najpierw: cp .env.example .env" >&2; exit 1; }

losowy() { openssl rand -base64 24 | tr -d '/+=' | cut -c1-32; }

for klucz in POSTGRES_PASSWORD MEMPALACE_DB_PASSWORD WS_DB_PASSWORD \
             MEMPALACE_MCP_HTTP_TOKEN APP_SECRET JWT_PASSPHRASE; do
  wartosc="$(losowy)"
  if grep -q "^${klucz}=" .env; then
    sed -i "s|^${klucz}=.*|${klucz}=${wartosc}|" .env
    echo "  ustawiono ${klucz}"
  fi
done
echo "Gotowe. Sekrety w .env — plik jest w .gitignore i nie trafi do repozytorium."
