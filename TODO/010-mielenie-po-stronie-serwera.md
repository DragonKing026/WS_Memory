---
noteId: "ac1d9e70aeb211f1997d030a3cd38ca7"
tags: []

---

# 010 — Mielenie po stronie serwera: transkrypty, repozytoria, dokumenty

**Utworzono:** 2026-09-12 16:05 · **Stan:** do zrobienia · **Zależności:** 009

## Powód

To jest mechanizm, który sprawia, że baza wiedzy rośnie **sama**. Bez niego
WS_Memory zawiera tylko to, co ktoś świadomie wpisał — czyli dokładnie tyle,
ile zwykła wiki, i tyle samo wymaga wysiłku.

## Analiza

MemPalace ma gotowy miner w trzech trybach: projekty (kod, notatki), rozmowy
(`--mode convos`) i dokumenty (`--mode extract`: PDF, DOCX, PPTX, XLSX, RTF,
EPUB — wymaga wariantu `extract`). Mining jest idempotentny i koordynowany po
znacznikach czasu, więc powtórne uruchomienie nie duplikuje wiedzy.

Ważne ograniczenie, które wyszło w rozpoznaniu: **długi mining trzyma blokadę
pisarza.** Dlatego mielenie musi iść przez narzędzie MCP w kontenerze
`mempalace` (proces jest tam ponownie wejściowy), a nie przez osobne wywołanie
CLI z zewnątrz — inaczej zlecenie zostanie odrzucone.

Mielenie repozytoriów wymaga dostępu do kodu. Dwie drogi: klonowanie z firmowego
gita po stronie serwera (czyste, wymaga klucza tylko do odczytu) albo wysyłka
z maszyny dewelopera (proste, ale przenosi duże pliki i powtarza pracę).
Wybieramy klonowanie po stronie serwera.

Czego mielić **nie wolno**: `vendor/`, `node_modules/`, plików `.env` z
sekretami, dumpów bazy. MemPalace respektuje `.gitignore`, ale sekrety trafiają
do repozytoriów częściej, niż powinny — potrzebna druga warstwa filtrów.

## Rozwiązanie

1. Worker obsługujący `mining_jobs`: pobranie zadania, wywołanie
   `mempalace_mine` przez MCP, zapis statystyk, obsługa błędów z ponowieniem.
2. Mielenie transkryptów: plik sesji z wolumenu, tryb `convos`, przestrzeń
   z `session_uploads` (domyślnie prywatna autora).
3. Mielenie repozytoriów: konfiguracja per przestrzeń (adres repo, gałąź,
   harmonogram), klonowanie płytkie kluczem tylko do odczytu, tryb projektowy.
4. Mielenie dokumentów: katalog wrzutek per przestrzeń + upload przez interfejs;
   tryb `extract` (obraz mempalace budowany z wariantem `extract`).
5. Filtr sekretów przed mieleniem: odrzucanie plików pasujących do wzorców
   (`.env*`, `*.pem`, `*.key`, `id_rsa*`, `*.dump`, `*.sql`) niezależnie od
   `.gitignore`, z wpisem do dziennika, co pominięto.
6. Harmonogram nocny (Messenger + cron w kontenerze `worker`): repozytoria
   oznaczone do cyklicznego mielenia.
7. Widok w interfejsie: stan zadań, statystyki, błędy, przycisk ponowienia.
8. Zadanie kontrolne: `memory_entries` bez odpowiadającej szuflady w pałacu —
   **raportowane, nie naprawiane po cichu**.

## Kryteria ukończenia

- Transkrypt wysłany hookiem jest zmielony i znajdowalny przez `ws_search` w
  prywatnej przestrzeni autora.
- Powtórne zmielenie tego samego źródła nie tworzy duplikatów (liczba szuflad
  bez zmian).
- Zmielenie repozytorium główna aplikacja Symfony zespołu (próbka) daje szuflady w przestrzeni projektu
  i znajdowalne odpowiedzi o jego architekturze.
- Plik `.env` z hasłem **nie** trafia do pałaca (test z podstawionym plikiem).
- PDF wrzucony przez interfejs jest znajdowalny po treści, nie tylko po nazwie.
- Harmonogram nocny wykonuje się i zostawia ślad w `mining_jobs`.
- Zadanie kontrolne zgłasza rozjazd, gdy ręcznie usuniemy szufladę z pałaca.
