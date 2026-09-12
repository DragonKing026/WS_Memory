---
noteId: "50cd9ca1aeb211f1997d030a3cd38ca7"
tags: [ws-memory, todo, backend, wiki, wersjonowanie, rewizje]

---

# TODO-005 — Backend: wiki, rewizje, publikacja do pałaca

**Utworzono:** 2026-09-12 16:03 · **Stan:** ✅ **UKOŃCZONE 2026-09-12 22:52** · **Zależności:** 002, 003

## Powód

Dokumentacja kanoniczna to ta klasa wiedzy, dla której wersjonowanie ma sens:
dokumenty są czytane jak dokumentacja, poprawiane przez wiele osób i przez AI,
i muszą dać się cofnąć. Pałac nie ma pojęcia rewizji — dlatego źródłem prawdy
jest Postgres (D-004).

## Analiza

Rewizje trzymamy jako **pełne treści**, nie diffy (`docs/02-model-danych.md`).
Diff liczymy w locie. Powód: łańcuch diffów jest tak wiarygodny jak jego
najsłabsze ogniwo — jedno uszkodzone ogniwo unieważnia całą historię od tego
miejsca. Pełne treści zajmują więcej miejsca i są tego warte.

Rollback to **nowa rewizja** z treścią starej, nie usunięcie nowszych.
Historia nigdy się nie skraca.

Publikacja do pałaca musi iść przez `worker` (asynchronicznie), bo liczenie
embeddingu długiego dokumentu potrwa. Ale kolejność ma znaczenie: przy szybkiej
serii zapisów pałac musi skończyć z **najnowszą** wersją. Zadanie publikacji
musi więc być idempotentne i odrzucać zlecenia starsze niż aktualna rewizja.

Agent pisze wprost (D-005), z wyjątkiem przestrzeni z `requires_proposal`.

## Rozwiązanie

1. Encje `Document`, `DocumentRevision`, `Proposal` + migracja z
   ograniczeniami: dokładnie jeden autor na rewizję (`CHECK`),
   `current_revision_id` wskazujący rewizję tego dokumentu.
2. `DocumentService`: `write(Actor, space, slug, title, content, changeNote)`
   — tworzy dokument albo rewizję, ustawia `authored_by_ai` na podstawie typu
   aktora; `rollback(Actor, document, revisionNumber)`; `verify(User, document)`
   — **tylko człowiek**; `archive`.
3. Endpointy `/api`: lista, odczyt (z opcjonalnym numerem rewizji), zapis,
   historia, porównanie dwóch rewizji, cofnięcie, weryfikacja, archiwizacja.
4. Narzędzia MCP: `ws_doc_list`, `ws_doc_read`, `ws_doc_write`, `ws_propose`.
   **Bez `ws_doc_verify` i bez `ws_doc_delete`** (D-005, D-007).
5. Zadanie Messenger `PublishDocumentToPalace` — wypycha treść jako szufladę
   w pokoju `documentation`, zapisuje `memory_entries`, jest idempotentne i
   ignoruje zlecenia dla nieaktualnej rewizji.
6. Kolejka propozycji: gdy `spaces.requires_proposal`, `ws_doc_write` zwraca
   `-32004` z podpowiedzią użycia `ws_propose`; przyjęcie propozycji tworzy
   dokument z autorem-człowiekiem i zachowaniem informacji, że treść
   pochodziła od AI.

## Kryteria ukończenia

- Zapis → odczyt zwraca treść; drugi zapis tworzy rewizję nr 2, nie nadpisuje.
- Porównanie rewizji 1 i 3 zwraca poprawny diff.
- Cofnięcie do rewizji 1 tworzy rewizję nr 4 o treści rewizji 1; rewizje 2 i 3
  nadal istnieją.
- Dokument napisany przez agenta ma `authored_by_ai = true` i brak weryfikacji.
- `ws_doc_verify` nie istnieje w `tools/list`.
- Po publikacji dokument jest znajdowalny przez `ws_search` (test integracyjny
  z kolejką przetworzoną do końca).
- Seria trzech szybkich zapisów kończy się pałacem zawierającym treść
  najnowszej rewizji (test wyścigu).
- W przestrzeni z `requires_proposal` zapis agenta ląduje w kolejce, nie w
  dokumencie.

## Co zostało zrobione

**Ukończono:** 2026-09-12 22:52

### Kryteria ukończenia — weryfikacja

| Kryterium | Wynik |
|---|---|
| zapis → odczyt zwraca treść; drugi zapis tworzy rewizję nr 2, nie nadpisuje | ✅ i rewizja 1 dalej jest czytelna pod `?revision=1` |
| porównanie rewizji 1 i 3 zwraca poprawny diff | ✅ własny LCS po wierszach, 9 testów jednostkowych — w tym różne końce wiersza i odmowa dla absurdalnie długiej treści |
| cofnięcie do rewizji 1 tworzy rewizję nr 4; rewizje 2 i 3 nadal istnieją | ✅ historia zwraca `[1, 2, 3, 4]`, a rewizja 3 dalej daje swoją treść |
| dokument napisany przez agenta ma `authored_by_ai = true` i brak weryfikacji | ✅ plus `authorAgentTokenId` w rewizji |
| `ws_doc_verify` nie istnieje w `tools/list` | ✅ test wypisuje też trzy inne nazwy, których nie ma i nie będzie |
| po publikacji dokument jest znajdowalny przez `ws_search` | ✅ test integracyjny z **kolejką opróżnioną przez prawdziwy transport** |
| seria trzech szybkich zapisów kończy się najnowszą treścią w pałacu | ✅ kolejka opróżniona **od najnowszego zlecenia** — tylko w tej kolejności strażnik jest sprawdzany |
| w przestrzeni z `requires_proposal` zapis agenta ląduje w kolejce | ✅ `-32004`, komunikat nazywa `ws_propose`, a w wiki nie ma nic |

