---
tags: [ws-memory, dokumentacja, backend, symfony, warstwy, api]
---

# Backend — co do czego służy

Stan: **działa** (2026-09-12). Zaimplementowane: zdrowie, zaproszenia, konta,
logowanie, przestrzenie i role, audyt. Brakuje: gateway MCP (TODO-004), wiki
(TODO-005), publikacja z lokalnych pałaców (TODO-012).

Ten dokument opisuje **każdy element backendu i powód jego istnienia**. Jeśli
nie wiesz, gdzie dopisać nową rzecz — zacznij tutaj.

## Warstwy i kierunek zależności

```
Presentation/    ← wejścia: HTTP i konsola. Tłumaczą żądanie na wywołanie.
Application/     ← przypadki użycia: „wystaw zaproszenie", „przyjmij je".
Domain/          ← reguły. BEZ Symfony, BEZ Doctrine, BEZ MemPalace.
Infrastructure/  ← implementacje portów: Doctrine, HTTP, MemPalace.
Entity/          ← model trwałości (Doctrine). Odwzorowanie tabel, nie reguły.
```

Zależności idą **wyłącznie do środka**. `Domain` nie importuje niczego z
pozostałych warstw — dzięki temu reguły uprawnień da się przetestować bez bazy
i bez kontenera, a testy negatywne chodzą przy każdym commicie w ułamku sekundy.

Sprawdzian: *gdyby jutro trzeba było wymienić Doctrine albo MemPalace, ile
plików w `Domain/` trzeba tknąć?* Odpowiedź ma brzmieć „zero".

**Dlaczego `Entity/` jest osobno, a nie w `Domain/`:** encje Doctrine noszą
atrybuty mapowania, czyli wiedzą o bazie. Trzymanie ich w `Domain/` zabrudziłoby
warstwę, która ma być czysta. Trzymanie osobnego modelu domenowego obok encji
oznaczałoby ręczne przepisywanie w obie strony — koszt nieproporcjonalny do
skali tego projektu. Kompromis: encje są **modelem trwałości**, a reguły
mieszkają w `Domain/`.

## Mapa plików

### `Domain/` — reguły, bez frameworka

| Plik | Rola |
|---|---|
| `Identity/Actor.php` | Kto wykonuje operację: człowiek albo token agenta. **Jeden typ dla obu**, bo REST i MCP wołają te same serwisy — gdyby tożsamość była modelowana dwa razy, obie powierzchnie kiedyś różniłyby się w uprawnieniach, a różnica ujawniłaby się jako wyciek, nie jako błąd. |
| `Space/SpaceId.php` | Identyfikator przestrzeni jako obiekt wartości. String da się pomylić z innym stringiem; ten typ nie. |
| `Space/SpaceRole.php` | Rola w przestrzeni: `reader` / `writer` / `admin`. Uporządkowana siłą, więc „co najmniej piszący" to porównanie, a nie lista przypadków, którą ktoś zapomni rozszerzyć przy czwartej roli. |
| `Space/SpaceMembershipRepository.php` | **Port**: skąd biorą się członkostwa. Zadeklarowany w domenie, zaimplementowany w infrastrukturze. |
| `Space/SpaceAccessResolver.php` | **Jedyne miejsce liczące uprawnienia.** Każdy odczyt i zapis przechodzi tędy. Nie ma cache — odebrana rola przestaje działać natychmiast, nie po wygaśnięciu czegokolwiek. |
| `Audit/AuditTrail.php` | **Port**: zapis, kto co zrobił. W domenie, bo audyt jest regułą tego systemu, nie wygodą infrastruktury (D-016). |
| `Health/*` | Port `HealthProbe` + `HealthChecker` składający raport. Monitorowanie kolejnej zależności to dodanie klasy, nie edycja kontrolera. |

### `Application/` — przypadki użycia

