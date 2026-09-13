---
noteId: "7a6c84e0aeb211f1997d030a3cd38ca7"
tags: [ws-memory, todo, frontend, edytor, codemirror, wersjonowanie]

---

# TODO-008 — Frontend: edytor Markdown, historia, tokeny agentów

**Utworzono:** 2026-09-12 16:03 · **Stan:** ✅ **UKOŃCZONE 2026-09-13 16:31** · **Zależności:** 007, 005

## Powód

Bez edytora WS_Memory jest tylko przeglądarką tego, co napisało AI — a
wymaganie było wyraźne: baza wiedzy **zarówno dla ludzi, jak i dla modeli**.
Do tego dochodzi ekran tokenów, bez którego deweloper nie podłączy agenta.

## Analiza

Edytor to **CodeMirror 6 z podglądem obok**, nie WYSIWYG (D-009). Uzasadnienie
jest w decyzji: to samo pole zapisują ludzie i agenci, a każdy obieg treści
przez model dokumentu WYSIWYG gubi to, czego ten model nie obsługuje.

Historia rewizji: porównanie **dwóch dowolnych** rewizji, nie tylko sąsiednich
— przy dokumencie poprawianym przez AI kilka razy pod rząd interesujące jest
zwykle „co się zmieniło od wersji, którą czytałem", a nie ostatni krok.

Ryzyko utraty treści: użytkownik pisze długi dokument, token wygasa albo
przeglądarka się zamyka. Szkic musi przetrwać w `localStorage` i zostać
odtworzony przy powrocie, z jawnym pytaniem, czy przywrócić.

Konflikt edycji: agent może zapisać rewizję, gdy człowiek edytuje. Nie blokujemy
— pokazujemy ostrzeżenie „w trakcie twojej edycji powstała rewizja nr N" z
możliwością porównania przed zapisem.

## Rozwiązanie

1. Ekran `/s/:space/:slug/edit` — CodeMirror 6 (Markdown, podświetlanie,
   skróty) + podgląd `markdown-it` obok, przełączalny na pełną szerokość.
2. Pole „opis zmiany" wymagane przy zapisie — historia bez opisów zmian jest
   prawie bezużyteczna.
3. Szkic w `localStorage` co kilka sekund, przywracanie z pytaniem, czyszczenie
   po udanym zapisie.
4. Wykrywanie nowej rewizji w tle (odpytywanie przy zapisie) i ostrzeżenie z
   porównaniem, bez blokowania zapisu.
5. Ekran `/s/:space/:slug/history` — lista rewizji z autorem i opisem, wybór
   dwóch do porównania, diff z podświetleniem, przycisk cofnięcia z
   potwierdzeniem.
6. Przycisk weryfikacji dokumentu (tylko dla ludzi z rolą `writer`+) z
   wyraźnym opisem, co oznacza: „potwierdzam, że treść jest prawdziwa".
7. Ekran `/settings/tokens` — wystawianie tokena (wartość pokazana **raz**,
   z ostrzeżeniem), zakres przestrzeni, lista z „ostatnio użyty",
   unieważnianie z potwierdzeniem.
8. Ekrany administracyjne: użytkownicy, zaproszenia, przestrzenie, role, audyt.

## Kryteria ukończenia

- Utworzenie i edycja dokumentu działa; podgląd renderuje dokładnie ten tekst,
  który poleci do API.
- Zapis bez opisu zmiany jest niemożliwy.
- Zamknięcie karty w trakcie pisania i powrót przywraca szkic.
- Porównanie rewizji 1 i 4 pokazuje poprawny diff; cofnięcie tworzy nową
  rewizję i nie usuwa żadnej.
- Rewizja zapisana przez agenta w trakcie edycji powoduje ostrzeżenie, nie
  cichą utratę treści.
- Token wystawiony w interfejsie pozwala agentowi połączyć się z `/mcp`;
  unieważnienie odcina go natychmiast.
- E2E (Playwright): logowanie → utworzenie → edycja → diff → cofnięcie.

## Co zostało zrobione

**2026-09-13 16:31**

Punkty 1–7 zamknięto wcześniej (edytor CodeMirror z podglądem, wymagany opis
zmiany, szkic w przeglądarce, wykrywanie cudzej rewizji, historia z porównaniem
dowolnych dwóch rewizji i cofaniem, weryfikacja dokumentu, ekran tokenów, E2E
w Playwrighcie). Teraz punkt 8.

