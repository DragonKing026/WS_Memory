---
noteId: "86dee9c0aeb711f1997d030a3cd38ca7"
tags: [ws-memory, todo, hybryda, mempalace, publikacja, lustro]

---

# TODO-012 — Mostek: lokalny pałac → wspólna baza (hybryda)

**Utworzono:** 2026-09-12 16:39 · **Stan:** 🔵 **W TOKU — punkty 1–4, 7, 10 i 11 z 12** (2026-09-13 22:32) · **Zależności:** 004, 009 · **Przejmuje zakres anulowanego** TODO-010

## Powód

To jest **jedyna droga**, którą wiedza wchodzi do wspólnej bazy poza pisaniem
w wiki (D-010 + D-012), i **działa domyślnie, bez udziału użytkownika**
(D-014). Serwer nie mieli niczego; każdy ma lokalny pałac, mieli u siebie,
a wynik jedzie na serwer sam.

Dzięki temu kod i rozmowy nie opuszczają laptopa, a nikt nie czeka na
administratora, żeby dodać źródło.

## Analiza

Ustalenia z kodu MemPalace 3.7.0, na których stoi ten mostek:

- **Replikacji pałac↔pałac nie ma.** `logstream sync` synchronizuje zdarzenia
  koordynacyjne i artefakty, nie szuflady (`logsync.py` nie wspomina o
  szufladach ani raz). `mempalace sync` to sprzątanie po usuniętych plikach.
  Mostek trzeba zbudować samemu — i dobrze, bo chcemy, żeby przechodził przez
  nasze uprawnienia.
- **Materiał do budowy jest**: `mempalace_list_drawers` (paginacja, filtr
  skrzydła i pokoju, zakres daty `since`/`before`) plus `mempalace_get_drawer`
  (pełna treść) na lokalnym serwerze stdio.
- **`replica.json`** daje stabilny identyfikator kopii pałaca — w kodzie
  opisany jako „nazwa siedziska". To klucz do rozpoznawania, skąd przyszła
  szuflada, i do idempotencji.

Trzy pułapki, które trzeba obsłużyć:

1. **Duplikaty przy powtórnej publikacji.** Rozwiązanie: unikalna para
   `(source_replica, source_drawer_id)` w `memory_entries` — druga publikacja
   aktualizuje wiersz, nie tworzy drugiego.
2. **Wysyłka jest domyślna, więc dzieje się bez nadzoru.** Ochroną nie jest
   pytanie przed każdą wysyłką (to zabiłoby sens domyślności), tylko **reguła
   lądowania**: bez mapowania treść idzie do prywatnej przestrzeni właściciela,
   a mapowanie na przestrzeń zespołową wymaga jednorazowego potwierdzenia.
   Do tego wykluczenia tematów, dziennik partii i wycofanie.
3. **Sekrety w lokalnym pałacu.** Lokalny `mine` mógł zgarnąć plik, którego nie
   powinno tam być. Filtr sekretów musi działać **po obu stronach** — klient nie
   wysyła, serwer i tak sprawdza. Zaufanie do klienta byłoby tu błędem.
   Od D-014 filtr leży na **każdej** ścieżce, nie tylko na świadomie
   uruchomionej — jego testy są krytyczne.
4. **Serwer bywa niedostępny.** Lokalny pałac jest pierwotny (D-015), więc
   zapis nie może na niego czekać ani zawodzić z jego powodu. Potrzebna
   **lokalna kolejka wyjściowa**: niewysłane szuflady czekają ze znacznikiem i
   dopinają się przy następnej okazji. Ponowna wysyłka jest bezpieczna dzięki
   odsiewowi, który i tak budujemy.
5. **Powtórzenia między osobami.** Trzy osoby mielące to samo repozytorium
   przyślą tę samą treść trzy razy. Odsiew po `content_hash` w obrębie
   przestrzeni docelowej plus propozycja mapowania na przestrzeń zespołową,
   gdy nazwa skrzydła jej odpowiada.

Czego **nie** robimy: nie dajemy lokalnemu MemPalace dostępu do centralnej bazy
przez `MEMPALACE_PGVECTOR_DSN`. Byłoby prostsze, ale ominęłoby token, role i
audyt — odrzucone w D-010.

## Rozwiązanie

- [x] **1.** Encje `Mirror`, `PublishBatch`, `PublishSettings` (`auto_publish` domyślnie
      `true`) + rozszerzenie `memory_entries` (`source_replica`,
      `source_drawer_id`, `publish_batch_id`, `content_hash`) z unikalnością na
      parze źródłowej i indeksem `(space_id, content_hash)` pod odsiew powtórzeń.
- [x] **2.** `POST /api/publish` — przyjmuje partię szuflad: treść, skrzydło i pokój
      źródłowy, znaczniki czasu, identyfikator repliki i szuflady. Sprawdza rolę
      `writer` w przestrzeni docelowej, przepuszcza przez filtr sekretów,
      przelicza embeddingi po stronie serwera, zapisuje z autorem.
      Tryb `preview=true` zwraca raport bez zapisu.
- [x] **3.** `POST /api/publish/{batch}/revert` — wycofanie partii: usuwa szuflady z
      pałaca i wiersze rejestru, zostawia partię ze statusem `reverted`.
- [x] **4.** Filtr sekretów jako osobny, testowalny serwis (wzorce: `.env`, klucze
      prywatne, `BEGIN * PRIVATE KEY`, hasła w URL-ach, tokeny o typowych
      prefiksach). Raport pominięć jest częścią partii.
