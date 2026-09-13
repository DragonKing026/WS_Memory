---
noteId: "d037a400af8311f18a50cfad3ca0cc8a"
tags: []
description: "Przeszukaj firmową bazę wiedzy, a potem lokalny pałac"
argument-hint:
  - "czego szukasz"

---

Szukaj: **$ARGUMENTS**

Kolejność jest częścią protokołu:

1. **`ws_search`** — firmowa baza wiedzy. Wiedza zespołu ma pierwszeństwo.
2. **`mempalace_search`** — lokalny pałac, dopiero potem.

Jeśli pierwsze zapytanie nic nie zwróci, **spróbuj jeszcze raz innymi słowami**
— zespół mógł nazwać rzecz inaczej niż pytający. Gdy trafisz na dokument,
dociągnij go w całości przez `ws_doc_read`; fragment z wyszukiwania bywa
wyrwany z kontekstu.

Odpowiadając, **podaj źródło** każdej informacji: adres dokumentu albo
przestrzeń. Bez tego nie da się odróżnić ustalenia od domysłu.

Gdy nie ma nic — powiedz to wprost i wymień słowa, których użyłeś. Pusty wynik
to najczęściej luka w dokumentacji, warta zgłoszenia.