### Ekrany administracyjne

Cztery ekrany pod `/admin`, w **osobnym układzie** — nie w pasku bocznym bazy
wiedzy. Rozdzielenie było poprawką po przeglądzie i jest istotne: pasek boczny
odpowiada na pytanie „gdzie jest wiedza", a utrzymanie instalacji na zupełnie
inne. Zrzuty: `TODO/zrzuty/008-administracja-*.png`.

- **Konta** — lista z wyszukiwaniem, nadawanie i odbieranie roli globalnej,
  włączanie i wyłączanie konta. Wyłączenie unieważnia tokeny agentów tej osoby;
  konto wyłączone, którego agent dalej pisze do pamięci, jest wyłączone pozornie.
- **Zaproszenia** — wystawianie z panelu (dotąd tylko z konsoli), lista ze
  statusem wyliczanym, unieważnianie. Link pokazuje się **raz**, jak token agenta.
- **Przestrzenie** — liczniki, skład, zmiana roli, usunięcie i nadanie dostępu
  nowej osobie. Prywatne oznaczone i bez akcji na członkach.
- **Dziennik audytu** — filtry po akcji, przestrzeni, aktorze i zakresie dat,
  z rozróżnieniem człowieka, agenta i systemu. Bez możliwości zmiany i czyszczenia.

Backend: 400 testów, PHPStan level 8 czysty. Frontend: 159 testów.

### Ekran audytu od razu udowodnił, po co istnieje

Otwarty pierwszy raz pokazał **40 889 wpisów, z czego 20 335 to `user.login`** —
połowa dziennika opisująca logowania, których nie było. Firewalle są bezstanowe,
więc token jest sprawdzany przy **każdym** żądaniu i `LoginSuccessEvent` leciał za
każdym razem. Jedna osoba klikająca po aplikacji dopisywała wiersz na każde
żądanie HTTP; przy okazji `last_login_at` pokazywało „przed chwilą" każdemu
z ważnym tokenem, a każdy odczyt wykonywał zapis do bazy.

Istniejący test sprawdzał, że dziennik **nie jest pusty** — co było prawdą i przed
zepsuciem, i po. Nowy liczy. Trzy testy ekranu audytu miały tę usterkę zakodowaną
jako oczekiwanie (`SEEDED + 1`), więc poprawka je wywróciła — i dobrze.

Dziennik audytu, w którym większość wpisów to fikcja, jest gorszy od krótkiego:
prawdziwe wpisy w nim są, tylko nikt ich nie znajdzie.

### Trzy inne rzeczy złapane przy składaniu

**`vue-tsc` wywracał się w kontenerze na braku pamięci** — Node dobiera stertę od
limitu kontenera i przy 1 GB wychodziło 549 MB. Objaw wygląda jak błąd typów,
a blokował też skrypt wypychający.

**Własny wiersz na liście kont miał aktywne przyciski**, które backend odrzuca.
Wyłączone — z widocznym powodem, bo wyłączony przycisk bez wyjaśnienia myli tak
samo, tylko ciszej.

**Nadawanie dostępu żyło pod inną trasą niż zmiana roli**, więc ta sama reguła
odpowiadała dwoma kodami (403 i 422), a ta sama operacja zapisywała się w audycie
raz jako „dodano", raz jako „zmieniono rolę". To drugie unieważnia pytanie, dla
którego D-016 w ogóle istnieje: czy ta osoba wtedy dostała dostęp, czy tylko
awansowała.

### Czego nie zrobiono

- **Brak indeksu pod wyszukiwanie kont** — `ILIKE '%…%'` nie skorzysta z btree.
  Przy paruset kontach bez znaczenia, ale to ten sam mechanizm, który wywalił
  listę dokumentów: rośnie cicho. Poprawka to `pg_trgm` i indeks GIN.
- **`count(*)` na dzienniku audytu jest zawsze O(n)** — przy milionach wierszy to
  setki milisekund na każde otwarcie ekranu.
- **Brak tworzenia i edycji przestrzeni z panelu** (nazwa, opis, tryb propozycji).
- **Brak filtrów po roli i aktywności na kontach oraz po rodzaju aktora w audycie**
  — a „pokaż, co zrobiły agenty" to jeden z głównych powodów wejścia na ten ekran.
- **Dwie rodziny tras dla trzech wariantów jednej operacji na członkostwie.**
  Kody odmów i nazwy akcji ujednolicone, ale same trasy nie.
