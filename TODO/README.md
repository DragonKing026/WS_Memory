---
noteId: "29ba8f60aeb211f1997d030a3cd38ca7"
tags: [ws-memory, todo, proces, konwencje]

---

# TODO — zasady prowadzenia zadań

Jeden plik = jedno zadanie. Nazwa pliku: `NNN-krotki-opis.md`.
Nagłówek w środku pliku: **`# TODO-NNN — Tytuł zadania`** — numeracja z
prefiksem, żeby zadanie dało się jednoznacznie przywołać w rozmowie, commicie
i dokumentacji („zrób TODO-004"), bez mylenia z numerem decyzji (`D-004`).

## Struktura pliku zadania

Każde zadanie ma cztery sekcje:

- **Powód** — dlaczego to robimy. Co jest teraz źle albo czego brakuje.
- **Analiza** — co sprawdziliśmy, jakie są opcje, na co trzeba uważać.
- **Rozwiązanie** — jak to robimy, **listą z polami wyboru**, w kolejności
  wykonania.
- **Kryteria ukończenia** — **lista z polami wyboru**, warunki sprawdzalne.
  Nie „działa", a „polecenie X zwraca Y".

## Pola wyboru odhacza się na bieżąco

`Rozwiązanie` i `Kryteria ukończenia` to listy `- [ ]` / `- [x]`. **Punkt
odhaczasz w tym samym commicie, który go dowozi** — nie na koniec zadania.

Dwie reguły, obie wzięte z pomyłek, nie z teorii:

1. **Kryterium odhaczasz, gdy jest sprawdzone, a nie gdy kod istnieje.**
   W TODO-012 filtr sekretów działa po stronie serwera i ma testy, a kryterium
   zostaje nieodhaczone, bo brzmi „ani z klienta, ani gdy klient ją mimo
   wszystko wyśle (**dwa osobne testy**)" — a klienta nie ma.
2. **Nie prowadź obok drugiej listy postępu.** Tabela „co zrobione" powtarzająca
   listę rozwiązania rozjedzie się z nią przy pierwszej zmianie. Taka tabela
   powstała w TODO-012 i została usunięta tego samego dnia; w sekcji `Postęp`
   zostaje wyłącznie to, czego pola wyboru nie powiedzą — na przykład błąd
   znaleziony po drodze.

## Zadanie zrobione w części

Stan w nagłówku brzmi wtedy **`🔵 W TOKU — punkty A–B z N`** z datą, a plik
dostaje sekcję **Postęp**.

Powód jest konkretny: zadanie zrobione w połowie wygląda na liście **dokładnie
tak samo** jak nietknięte. Zdarzyło się to 2026-09-13 — serwerowa połowa
TODO-012 była scalona w `main`, a plik nadal mówił „do zrobienia". Zauważył to
człowiek planujący następny krok, nie żadne sprawdzenie.

**Żaden skrypt tego nie wymusi.** `sprawdz-zadania.py` nie wie, ile z zadania
jest zrobione, i wiedzieć nie będzie. To jest reguła utrzymywana ręcznie i taka
zostaje — dlatego jest tutaj zapisana.

## Zrzuty ekranu

Zrzuty z weryfikacji trafiają do `TODO/zrzuty/`, nazwane `NNN-krotki-opis.png`
— **nigdy do korzenia repozytorium**. Zasady i uzasadnienie: `TODO/zrzuty/README.md`.

## Po ukończeniu

0. Odhacz ostatnie pola wyboru. Jeśli któreś zostaje puste, to **nie jest
   zadanie ukończone** — albo zejdź z zakresu świadomie i opisz to w rozliczeniu
   jako odłożone, albo dokończ.
1. Dopisz sekcję **Co zostało zrobione** z datą i godziną: co powstało, co
   przetestowano, co odłożono i dlaczego. Fakty, nie deklaracje.
   **Godzinę bierzesz z zegara, nie z pamięci** — po commicie da się ją
   sprawdzić przez `git log`. Daty pisane na wyczucie rozjechały już raz
   `CHANGELOG.md` o kilka godzin i umieściły dwa wpisy w przyszłości.
2. `git mv TODO/NNN-....md TODO/DONE/` — historia pliku zostaje zachowana.
3. Zaktualizuj dokumentację w `docs/`, jeśli zmieniło się zachowanie systemu.
4. Dopisz wpis do `CHANGELOG.md` z datą i godziną.
5. Zacommituj wszystko razem: kod, dokumentację, przeniesione zadanie, changelog.

Zadanie bez sekcji **Co zostało zrobione** nie trafia do `DONE/`.

**Zadanie anulowane też znika z listy** — do `DONE/`, z sekcją **Dlaczego
anulowane** zamiast rozliczenia. Nic w nim nie powstało, więc rozliczenie
z pracy, której nie było, byłoby pustą sekcją. Zostawione w `TODO/` wygląda jak
zaległość: TODO-010 leżało tak dobę po anulowaniu i padło pytanie, czemu nie
robimy go przed 011.

## Kolejność i zależności

```
✅ 000 szkielet ──► ✅ 001 backend fundament ──► ✅ 002 konta i przestrzenie
                                                     │
                                 ┌───────────────────┼───────────────────┐
                                 ▼                   ▼                   ▼
                       ✅ 003 klient pałaca ✅ 005 wiki backend ✅ 006 frontend fundament
                                 │                   │                   │
                                 ▼                   │                   ▼
                       ✅ 004 gateway MCP ◄──────────┘         ✅ 007 szukanie (UI)
                                 │                                       │
                                 ▼                                       ▼
                          009 plugin                              008 edytor i historia
                                 │
                                 ▼
                          012 mostek: lokalny pałac → serwer
                                 │
                                 ▼
                          011 dopieszczenie

  ❌ 010 mielenie serwerowe — ANULOWANE (D-012)
  ✅ 013 dokumentacja angielska · ✅ 014 ciągła integracja
```

Zadania 000-002 są ściśle sekwencyjne. Dalej backend (003-005) i frontend
(006-008) mogą iść równolegle, bo stykają się tylko przez kontrakt OpenAPI.
`TODO-010` zostało **anulowane** decyzją D-012: serwer nie mieli niczego,
więc jedyną drogą wnoszenia wiedzy jest `TODO-012` — publikacja z lokalnego
pałaca. Plik zostaje na miejscu ze statusem anulowania, żeby numeracja się nie
przesunęła, a analiza pozostała dostępna.