| Plik | Rola |
|---|---|
| `Invitation/IssueInvitation.php` | Wystawia zaproszenie. Token losowy, w bazie **wyłącznie skrót**; jawna wartość wraca raz i nigdzie nie jest zapisywana. |
| `Invitation/AcceptInvitation.php` | Zamienia zaproszenie w konto **razem z prywatną przestrzenią, w jednej transakcji**. Zapis bez wskazanej przestrzeni ląduje właśnie tam (reguła 6), więc konto bez niej wywracałoby pierwszy zapis agenta. |
| `Invitation/IssuedInvitation.php` | Obiekt wyniku z jawnym tokenem. **Nie jest usługą** — wykluczony z kontenera. |

### `Infrastructure/` — adaptery portów

| Plik | Rola |
|---|---|
| `Doctrine/DoctrineSpaceMembershipRepository.php` | Czyta członkostwa **przez DBAL, nie przez ORM**. To zapytanie leży na ścieżce uprawnień każdego żądania, a hydracja encji wstawiłaby identity map między odebranie roli a jego skutek — czyli dokładnie ten cache, którego resolver obiecuje nie mieć. |
| `Doctrine/DoctrineAuditTrail.php` | Zapisuje wpisy audytu. IP i przeglądarkę bierze z bieżącego żądania, nie z parametrów — gdyby były parametrem, część wywołań by o nich zapomniała, a wpis bez pochodzenia odpowiada na połowę pytania, po co istnieje. |
| `Doctrine/DatabaseHealthProbe.php` | Sonda: czy baza odpowiada. |
| `MemPalace/MemPalaceHealthProbe.php` | Sonda: czy pamięć odpowiada. Pyta `/healthz` z krótkim limitem czasu — zawieszony healthcheck jest gorszy od negatywnego. |
| `Security/LoginAuditSubscriber.php` | Audyt logowań. Wisi na zdarzeniach bezpieczeństwa, bo kontroler logowania **nigdy się nie wykonuje** — firewall odpowiada pierwszy. |

### `Presentation/` — wejścia

| Trasa / komenda | Plik | Uwaga |
|---|---|---|
| `GET /api/health` | `Api/HealthController.php` | Bez uwierzytelniania: monitoring nie ma tokena i mieć nie powinien. `200` gdy zdrowy, `503` gdy nie — Docker czyta kod, nie treść. |
| `POST /api/login` | `Api/LoginController.php` | **Ciało nigdy się nie wykonuje.** Trasa istnieje, bo Symfony musi rozwiązać `check_path`; odpowiada firewall `json_login`. |
| `GET /api/me` | `Api/MeController.php` | Wejście frontendu do modelu uprawnień. Listę przestrzeni bierze z `SpaceAccessResolver`, nie z własnego zapytania. |
| `GET /api/spaces`, `GET /api/spaces/{slug}` | `Api/SpaceController.php` | Przestrzeń poza uprawnieniami odpowiada **bajt w bajt** tak samo jak nieistniejąca. |
| `POST /api/spaces`, `POST /api/spaces/{slug}/members` | `Api/SpaceAdministrationController.php` | Tworzenie przestrzeni i nadawanie ról. Twórca od razu zostaje administratorem; prefiks `priv_` zarezerwowany; przestrzeni prywatnej nie da się udostępnić. |
| `POST /api/invitations/accept` | `Api/AcceptInvitationController.php` | Publiczna z konieczności — wołający nie ma jeszcze konta. Polityka haseł egzekwowana tutaj, nie w przeglądarce. |
| `ws:user:invite` | `Console/InviteUserCommand.php` | Jedyna droga do pierwszego konta. Wypisuje link, bo pierwsze zaproszenie powstaje zwykle przed konfiguracją poczty. |

### `Entity/` — model trwałości

