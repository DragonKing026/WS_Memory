---
noteId: "50cd9ca1aeb211f1997d030a3cd38ca7"
tags: [ws-memory, todo, backend, wiki, wersjonowanie, rewizje]

---

# TODO-005 — Backend: wiki, rewizje, publikacja do pałaca

**Utworzono:** 2026-09-12 16:05 · **Stan:** do zrobienia · **Zależności:** 002, 003

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
