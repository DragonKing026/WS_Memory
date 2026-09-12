---
noteId: "86dee9c0aeb711f1997d030a3cd38ca7"
tags: [ws-memory, todo, hybryda, mempalace, publikacja, lustro]

---

# TODO-012 — Mostek: lokalny pałac → wspólna baza (hybryda)

**Utworzono:** 2026-09-12 16:45 · **Stan:** do zrobienia · **Zależności:** 004, 009

## Powód

Deweloper, który chce mielić własne projekty, nie powinien wysyłać kodu na
serwer ani czekać na administratora. Ma mieć **własny lokalny MemPalace** —
z własnym `mempalace init` i `mempalace mine` — i móc **publikować wybraną
wiedzę** do wspólnej bazy (D-010).

Dodatkowa korzyść: rozmowy i notatki robocze zostają lokalnie, dopóki ktoś ich
świadomie nie skieruje do zespołu.

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
2. **Lustro działa bez nadzoru.** Raz ustawione mapowanie skrzydło → przestrzeń
   wyśle też to, czego właściciel nie przewidział. Dlatego pierwszy przebieg
   jest **podglądem wymagającym potwierdzenia**, są wykluczenia pokoi, a partie
   można wycofać.
3. **Sekrety w lokalnym pałacu.** Lokalny `mine` mógł zgarnąć plik, którego nie
   powinno tam być. Filtr sekretów musi działać **po obu stronach** — klient nie
   wysyła, serwer i tak sprawdza. Zaufanie do klienta byłoby tu błędem.

Czego **nie** robimy: nie dajemy lokalnemu MemPalace dostępu do centralnej bazy
przez `MEMPALACE_PGVECTOR_DSN`. Byłoby prostsze, ale ominęłoby token, role i
audyt — odrzucone w D-010.

## Rozwiązanie

1. Encje `Mirror`, `PublishBatch` + rozszerzenie `memory_entries`
   (`source_replica`, `source_drawer_id`, `publish_batch_id`) z ograniczeniem
   unikalności na parze źródłowej.
2. `POST /api/publish` — przyjmuje partię szuflad: treść, skrzydło i pokój
   źródłowy, znaczniki czasu, identyfikator repliki i szuflady. Sprawdza rolę
   `writer` w przestrzeni docelowej, przepuszcza przez filtr sekretów,
   przelicza embeddingi po stronie serwera, zapisuje z autorem.
   Tryb `preview=true` zwraca raport bez zapisu.
3. `POST /api/publish/{batch}/revert` — wycofanie partii: usuwa szuflady z
   pałaca i wiersze rejestru, zostawia partię ze statusem `reverted`.
4. Filtr sekretów jako osobny, testowalny serwis (wzorce: `.env`, klucze
   prywatne, `BEGIN * PRIVATE KEY`, hasła w URL-ach, tokeny o typowych
   prefiksach). Raport pominięć jest częścią partii.
5. Komenda pluginu `/ws-publish` — filtr (skrzydło / pokój / zakres daty),
   podgląd, potwierdzenie, wysyłka partiami z widocznym postępem.
6. Lustra: CRUD w `/api`, ekran w interfejsie (mapowanie skrzydło → przestrzeń,
   wykluczenia pokoi, pauza, wyłącznik), pierwszy przebieg jako podgląd.
7. Lokalny agent lustra w pluginie: uruchamiany hookiem `SessionEnd` albo
   ręcznie, publikuje przyrostowo szuflady nowsze niż `last_drawer_filed_at`.
8. Skill `ws-memory-recall` uzupełniony o kolejność dwóch źródeł: najpierw
   `ws_search` (wspólna baza), potem lokalny `mempalace_search`.
9. Dokumentacja dla dewelopera: jak postawić lokalny pałac i podłączyć oba
   serwery MCP naraz.

## Kryteria ukończenia

- Deweloper z lokalnym pałacem publikuje szufladę i widzi ją w interfejsie
  z własnym autorstwem oraz oznaczeniem, z której repliki przyszła.
- Powtórna publikacja tej samej szuflady **nie** tworzy duplikatu (liczba
  szuflad w przestrzeni bez zmian, wiersz zaktualizowany).
- Publikacja do przestrzeni bez roli `writer` jest odrzucona.
- Szuflada z podstawionym plikiem `.env` **nie** przechodzi — ani z klienta,
  ani gdy klient ją mimo wszystko wyśle (dwa osobne testy).
- Lustro bez potwierdzenia pierwszego podglądu nie publikuje nic (test).
- Wykluczony pokój nie trafia do przestrzeni, choć jest w skrzydle źródłowym.
- Wycofanie partii usuwa szuflady i zostawia partię ze statusem `reverted`.
- Agent z dwoma serwerami MCP znajduje treść i we wspólnej bazie, i w lokalnym
  pałacu, w kolejności narzuconej skillem.
- Kod projektu **nie** pojawia się w żadnym żądaniu do serwera — tylko tekst
  szuflad (sprawdzone na zapisie ruchu).
