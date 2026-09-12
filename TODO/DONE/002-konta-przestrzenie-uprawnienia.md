---
noteId: "29bab672aeb211f1997d030a3cd38ca7"
tags: [ws-memory, todo, backend, uprawnienia, konta, audyt]

---

# TODO-002 — Backend: konta, zaproszenia, przestrzenie, role, audyt

**Utworzono:** 2026-09-12 16:05 · **Stan:** ✅ **UKOŃCZONE 2026-09-12 21:55** · **Zależności:** 001

## Powód

To jest luka, którą WS_Memory w ogóle powstaje, żeby wypełnić: MemPalace
uwierzytelnia **jednym wspólnym tokenem**, więc nie wie, kto zapisał i kto ma
prawo czytać. Zanim podłączymy cokolwiek do pałaca, musi istnieć tożsamość
i model uprawnień.

## Analiza

Model z `docs/02-model-danych.md`: `users`, `invitations`, `spaces`,
`space_members`, `audit_log`. Rejestracja **tylko z zaproszenia** — otwarta
rejestracja w bazie wiedzy firmy nie ma sensu.

Kluczowa decyzja implementacyjna: **rozwiązywanie uprawnień musi być jednym
serwisem** (`SpaceAccessResolver`), z którego korzystają i REST, i MCP
(D-008). Dwie kopie tej logiki to gwarantowany rozjazd, a rozjazd w
uprawnieniach to wyciek.

Prywatna przestrzeń tworzona automatycznie przy akceptacji zaproszenia —
inaczej pierwszy zapis agenta nie miałby gdzie trafić (reguła nr 6).

Uwaga na kolejność: audyt musi istnieć **przed** pierwszym endpointem
zmieniającym stan, nie po. Dopisywanie audytu później zawsze pozostawia
nieobjęte ścieżki.

## Rozwiązanie

1. Encje: `User`, `Invitation`, `Space`, `SpaceMember`, `AuditLog` + migracja.
2. `SpaceAccessResolver` — jedyne miejsce liczące uprawnienia:
   `dozwolone_przestrzenie(użytkownik)`, `rola_w_przestrzeni(u, s)`,
   `może_pisać(u, s)`. Pokryty testami jednostkowymi, w tym negatywnymi.
3. Zaproszenia: `bin/console ws:user:invite <email> [--admin]`, wysyłka maila,
   endpoint akceptacji (ustawienie hasła), token jednorazowy z wygaśnięciem.
4. Automatyczne utworzenie prywatnej przestrzeni `priv_<id>` przy akceptacji.
5. Endpointy: logowanie, odświeżenie tokena, `GET /api/me` (z listą
   przestrzeni i rolami), CRUD przestrzeni i członków (tylko `ROLE_ADMIN`
   albo `admin` przestrzeni).
6. Audyt jako nasłuch na zdarzeniach Doctrine + jawne wpisy przy logowaniu i
   zmianach uprawnień; `actor`, `ip`, `user_agent`, `target`.
7. Polityka haseł: minimum 12 znaków, sprawdzenie przeciw liście
   najpopularniejszych (`NotCompromisedPassword`).

## Kryteria ukończenia

- Zaproszenie → akceptacja → logowanie → `GET /api/me` zwraca przestrzenie.
- Użytkownik bez roli w przestrzeni dostaje `404` (nie `403`) przy próbie jej
  odczytu — nie ujawniamy istnienia.
- Prywatna przestrzeń powstaje automatycznie i jest widoczna tylko dla
  właściciela.
- Odebranie roli natychmiast odcina dostęp (test).
- Każda zmiana stanu ma wpis w `audit_log` z aktorem i IP.
- Testy negatywne uprawnień przechodzą — to one są tu najważniejsze.


---

## Co zostało zrobione

**Ukończono:** 2026-09-12 21:55

### Kryteria ukończenia — weryfikacja

| Kryterium | Wynik |
|---|---|
| zaproszenie → akceptacja → logowanie → `/api/me` z przestrzeniami | ✅ sprawdzone na żywej instancji, nie tylko w testach |
| brak roli → `404`, nie `403` | ✅ **odpowiedzi bajt w bajt identyczne** z nieistniejącą przestrzenią |
| prywatna przestrzeń powstaje automatycznie, widoczna tylko dla właściciela | ✅ test negatywny na realnej bazie |
| odebranie roli natychmiast odcina dostęp | ✅ test jednostkowy i przez API |
| każda zmiana stanu ma wpis w audycie z aktorem i IP | ✅ `invitation.issued`, `invitation.accepted`, `user.login`, `user.login_failed`, `space.created`, `space.member_added`, `space.read` |
| testy negatywne przechodzą | ✅ **44 testy, 84 asercje**; z tego 12 negatywnych |

