---
tags: [ws-memory, spec, projektowanie, decyzje, architektura]
---

# WS_Memory — spec projektowy

**Data:** 2026-09-12 · **Stan:** zatwierdzony · **Autor ustaleń:** Artur Ograbek
**Etap:** projektowanie zakończone, implementacja nierozpoczęta

Ten dokument utrwala **decyzje i ich uzasadnienia** z etapu projektowania.
Bieżący opis działania systemu żyje w `docs/01`–`docs/07`; gdy się rozejdą,
to `docs/` jest aktualne, a ten plik zostaje jako zapis, dlaczego tak wyszło.

> **Zapis historyczny — nie aktualizujemy go.** Spec zamyka się na decyzji
> D-009. Po nim zapadły D-010…D-015, które zmieniły trzy rzeczy opisane niżej:
> mielenie odbywa się **wyłącznie lokalnie** (D-012), a nie na serwerze;
> wysyłka na serwer jest **domyślna** (D-014); lokalny pałac jest **pierwotny**,
> serwer trzyma kopię (D-015). Aktualny stan: `docs/06-decyzje.md`.

## 1. Problem

Web Systems nie ma wspólnej bazy wiedzy. Wiedza żyje w głowach, w kodzie, w
rozmowach z agentami AI i w plikach na dyskach. Agent AI zaczyna każdą sesję
od zera, a nowa osoba w zespole pyta ludzi o rzeczy, które ktoś już kiedyś
ustalił.

Potrzebna jest **jedna baza wiedzy dla ludzi i dla modeli**: człowiek pisze i
czyta przez przeglądarkę, agent czyta i zapisuje przez MCP, i jedno widzi
drugie bez eksportów.

## 2. Zakres

**W zakresie:** serwer MCP z tożsamością i uprawnieniami · aplikacja
internetowa z logowaniem i wiki z wersjonowaniem · plugin do Claude Code
(narzędzia, instrukcje, ustawienia, agenci) · deployment w Dockerze.

**Poza zakresem (na teraz):** SSO, 2FA, integracja ze Slackiem,
wielojęzyczność interfejsu, aplikacja mobilna, publiczny dostęp do wybranych
dokumentów.

## 3. Co daje MemPalace, a co dokładamy

MemPalace 3.7.0 jest **zależnością**, nie inspiracją. Daje magazyn wektorowy,
wyszukiwanie semantyczne i leksykalne, 36 narzędzi MCP, miner (kod, PDF/DOCX,
transkrypty), graf wiedzy, dziennik i hooki sesji.

Rozpoznanie na kodzie zainstalowanej wersji ustaliło cztery fakty, które
wyznaczyły zakres WS_Memory:

1. **`mempalace serve` uwierzytelnia jednym wspólnym tokenem** — brak
   tożsamości per użytkownik, brak atrybucji, brak uprawnień. To jest główna
   luka, którą wypełniamy.
2. **Backend pgvector jest pełnoprawny** (HNSW, hybryda BM25+wektory,
   izolacja przez namespace) i bezpieczny przy wielu pisarzach.
3. **Mechanizm hub-forward działa tylko w obrębie jednej maszyny** (wykrywa hub
   przez plik w katalogu pałaca) — nie nadaje się do pracy zdalnej zespołu.
4. **Domyślny model embeddingów `minilm` jest trenowany tylko na angielskim** —
   dyskwalifikuje go dla bazy pisanej po polsku.

Dokładamy: tożsamość i atrybucję · przestrzenie z rolami · interfejs dla ludzi
z wersjonowaniem · kurowany zestaw narzędzi MCP jako granicę uprawnień ·
plugin firmowy · deployment zespołowy z backupem i audytem.

## 4. Trzy klasy wiedzy

Rozróżnienie fundamentalne — pomylenie ich prowadzi do złych decyzji.

1. **Pamięć surowa** (główny wolumen, automatyczna): transkrypty, dziennik,
   graf wiedzy, mining repozytoriów. Agent zapisuje swobodnie. Nie wersjonujemy.
2. **Ustalenia i notatki**: agent zapisuje wprost, oznaczone jako AI.
3. **Dokumentacja kanoniczna (wiki)**: rewizje, diff, rollback. **Agent też
   pisze wprost**; dostaje status „autor: AI" i flagę weryfikacji przez
   człowieka — znacznik zaufania, **nie brama**.

Uzasadnienie braku bramy: to AI wytworzy większość zapisów (hooki mielą
transkrypty automatycznie). Bramka zatwierdzania byłaby wąskim gardłem i
zostałaby obchodzona. Ochroną jest możliwość cofnięcia, nie blokada zapisu.

## 5. Architektura