- [ ] **5.** **Wysyłka automatyczna** (domyślna): po sesji i po lokalnym mieleniu
      zbiera szuflady nowsze niż znacznik, ustala przestrzeń docelową regułą
      lądowania (mapowanie → zespołowa, brak → prywatna) i wysyła partią.
- [ ] **6.** **Kolejka wyjściowa** w `~/.ws-memory/outbox/`: nieudana wysyłka nie gubi
      niczego i nie przerywa pracy; ponowienie z narastającym odstępem, znacznik
      przesuwa się dopiero po potwierdzeniu przez serwer.
- [x] **7.** Komenda `/ws-publish` — dla trybu ręcznego: filtr (skrzydło / temat /
      zakres daty), podgląd, potwierdzenie, wysyłka z widocznym postępem.
- [ ] **8.** Propozycja mapowania, gdy nazwa lokalnego skrzydła odpowiada istniejącej
      przestrzeni zespołowej użytkownika.
- [ ] **9.** Lustra: CRUD w `/api`, ekran w interfejsie (mapowanie skrzydło → przestrzeń,
      wykluczenia pokoi, pauza, wyłącznik), pierwszy przebieg jako podgląd.
- [x] **10.** Lokalny agent wysyłki w pluginie: uruchamiany hookiem `SessionEnd` albo
      ręcznie, publikuje przyrostowo szuflady nowsze niż `last_drawer_filed_at`.
- [x] **11.** Skill `ws-memory-recall` uzupełniony o kolejność dwóch źródeł: najpierw
      `ws_search` (wspólna baza), potem lokalny `mempalace_search`.
- [ ] **12.** Dokumentacja dla dewelopera: jak postawić lokalny pałac i podłączyć oba
      serwery MCP naraz.

## Postęp

Stan poszczególnych punktów jest w polach wyboru wyżej. Dwie rzeczy, których
pola nie powiedzą:

**Po drodze zamknięta dziura bezpieczeństwa**, zgłoszona przez skanowanie kodu
na pull requeście. Para źródłowa nie była związana z właścicielem, więc podanie
cudzej nazwy repliki zwracało cudzy wiersz — a ścieżka powtórnej publikacji
nadpisywała wtedy jego szufladę i przenosiła ją do własnej przestrzeni
(`Version20260913000006`). Rozstrzygnięcia projektowe: **D-036**.

**Punkt 11 był już zrobiony** przy TODO-009 — skill `ws-memory-recall` narzuca
kolejność dwóch źródeł od początku.

**Mostek przejechany od końca do końca.** 23 szuflady z lokalnego pałaca
(`wing_websystems`, `wing_claude-code-ws-memory`) są w przestrzeni
`baza-wiedzy`, z `source_replica = laptop-artur` i oryginalnymi
identyfikatorami, i znajdują się wyszukiwaniem znaczeniem. Wysłał je
`plugin/skrypty/wyslij.py`, wołany z **zainstalowanej wtyczki**, poleceniem
`/ws-publish`.

Pierwszy prawdziwy klient znalazł błąd, który zamykał mostek całkowicie —
opisany niżej i w D-039. Dopóki jedynym „klientem" były testy wołające
`PublishService` wprost, nie miał prawa się ujawnić.

Zostają punkty **5, 6, 8, 9 i 12**: wysyłka automatyczna po sesji, kolejka
wyjściowa, propozycja mapowania, ekran luster i dokumentacja dla dewelopera.

## Kryteria ukończenia

- [x] Deweloper z lokalnym pałacem publikuje szufladę i widzi ją w interfejsie
  z własnym autorstwem oraz oznaczeniem, z której repliki przyszła.
- [x] Powtórna publikacja tej samej szuflady **nie** tworzy duplikatu (liczba
  szuflad w przestrzeni bez zmian, wiersz zaktualizowany).
- [x] Publikacja do przestrzeni bez roli `writer` jest odrzucona.
- [ ] Szuflada z podstawionym plikiem `.env` **nie** przechodzi — ani z klienta,
  ani gdy klient ją mimo wszystko wyśle (dwa osobne testy).
- [ ] Przy domyślnych ustawieniach szuflada zapisana lokalnie pojawia się na
  serwerze **bez żadnej akcji użytkownika**.
- [x] Skrzydło bez mapowania ląduje w prywatnej przestrzeni właściciela i **nie
  jest widoczne** dla innych członków zespołu (test negatywny).
- [x] Mapowanie bez potwierdzenia nie kieruje niczego do przestrzeni zespołowej.
- [ ] Wyłączenie `auto_publish` zatrzymuje wysyłkę całkowicie.
- [x] Ta sama treść wysłana dwukrotnie do jednej przestrzeni jest zapisana raz
  (odsiew po `content_hash`).
- [ ] **Przy wyłączonym serwerze** mielenie i zapis lokalny działają normalnie,
  a szuflady czekają w kolejce; po włączeniu serwera dopinają się bez
  duplikatów i bez działania użytkownika (test: zatrzymanie kontenera,
  praca, uruchomienie).
- [ ] Przerwanie wysyłki w połowie partii nie gubi szuflad ani nie przesuwa
  znacznika przed potwierdzeniem.
- [ ] Wykluczony pokój nie trafia do przestrzeni, choć jest w skrzydle źródłowym.
- [x] Wycofanie partii usuwa szuflady i zostawia partię ze statusem `reverted`.
- [ ] Agent z dwoma serwerami MCP znajduje treść i we wspólnej bazie, i w lokalnym
  pałacu, w kolejności narzuconej skillem.
- [ ] Kod projektu **nie** pojawia się w żadnym żądaniu do serwera — tylko tekst
  szuflad (sprawdzone na zapisie ruchu).
