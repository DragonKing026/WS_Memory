---
noteId: "29bab671aeb211f1997d030a3cd38ca7"
tags: [ws-memory, todo, backend, symfony, doctrine, api-platform]

---

# TODO-001 — Backend: fundament Symfony

**Utworzono:** 2026-09-12 16:05 · **Stan:** ✅ **UKOŃCZONE 2026-09-12 19:45** · **Zależności:** 000

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


---

## Co zostało zrobione

**Ukończono:** 2026-09-12 19:45

### Kryteria ukończenia — weryfikacja

| Kryterium | Wynik |
|---|---|
| `backend` i `worker` jako `healthy` | ✅ wszystkie sześć usług `healthy` |
| `GET /api/health` zwraca 200 ze statusem zależności | ✅ `{"status":"healthy","database":{"available":true},"mempalace":{"available":true}}` |
| `doctrine:migrations:migrate` na czystej bazie | ✅ 9 zapytań, kolejka w schemacie `ws` |
| `doctrine:schema:validate` bez rozbieżności | ⚠️ **częściowo** — patrz niżej |
| brak Twiga w `composer.json` | ✅ zero pakietów renderujących interfejs |
| `make test` przechodzi | ✅ 3 testy, 8 asercji, PHP 8.4.25 |

### Co powstało

- `backend/` — Symfony 8.0 na PHP 8.4, API Platform 4.3, Doctrine ORM 3.6,
  Messenger, LexikJWT. **Bez Twiga**: Swagger UI wyłączone, kontrakt wystawiony
  maszynowo pod `/api/docs.json`.
- `docker/backend/Dockerfile` — trzystopniowy (`baza` → `dev` → `prod`),
  php-fpm 8.4 z `pdo_pgsql`, `intl`, `opcache`, `sodium`; Xdebug tylko w `dev`.
- `docker/nginx/default.conf` — **jedyne wejście**: `/api` i `/mcp` na backend,
  `/` zarezerwowane dla frontendu. Jeden origin, więc zero CORS-a.
- `backend/migrations/Version20260912000001.php` — kolejka Messengera w `ws`,
  z wyzwalaczem `LISTEN/NOTIFY`, żeby konsument budził się natychmiast zamiast
  odpytywać bazę w kółko.
- `Makefile` — `make start`, `make test`, `make migracje`, `make test-semantyka`.

### Architektura: warstwy i porty od pierwszej klasy

Na wniosek z tej sesji kod jest ułożony w warstwy od razu, a nie „później,
jak urośnie": `Domain/` (bez frameworka) → `Application/` → `Infrastructure/`
→ `Presentation/`.

Endpoint zdrowia jest tego pierwszym przykładem i celowo nie jest jednym
kontrolerem z dwoma `try`: `HealthProbe` to **port**, `DatabaseHealthProbe`
i `MemPalaceHealthProbe` to **adaptery** zbierane po tagu, a `HealthChecker`
składa z nich raport. Skutek praktyczny: monitorowanie kolejnej zależności
(serwer embeddingów, poczta, frontend) to **dodanie klasy**, nie edycja
kontrolera. Ten sam układ obsłuży potem narzędzia MCP, gdzie dojdzie łańcuch
dekoratorów: uprawnienia → audyt → limit tempa.

### Pięć rzeczy, które wyszły dopiero w działaniu

**1. `doctrine:schema:validate` i `migrations:diff` są niedostępne.**
DoctrineBundle 3 wymaga do porównywania schematu API `Schema::edit()` z DBAL
**^4.5**, a najnowsze stabilne wydanie to 4.4.4 (4.5 istnieje wyłącznie jako
gałąź rozwojowa). Sprawdzone: błąd występuje **także bez** `schema_filter`,
więc nie jest naszą konfiguracją. Co zrobiliśmy zamiast tego:
- `doctrine:schema:validate --skip-sync` → `[OK] The mapping files are correct`;
- migracje piszemy ręcznie, co i tak jest jawniejsze;
- `schema_filter` zostaje w konfiguracji, bo zadziała, gdy DBAL 4.5 wyjdzie.

Sprawdzone też, że problem jest realny: rola `ws_app` **widzi** tabele w
schemacie `palace` (`information_schema` zwraca je), więc bez filtra generator
różnic zaproponowałby kiedyś ich usunięcie.

**2. `monolog-bundle` nie obsługuje jeszcze Symfony 8** przy jawnym pinowaniu
wersji — wszedł natomiast jako zależność przechodnia, więc logowanie działa.

**3. Postgres: `localhost` w kontenerze to najpierw IPv6.** Healthcheck nginxa
dostawał odmowę połączenia, bo `listen 80` wiąże wyłącznie IPv4. Dodane
`listen [::]:80` i sprawdzanie po `127.0.0.1`.

**4. Symfony cache'uje skompilowany kontener w zamontowanym wolumenie.**
Poprawka błędnej konfiguracji Doctrine nie działała, dopóki nie usunięto
`backend/var/cache` — kontener czytał starą wersję i zgłaszał nieistniejący
już błąd.

**5. Healthcheck workera nie może używać `pgrep`** — obraz PHP nie ma `procps`.
Zamiast dokładać pakiet, sprawdzamy `/proc/1/cmdline`: konsument jest procesem
numer 1 kontenera, więc to pytanie dokładniejsze, nie tylko tańsze.

### Decyzje językowe podjęte przy okazji

Ujednolicono konwencję: **w kodzie wszystko po angielsku**, łącznie z
komentarzami i PHPDoc (tak jak w głównej aplikacji Symfony zespołu). Pierwsza wersja kontrolera miała
polskie nazwy — przepisana, zanim urosło. Dokumentacja ma być **dwujęzyczna**,
z polskim jako wersją wiodącą; angielskie odpowiedniki to `TODO-013`.

### Czego nie zrobiono

- **JWT nie ma jeszcze endpointów** — bundle zainstalowany i skonfigurowany,
  ale logowanie powstanie razem z kontami użytkowników w `TODO-002`. Klucze
  generujemy przy tamtym zadaniu, żeby nie leżały nieużywane.
- **Brak testów jednostkowych warstwy Domain** — jest tam na razie tylko
  logika zdrowia, pokryta testem funkcjonalnym. Przy pierwszej regule
  uprawnień (`TODO-002`) dochodzą testy jednostkowe, w tym negatywne.