| Encja | Uwagi konstrukcyjne |
|---|---|
| `User` | Konta się **dezaktywuje, nie usuwa** — rewizje i wpisy audytu wskazują autora, a historia bez autora przestaje być dowodem. `ROLE_USER` jest domyślna i nieprzechowywana. |
| `Space` | `Space::privateFor()` tworzy przestrzeń prywatną `priv_<uuid>`. `palace_namespace` niepuste = osobne tabele pgvector dla przestrzeni wrażliwych. |
| `SpaceMember` | **Klucz złożony** `(space, user)`: dwie role jednej osoby w jednej przestrzeni są niereprezentowalne, więc pytanie „która wygrywa" nie może paść. |
| `Invitation` | Tylko skrót tokena. `isUsable()` łączy „niewykorzystane" i „nieprzeterminowane" w jednym miejscu, żeby drugie wejście nie zapomniało o jednym z warunków. |
| `AuditLog` | Dopisywany, nigdy nie zmieniany. Aktor zapisany **zwykłym identyfikatorem, nie kluczem obcym** — dezaktywacja konta nie rusza zapisu tego, co zrobiło. |

## Jak przechodzi żądanie

### Logowanie

1. `POST /api/login` → firewall `json_login` przechwytuje, kontroler się nie wykonuje.
2. Provider `app_users` znajduje konto po adresie, hasło weryfikowane hasherem.
3. Sukces → `LoginSuccessEvent` → `LoginAuditSubscriber` zapisuje `user.login`
   i znacznik ostatniego logowania; Lexik zwraca token JWT.
4. Porażka → `LoginFailureEvent` → wpis `user.login_failed` **bez aktora**:
   mamy wtedy deklarowaną tożsamość, nie potwierdzoną, więc zapisanie jej
   pozwalałoby podrabiać wpisy audytu cudzym adresem.

Nieznane konto i złe hasło odpowiadają identycznie — inaczej formularz
logowania służyłby do sprawdzania, kto tu pracuje.

### Odczyt przestrzeni

1. Firewall `api` weryfikuje JWT i ładuje użytkownika **z bazy przy każdym żądaniu**.
2. Kontroler buduje `Actor` i pyta `SpaceAccessResolver`.
3. Resolver czyta role przez DBAL — bez cache, więc odebranie roli działa od razu.
4. **Uprawnienie sprawdzane przed istnieniem.** Odwrotna kolejność
   różnicowałaby czas odpowiedzi między „nie ma" a „nie wolno", a wolniejsza
   odpowiedź to też ujawnienie.

### Zaproszenie

`ws:user:invite` albo endpoint administracyjny → `IssueInvitation` (skrót w
bazie, jawny token raz na wyjściu) → osoba otwiera link →
`POST /api/invitations/accept` → walidacja hasła → `AcceptInvitation` tworzy
konto, prywatną przestrzeń i członkostwo w jednej transakcji → wpis
`invitation.accepted`.

## Uprawnienia od końca do końca

Cztery warstwy, każda pokryta testem negatywnym:

1. **Firewall** — bez ważnego JWT nie ma dostępu do `/api` poza zdrowiem,
   logowaniem, akceptacją zaproszenia i dokumentacją kontraktu.
2. **`SpaceAccessResolver`** — jedyne miejsce liczące role. Zakres tokena
   agenta **zawęża i nigdy nie poszerza** uprawnień właściciela.
3. **Kontroler** — sprawdza uprawnienie przed istnieniem i zwraca `404` tam,
   gdzie `403` zdradzałoby istnienie zasobu.
4. **Audyt** — każda zmiana stanu zostawia wpis z aktorem, IP i przeglądarką.

Administrator globalny **nie czyta cudzych przestrzeni po cichu** (D-016).
Może nadać sobie rolę — i to zostaje w dzienniku.

## Migracje

Pisane **ręcznie**, nie generowane: przy włączonym `schema_filter` generator
różnic wymaga DBAL `^4.5`, a stabilne jest 4.4.4. Mapowanie sprawdzamy przez
`doctrine:schema:validate --skip-sync`.

| Migracja | Zawartość |
|---|---|
| `Version20260912000001` | Kolejka Messengera w `ws`, z wyzwalaczem `LISTEN/NOTIFY`. |
| `Version20260912000002` | Konta, zaproszenia, przestrzenie, role, dziennik audytu. |

