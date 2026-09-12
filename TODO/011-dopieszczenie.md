---
noteId: "ac1d9e71aeb211f1997d030a3cd38ca7"
tags: [ws-memory, todo, jakosc, dashboard, utrzymanie]

---

# TODO-011 — Dopieszczenie: jakość bazy wiedzy

**Utworzono:** 2026-09-12 16:05 · **Stan:** do zrobienia · **Zależności:** 010

## Powód

Po uruchomieniu wszystkich funkcji pojawi się problem, którego nie ma na
starcie: **baza będzie rosnąć szybciej, niż ktokolwiek ją czyta**. Agenci
zapisują automatycznie, mining dokłada repozytoria, transkrypty przychodzą po
każdej sesji. Bez narzędzi utrzymania jakości baza wiedzy zamienia się w
wysypisko, w którym wyszukiwanie zwraca pięć sprzecznych odpowiedzi.

To zadanie jest celowo ostatnie: dopóki nie widzimy realnego wzorca użycia, nie
wiemy, które problemy jakości wystąpią naprawdę.

## Analiza

Trzy problemy jakości są przewidywalne:

- **Duplikaty** — to samo ustalenie zapisane przez trzech agentów w trzech
  sesjach. MemPalace ma `mempalace_check_duplicate`, można się na tym oprzeć.
- **Sprzeczności** — starsza decyzja nadal wyszukiwalna obok nowszej, która ją
  unieważnia. Graf wiedzy MemPalace ma unieważnianie faktów
  (`kg_invalidate`, `kg_supersede`) — warto to wykorzystać dla dokumentów.
- **Luki** — pytania, na które baza nie odpowiada. `ws-onboarding` już je
  zgłasza; trzeba je gdzieś zbierać, żeby ktoś je uzupełnił.

Czego **nie** robimy: automatycznego usuwania czegokolwiek. Narzędzia jakości
proponują, człowiek decyduje. Automatyczna czystka w bazie wiedzy usunie
kiedyś coś, czego nikt nie odtworzy.

## Rozwiązanie

1. Dashboard jakości: przyrost bazy w czasie, udział treści zweryfikowanej,
   dokumenty bez weryfikacji starsze niż miesiąc, przestrzenie bez aktywności.
2. Rejestr luk: zgłoszenia z `ws-onboarding` i z ręcznego przycisku „brakuje
   tu odpowiedzi" — lista do uzupełnienia, z liczbą zapytań.
3. Wykrywanie duplikatów: zadanie cykliczne oparte na
   `mempalace_check_duplicate`, wynik jako **propozycje** scalenia.
4. Oznaczanie treści przedawnionej: dokument może wskazywać, że zastępuje inny;
   zastąpiony spada w wynikach i pokazuje odnośnik do następcy.
5. Kolejka propozycji w interfejsie (dla przestrzeni z `requires_proposal`):
   lista, podgląd, przyjęcie jednym kliknięciem, odrzucenie z uzasadnieniem.
6. Raport tygodniowy mailem do administratorów: przyrost, luki, duplikaty,
   zadania w błędzie.
7. Eksport przestrzeni do plików Markdown — na wypadek migracji i jako backup
   czytelny bez systemu.

## Kryteria ukończenia

- Dashboard pokazuje przyrost i udział treści zweryfikowanej.
- Luka zgłoszona przez `ws-onboarding` pojawia się w rejestrze z licznikiem.
- Zadanie duplikatów znajduje celowo wprowadzoną parę i **proponuje** scalenie,
  nie wykonuje go.
- Dokument oznaczony jako zastąpiony spada w wynikach i pokazuje następcę.
- Przyjęcie propozycji tworzy dokument z zachowaną informacją o autorstwie AI.
- Raport tygodniowy dociera i zawiera prawdziwe liczby.
- Eksport przestrzeni daje pliki Markdown czytelne bez WS_Memory.
- **Nic w tym zadaniu nie usuwa treści automatycznie** — potwierdzone
  przeglądem kodu.
