---
noteId: "29bab671aeb211f1997d030a3cd38ca7"
tags: [ws-memory, todo, backend, symfony, doctrine, api-platform]

---

# TODO-001 — Backend: fundament Symfony

**Utworzono:** 2026-09-12 16:05 · **Stan:** do zrobienia · **Zależności:** 000

## Powód

Potrzebny szkielet backendu, na którym staną wszystkie kolejne zadania:
aplikacja Symfony w kontenerze, połączenie z Postgresem, migracje, kolejka i
uwierzytelnianie tokenowe. Bez tego każde następne zadanie zaczynałoby się od
tej samej konfiguracji.

## Analiza

Backend jest **czystym API** (D-008) — nie instalujemy Twiga ani niczego, co
renderuje interfejs. Pokusa będzie, bo Symfony domyślnie proponuje szablony;
reguła nienaruszalna nr 9 mówi wprost, że to błąd architektoniczny.

Uwierzytelnianie: JWT z parą kluczy (token dostępowy krótki, odświeżający
długi). Agenci mają osobny mechanizm (zadanie 004) — nie mieszamy tokenów
ludzi z tokenami maszyn, bo mają różny czas życia i różne unieważnianie.

Schemat `ws` należy do Doctrine, schemat `palace` do MemPalace. Migracje
Doctrine **nie mogą dotykać** `palace` — konfigurujemy `schema_filter`.

## Rozwiązanie

1. `backend/` — Symfony 8.0 / PHP 8.4, bez pakietów szablonowych.
2. `docker/backend/Dockerfile` — php-fpm 8.4 z `pdo_pgsql`, `intl`, `opcache`;
   osobny etap dev z Xdebug.
3. Doctrine: połączenie do Postgresa, `schema_filter` ograniczony do `ws`,
   pierwsza migracja tworząca schemat.
4. API Platform 4.3 — `/api`, dokumentacja OpenAPI pod `/api/docs`.
5. JWT (para kluczy generowana do wolumenu, nie do repo) + endpoint
   odświeżania tokena.
6. Messenger z transportem Doctrine; usługa `worker` w Compose z tym samym
   obrazem i innym wejściem.
7. `GET /api/health` — sprawdza bazę i osiągalność `mempalace`.
8. PHPUnit z bazą testową; `make test` jako jedno wejście.

## Kryteria ukończenia

- `docker compose up -d` podnosi `backend` i `worker` jako `healthy`.
- `GET /api/health` zwraca `200` ze statusem bazy i MemPalace.
- `doctrine:migrations:migrate` przechodzi na czystej bazie.
- `doctrine:schema:validate` nie zgłasza rozbieżności ani nie widzi tabel
  MemPalace w schemacie `palace`.
- W `composer.json` nie ma `symfony/twig-bundle` ani innego pakietu
  renderującego interfejs.
- `make test` przechodzi (na razie jeden test zdrowia).