**Pułapka `schema_filter`** — opisana w `docs/05-deployment.md`. W skrócie: filtr
`~^ws\.~` odrzuca własne tabele, bo przy `search_path = ws` DBAL zwraca je bez
kwalifikacji schematem. Poprawny wzorzec **wyklucza** `palace`.

## Testy

| Plik | Co sprawdza |
|---|---|
| `Domain/Space/SpaceAccessResolverTest.php` | Reguły uprawnień **bez bazy** — najszybsze i najważniejsze. Pięć z dziewięciu przypadków to negatywne. |
| `Application/InvitationFlowTest.php` | Cały przepływ zaproszenia na realnej bazie: prywatna przestrzeń, token jednorazowy, wygasły, nieznany, brak jawnego tokena w bazie, wpis w audycie. |
| `Api/AuthenticationTest.php` | Logowanie, `/api/me`, identyczna odmowa dla złego hasła i nieznanego konta, audyt. |
| `Api/SpaceAccessTest.php` | `404` zamiast `403`, identyczność odpowiedzi, lista tylko własnych przestrzeni, natychmiastowy skutek odebrania roli. |
| `Api/SpaceAdministrationTest.php` | Kto **nie może**: piszący nie awansuje siebie, obcy dostaje `404`, przestrzeni prywatnej nie da się udostępnić. |

Uruchomienie: `make test` (przygotowuje bazę testową i uruchamia PHPUnit).

**Dlaczego testy negatywne są tu ważniejsze od pozytywnych:** błąd w
uprawnieniach jest cichy. Nic nie rzuca wyjątku, nic nie pojawia się w logu —
treść po prostu trafia do kogoś, kto nie powinien jej zobaczyć.

## Jak dodać nową rzecz

**Nowy endpoint:** klasa w `Presentation/Api/` z atrybutem `#[Route]`. Logika
idzie do `Application/`, nie do kontrolera. Uprawnienia **zawsze** przez
`SpaceAccessResolver`.

**Nową sondę zdrowia:** klasa implementująca `Domain\Health\HealthProbe`.
Kontenerowy tag dokłada ją automatycznie — kontrolera nie ruszasz.

**Nową regułę uprawnień:** wyłącznie w `SpaceAccessResolver`, z testem
negatywnym. Druga metoda liczenia uprawnień to druga metoda pomylenia się.

**Nową migrację:** ręcznie w `migrations/`, nazwa `VersionRRRRMMDDNNNNNN`.
Tylko schemat `ws` — do `palace` nie piszemy nigdy (D-004).

**Nową zależność:** wpierw decyzja `D-0xx` w `docs/06-decyzje.md`.

## Konfiguracja

| Plik | Co ustawia |
|---|---|
| `config/services.yaml` | Autowiring, tag sond zdrowia, adres MemPalace, publiczny adres. Blok `when@test` udostępnia trzy usługi testom. |
| `config/packages/doctrine.yaml` | Połączenie, `schema_filter` ukrywający `palace`, mapowanie encji. |
| `config/packages/security.yaml` | Firewalle: zdrowie bez zabezpieczeń, `json_login`, JWT dla `/api` i `/mcp`. W testach obniżony koszt hashowania. |
| `config/packages/messenger.yaml` | Kolejka w bazie, `auto_setup: false` — tabelę tworzy migracja. |
| `config/packages/api_platform.yaml` | Kontrakt pod `/api/docs.json`, **Swagger UI wyłączone** (wymaga Twiga, a backend nie renderuje interfejsu). |

## Znane ograniczenia

- **Brak odświeżania tokenów.** `gesdinet/jwt-refresh-token-bundle` nie
  obsługuje jeszcze Symfony 8 (wymaga `symfony/console ^7`). Do czasu wydania
  zgodnej wersji token wygasa i trzeba zalogować się ponownie.
- **Dezaktywacja konta nie odcina istniejącego tokena** — patrz `TODO/`.
- **Brak wysyłki maili z zaproszeniami** — link trzeba przekazać ręcznie.
