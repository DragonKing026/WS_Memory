---
noteId: "7a6c84e0aeb211f1997d030a3cd38ca7"
tags: []

---

# 008 — Frontend: edytor Markdown, historia, tokeny agentów

**Utworzono:** 2026-09-12 16:05 · **Stan:** do zrobienia · **Zależności:** 007, 005

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