### Co powstało

**Domena (bez frameworka):** `Actor` — jeden typ tożsamości dla ludzi i
agentów, bo REST i MCP wołają te same serwisy. `SpaceId`, `SpaceRole`
uporządkowana siłą, port `SpaceMembershipRepository`, `SpaceAccessResolver`
jako **jedyne miejsce liczące uprawnienia**, port `AuditTrail`.

**Aplikacja:** `IssueInvitation`, `AcceptInvitation`.

**Infrastruktura:** repozytorium członkostw przez DBAL, dziennik audytu,
`ActiveAccountChecker`, nasłuch audytu logowań.

**Wejścia:** `/api/login`, `/api/me`, `/api/spaces`, `/api/spaces/{slug}`,
`/api/spaces/{slug}/members`, `/api/invitations/accept`, `ws:user:invite`.

**Migracja** `Version20260912000002` — pięć tabel.

### Decyzje podjęte po drodze

**D-016 — administrator globalny nie czyta cudzych przestrzeni po cichu.**
Może nadać sobie rolę, ale to zostaje w audycie. Ma znaczenie praktyczne:
w prywatnych przestrzeniach lądują domyślnie transkrypty rozmów z agentami
(D-014). Gdyby administrator czytał je bez śladu, obietnica prywatności byłaby
pusta, ludzie wyłączyliby wysyłkę i baza straciłaby to, po co powstaje.

**D-017 — odświeżania tokenów nie piszemy własnoręcznie.** Bundle nie obsługuje
jeszcze Symfony 8. Rotacja tokenów odświeżających to kod bezpieczeństwa
z nieoczywistymi pułapkami; pisanie go, żeby zaoszczędzić jedno logowanie
dziennie, to zła wymiana. Czas życia tokena: 8 godzin.

### Luka znaleziona przy pisaniu dokumentacji

Opisując backend, zauważyłem, że **dezaktywacja konta nie odcinała dostępu** —
JWT pozostaje ważny kryptograficznie aż do wygaśnięcia, więc zwolniona osoba
czytałaby bazę jeszcze przez cały czas życia ostatniego tokena. Naprawione
`ActiveAccountCheckerem`, który sprawdza konto **przy każdym żądaniu**, nie
tylko przy logowaniu. To argument za pisaniem dokumentacji: opis wymusił
prześledzenie ścieżki, której testy nie pokrywały.

### Cztery potknięcia warte zapamiętania

1. **Filtr schematu Doctrine odrzucał własne tabele.** Wzorzec `~^ws\.~` nie
   pasuje, bo przy `search_path = ws` DBAL zwraca nazwy bez kwalifikacji.
   Doctrine nie widział własnej tabeli migracji i próbował ją tworzyć ponownie.
   Poprawny wzorzec **wyklucza** `palace`. Opisane w `docs/05`.
2. **Usługi używane tylko przez testy są usuwane z kontenera** jako nieużywane.
   Hurtowe `public: true` na `App\` to zła odpowiedź — zamienia obiekty
   wartości w usługi, których nie da się autowirować.
3. **`JWT_PASSPHRASE` wylądowało w commitowanym `backend/.env`** (przepis Flex).
   Klucz prywatny był ignorowany, więc samo hasło nie dawało dostępu, ale
   wzorzec usunięty: sekret idzie z compose.
4. **Identity map Doctrine mylił test dezaktywacji** — encja z `setUp` była
   wciąż aktywna w pamięci. To artefakt testu, nie błąd produkcyjny.

### Czego nie zrobiono

- **Brak wysyłki maili z zaproszeniami** — link trzeba przekazać ręcznie.
  Wymaga skonfigurowanego `MAILER_DSN` i szablonu; osobne, drobne zadanie.
- **Brak endpointów usuwania członków i archiwizacji przestrzeni** — dojdą
  razem z ekranami administracyjnymi w `TODO-008`, gdzie będzie widać,
  czego interfejs faktycznie potrzebuje.
