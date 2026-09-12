---
noteId: "29bab672aeb211f1997d030a3cd38ca7"
tags: [ws-memory, todo, backend, uprawnienia, konta, audyt]

---

# TODO-002 — Backend: konta, zaproszenia, przestrzenie, role, audyt

**Utworzono:** 2026-09-12 16:05 · **Stan:** do zrobienia · **Zależności:** 001

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
