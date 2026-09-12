---
tags: [ws-memory, przeglad, onboarding]
---

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

**Backend czyta i zapisuje pamięć.** Projekt zatwierdzony 2026-09-12;
ukończone `TODO-000` (baza, embeddingi, pamięć + dowód polskiej semantyki),
`TODO-001` (backend jako czyste API), `TODO-002` (konta, zaproszenia,
przestrzenie i role), `TODO-003` (dostęp do pamięci z twardym filtrem
przestrzeni), `TODO-004` (gateway MCP i tokeny agentów), `TODO-013`
(dokumentacja dwujęzyczna) i `TODO-014` (CI).

**Agent AI może się już podłączyć**: `ws:agent:token` wypisuje gotowe
`claude mcp add`, a siedem narzędzi `ws_*` czyta i zapisuje wspólną bazę
z twardym filtrem przestrzeni. Brakuje wiki i frontendu. Kolejne zadania
w `TODO/`.

## Co to daje

- **Dla zespołu** — wiki z wersjonowaniem, diffem i cofaniem zmian; logowanie
  kontem firmowym; podział na przestrzenie (projekt / klient / dział) z rolami.
- **Dla agentów AI** — serwer MCP z firmowymi narzędziami: szukanie w bazie,
  zapisywanie ustaleń, pisanie dokumentacji, graf wiedzy. Plus plugin do
  Claude Code z hookami, skillami i podagentami.
- **Baza wypełnia się sama** — wtyczka pociąga MemPalace jako zależność, więc
  każdy ma lokalny pałac i mieli u siebie (`mempalace init`, `mempalace mine`).
  Wynik domyślnie jedzie na serwer: skrzydła zmapowane trafiają do przestrzeni
  zespołowych, reszta do Twojej prywatnej. Nic nie jest widoczne dla zespołu
  bez mapowania, a wysyłkę można wyłączyć jednym przełącznikiem.
- **Dla administratora** — jeden `docker compose up`, jeden `pg_dump` jako
  pełny backup, audyt każdego zapisu i odczytu.

## Szybki start

```bash
cp .env.example .env
./docker/wygeneruj-sekrety.sh   # losowe hasła i tokeny
make start                      # pierwszy start ~3 min: pobranie modelu embeddingów
make migracje
```

Sprawdzenie, czy fundament działa:

```bash
make test-semantyka   # polskie zapytanie musi znaleźć polską treść
make test             # testy backendu
curl http://127.0.0.1:8080/api/health
```

Pełny opis: [README.docker.md](README.docker.md).

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
| [docs/08-backend.md](docs/08-backend.md) | **Backend: co do czego służy** — mapa plików, przepływy, jak dodać nową rzecz |
| [docs/09-ci.md](docs/09-ci.md) | Ciągła integracja: co kiedy chodzi, co robić przy czerwonym przebiegu |
| [README.docker.md](README.docker.md) | Uruchomienie stosu, co gdzie jest, sprzątanie |
| [docs/en/](docs/en/) | Angielskie odpowiedniki (polski jest wersją wiodącą) |
| [SECURITY.md](SECURITY.md) | Jak zgłosić podatność i co w tym projekcie nią jest |
| [CHANGELOG.md](CHANGELOG.md) | Historia zmian z datami i godzinami |
| [TODO/](TODO/) | Zadania; ukończone w `TODO/DONE/` |

## Licencja

Własność Web Systems. Do użytku wewnętrznego.
