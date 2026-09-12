# WS_Memory

Wspólna baza wiedzy i dokumentacja Web Systems — **jedna dla ludzi i dla
modeli AI**. Człowiek loguje się przez przeglądarkę i pisze dokumentację;
agent AI czyta tę samą wiedzę przez MCP i sam do niej dopisuje. Jedno źródło
prawdy, bez eksportów i synchronizacji.

Silnikiem pamięci jest [MemPalace](https://github.com/MemPalace/mempalace)
użyty jako zależność. WS_Memory dokłada tożsamość, uprawnienia, interfejs dla
ludzi i deployment zespołowy.

**Backend (Symfony 8, czyste API) i frontend (Vue 3 + Vite) są rozdzielone** —
backend działa niezależnie i wystawia dwie powierzchnie nad tą samą logiką:
REST `/api` dla ludzi i MCP `/mcp` dla agentów.

## Status

**Etap projektowania zakończony, implementacja nierozpoczęta.**
Projekt zatwierdzony 2026-09-12. Zadania czekają w `TODO/`.

## Co to daje

- **Dla zespołu** — wiki z wersjonowaniem, diffem i cofaniem zmian; logowanie
  kontem firmowym; podział na przestrzenie (projekt / klient / dział) z rolami.
- **Dla agentów AI** — serwer MCP z firmowymi narzędziami: szukanie w bazie,
  zapisywanie ustaleń, pisanie dokumentacji, graf wiedzy. Plus plugin do
  Claude Code z hookami, skillami i podagentami.
- **Dla administratora** — jeden `docker compose up`, jeden `pg_dump` jako
  pełny backup, audyt każdego zapisu i odczytu.

## Szybki start

> Dostępne po ukończeniu zadania `TODO/000-szkielet-i-dowod-dzialania.md`.

```bash
cp .env.example .env        # ustaw hasła, domenę, sekret JWT
docker compose up -d
docker compose exec backend bin/console doctrine:migrations:migrate
docker compose exec backend bin/console ws:user:invite twoj@email.pl --admin
```

## Dokumentacja

| Plik | Zawartość |
|---|---|
| [AGENTS.md](AGENTS.md) | **Zacznij tutaj.** Kontrakt dla ludzi i agentów: reguły, stack, sposób pracy |
| [docs/01-architektura.md](docs/01-architektura.md) | Usługi, przepływy danych, granica bezpieczeństwa |
| [docs/02-model-danych.md](docs/02-model-danych.md) | Schemat `ws`, mapowanie przestrzeni na pałac |
| [docs/03-mcp-gateway.md](docs/03-mcp-gateway.md) | Narzędzia MCP i egzekwowanie uprawnień |
| [docs/04-plugin.md](docs/04-plugin.md) | Plugin WS_Memory: hooki, skille, podagenci |
| [docs/05-deployment.md](docs/05-deployment.md) | Docker, TLS, backup, aktualizacje |
| [docs/06-decyzje.md](docs/06-decyzje.md) | Decyzje techniczne z uzasadnieniem |
| [docs/07-frontend.md](docs/07-frontend.md) | Konwencje frontendu Vue, ekrany, zasady |
| [CHANGELOG.md](CHANGELOG.md) | Historia zmian z datami i godzinami |
| [TODO/](TODO/) | Zadania; ukończone w `TODO/DONE/` |

## Licencja

Własność Web Systems. Do użytku wewnętrznego.