**Razem: 215 testów, 889 asercji** (było 153). PHPStan poziom 8 bez błędów.

### Co powstało

**Domena** (`src/Domain/Document/`) — `DocumentSlug`, `DocumentStatus`,
`ProposalStatus`, `RevisionDiff`. Rozszerzone porty pamięci:
`MemoryStore::replace()`, `MemoryRegistry::{drawerForDocument,rebind,countsFor}`,
`AgentTokenDirectory::ownerOf()`.

**Aplikacja** — `Document\{DocumentService,ProposalService,PublishDocument,
PublishDocumentHandler,DocumentNotFound,ProposalRequired}` oraz
`MemoryService::publishDocument()`.

**Encje i migracja** — `Document`, `DocumentRevision`, `Proposal`,
`Version20260912000005`.

**Wejścia** — `Api\DocumentController` (8 tras), `Api\ProposalController`
(4 trasy) i cztery narzędzia MCP: `ws_doc_list`, `ws_doc_read`, `ws_doc_write`,
`ws_propose`. Zestaw narzędzi jest teraz kompletny: jedenaście.

### Rzeczy, które zmieniły projekt zadania

1. **`mempalace_update_drawer` istnieje** — zadanie zakładało „wypycha treść jako
   szufladę", co przy każdej rewizji zostawiałoby starą wersję wyszukiwalną.
   Zamiast tego jedna szuflada na dokument, aktualizowana w miejscu (D-025).
   **Sprawdzone, nie założone:** test zapisuje rewizję o innych słowach
   i potwierdza, że stara treść przestaje być znajdowalna — czyli aktualizacja
   naprawdę przelicza wektor.
2. **Klucz obcy złożony zamiast `CHECK`.** Model danych obiecywał `CHECK`
   pilnujący, że `current_revision_id` wskazuje rewizję tego dokumentu —
   a `CHECK` nie może sięgnąć do innej tabeli. Klucz obcy na
   `(current_revision_id, id)` → `document_revisions (id, document_id)` robi to
   deklaratywnie i czyni błąd **niewyrażalnym**. `DEFERRABLE`, bo dokument
   i pierwsza rewizja wskazują na siebie wzajemnie w jednej transakcji.
3. **Ograniczenie „dokładnie jeden autor" ma konsekwencję, której nie było
   w analizie:** rewizja agenta nie zapisuje właściciela tokena, a wiersz
   w `memory_entries` wymaga człowieka. Dołożone `ownerOf()`, odpowiadające także
   dla tokenów unieważnionych — kto coś napisał, nie zmienia się, gdy jego
   poświadczenie zostaje wycofane.

### Decyzje podjęte po drodze

**D-025** — jedna szuflada na dokument, aktualizowana w miejscu; zlecenia
nieaktualne porzucane. **D-026** — propozycję składa czytający, autorem
przyjętej rewizji jest recenzent.

### Potknięcia warte zapamiętania

1. **Commit z czerwonym testem.** Dodanie czterech narzędzi przewróciło test
   kontraktu `tools/list`, który wypisuje zestaw jawnie — czyli test zadziałał
   dokładnie tak, jak miał. Zdążyłem to zacommitować przed uruchomieniem
   zestawu; poprawione `--amend` (commit nie był wypchnięty).
2. **Testy integracyjne spędzały dwie i pół minuty na decyzji o pominięciu.**
   Docker nie odrzuca nazwy zatrzymanego kontenera — **przekracza limit czasu**,
   więc każde z 15 sprawdzeń kosztowało trzy sekundy. Sprawdzenie jest teraz
   jedno na uruchomienie (`RequiresLivePalace`); cały zestaw skrócił się z 2:33
   do 42 sekund przy zatrzymanym pałacu.
3. **PHPStan złapał dwie nieprecyzyjne adnotacje** w nowym kodzie: tablica LCS
   nie jest listą, bo jest wypełniana od prawego dolnego narożnika.

### Czego nie zrobiono

- **Brak wycofywania publikacji przy archiwizacji.** Zarchiwizowany dokument
  zostaje w pałacu i nadal jest znajdowalny semantycznie. To do rozstrzygnięcia
  razem z ekranami: archiwizacja bywa „to już nieaktualne" (wtedy powinno
  zniknąć z wyszukiwania) i „skończone, nie ruszamy" (wtedy nie). Zgadywanie
  teraz oznaczałoby zgadywanie źle w połowie przypadków.
- **Brak `draft` jako stanu roboczego w interfejsie.** Status istnieje w bazie
  i w API, ale każdy zapis publikuje — bo bez ekranów nie ma czym zapisać
  szkicu. Dojdzie z `TODO-008`.
- **Diff jest po wierszach, nie po słowach.** Dla prozy czyta się gorzej. To
  osobny problem i osobny algorytm; obecny odpowiada na pytanie „które wiersze
  się zmieniły", które wystarcza recenzentowi decydującemu o zaufaniu.
- **Brak eksportu dokumentu do pliku.** Nikt o to nie prosił; `ws_doc_read`
  i `/api` zwracają pełną treść, więc narzędzie zewnętrzne ma z czego zrobić plik.