Siedem usług w Dockerze; jedyne wejście z zewnątrz to `nginx`. Backend
(Symfony 8, czyste API) wystawia REST `/api` dla frontendu Vue i MCP `/mcp`
dla agentów — **nad wspólnymi serwisami domenowymi**, więc reguła uprawnień
istnieje w jednym miejscu. `mempalace` to jedyny komponent umiejący szukać
semantycznie i minować. `embeddings` liczy wektory dla całego systemu.
`postgres` (18 + pgvector) trzyma pałac i dane aplikacji w dwóch schematach.

Szczegóły: `docs/01-architektura.md`, `docs/02-model-danych.md`,
`docs/03-mcp-gateway.md`, `docs/07-frontend.md`.

## 6. Decyzje

| Nr | Decyzja | Kluczowy powód |
|---|---|---|
| D-001 | Symfony 8 jako backend, MemPalace jako sidecar | kompetencja zespołu; aktualizacja MemPalace nie dotyka naszego kodu |
| D-002 | PostgreSQL 18 + pgvector zamiast MariaDB | polski stemming w FTS, pgvector, JSONB, transakcyjny DDL |
| D-003 | centralny serwer embeddingów, `BAAI/bge-m3` | `minilm` jest angielski; `e5` wymaga prefiksów, których MemPalace nie doda |
| D-004 | Postgres źródłem prawdy wiki, pałac warstwą wyszukiwania | wersjonowanie to zadanie bazy relacyjnej; ACL w SQL, nie na wynikach |
| D-005 | agent zapisuje bez bramki | AI wytworzy większość zapisów; bramka byłaby obchodzona |
| D-006 | zamknięta sieć, transkrypty przez HTTPS | alternatywa wymagała Postgresa w internecie |
| D-007 | kurowany zestaw narzędzi MCP | granica narzędzi jest granicą uprawnień |
| D-008 | rozdzielenie backendu i frontendu | backend musi działać niezależnie; wzorzec z nowszego projektu z frontendem Vue |
| D-009 | CodeMirror 6, nie WYSIWYG | dokumenty krążą między ludźmi i AI; każdy obieg przez WYSIWYG gubi treść |

Pełne uzasadnienia i odrzucone alternatywy: `docs/06-decyzje.md`.

## 7. Reguły bezpieczeństwa

1. Agent nigdy nie dostaje tokena MemPalace ani DSN-u.
2. Żadne narzędzie MCP nie ma parametru „autor" — tożsamość z tokena.
3. Nie odpytujemy pałaca bez filtra przestrzeni; filtrowanie wyników po
   pobraniu jest wyciekiem, nie uprawnieniem.
4. Token agenta nigdy nie ma więcej uprawnień niż właściciel.
5. Agent nie weryfikuje własnych wpisów.
6. Zapis bez przestrzeni → prywatna przestrzeń właściciela tokena.
7. Brak uprawnień do przestrzeni zwraca **pusty wynik**, nie komunikat o
   braku dostępu (komunikat sam jest wyciekiem).

## 8. Kryteria ukończenia projektu

- Człowiek loguje się z zaproszenia, tworzy dokument, edytuje go, widzi diff
  dwóch rewizji i cofa zmianę.
- Agent przez plugin znajduje ten dokument **polskim zapytaniem innym słowem
  niż użyte w treści** i dopisuje własny dokument, który człowiek widzi
  natychmiast z oznaczeniem „autor: AI".
- Agent użytkownika bez roli w przestrzeni nie widzi jej treści **ani jej
  istnienia** — pokryte testem negatywnym.
- Transkrypt sesji trafia automatycznie do prywatnej przestrzeni autora.
- `pg_dump` i odtworzenie na czystej maszynie przywraca cały system.
- `docker compose up -d` na świeżym serwerze stawia działającą instalację.

## 9. Ryzyka

| Ryzyko | Obsługa |
|---|---|
| zmiana modelu embeddingów unieważnia wektory | decyzja podjęta przed pierwszym zapisem; test semantyczny w CI |
| MemPalace zmienia API MCP między wersjami | czarna skrzynka za granicą HTTP; test integracyjny po każdej aktualizacji |
| błąd w filtrze `wing` = wyciek między przestrzeniami | testy negatywne; namespace pgvector dla przestrzeni wrażliwych |
| AI zaśmieca bazę wpisami niskiej jakości | `ws-archiwista` wykrywa duplikaty; flaga weryfikacji; rollback |
| transkrypty zawierają treści prywatne | domyślnie prywatna przestrzeń autora; wyłącznik w hooku |
| wolumen wiedzy przerasta jedną maszynę | `embeddings` przenośne na GPU; `worker` skalowalny poziomo |
