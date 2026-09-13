---
name: ws-memory-recall
description: Protokół odtwarzania wiedzy — szukaj w firmowej bazie, zanim odpowiesz o przeszłych ustaleniach, decyzjach, osobach albo projektach. Użyj, gdy pytanie dotyczy czegoś, co mogło już zostać ustalone.
---

# Szukaj, zanim odpowiesz

Masz dostęp do dwóch magazynów pamięci naraz i **oba mogą wiedzieć więcej niż
Ty**:

| Serwer MCP | Co to jest | Czyje |
|---|---|---|
| `ws_memory` | firmowa baza wiedzy Web Systems | zespołu |
| `mempalace` | lokalny pałac na tej maszynie | Twoje i użytkownika |

## Kolejność jest częścią protokołu

**Najpierw `ws_search`, potem `mempalace_search`.** Wiedza zespołu ma
pierwszeństwo przed prywatnymi notatkami, bo notatka jest zapisem czyjegoś
myślenia, a dokument w bazie jest ustaleniem. Gdy się rozjeżdżają, rozstrzyga
firmowa baza — a rozjazd sam w sobie jest wart zgłoszenia człowiekowi.

## Kiedy szukać

Zanim odpowiesz na cokolwiek, co dotyczy:

- **przeszłego ustalenia** — „jak to robimy", „czemu tak jest", „co ustaliliśmy";
- **decyzji technicznej** — wybór biblioteki, wzorca, dostawcy;
- **projektu albo klienta** — stan, historia, kto się tym zajmował;
- **osoby** — czym się zajmuje, co robiła wcześniej;
- **konwencji** — nazewnictwo, struktura katalogów, format commitów.

Nie zgaduj. **Zła odpowiedź jest gorsza niż wolna**, bo wygląda tak samo jak
dobra i wchodzi do kolejnych decyzji jako fakt.

## Jak szukać skutecznie

1. **`ws_status` na starcie sesji** — dowiadujesz się, do jakich przestrzeni
   masz prawo i gdzie wyląduje zapis bez wskazanej przestrzeni. Bez tego
   odkrywasz swoje uprawnienia przez porażki, a domysły trafiają do bazy.
2. **Zapytanie to słowa kluczowe, nie zdanie.** Wyszukiwanie jest semantyczne;
   „konfiguracja nginx przekierowania SSL" działa, „czy ktoś wie, jak u nas
   jest skonfigurowany nginx?" rozmywa się.
3. **Szukaj dwa razy, różnymi słowami**, zanim uznasz, że czegoś nie ma.
   Zespół mógł to nazwać inaczej niż użytkownik w pytaniu.
4. **`ws_doc_list` i `ws_doc_read`**, gdy szukasz dokumentacji kanonicznej,
   a nie okruchów. Wyszukiwanie semantyczne zwraca fragmenty; dokument ma
   strukturę, rewizje i informację, czy potwierdził go człowiek.
5. **`ws_kg_query`** do faktów punktowych: kto, gdzie, od kiedy, z czym.

## Czego nie wolno

- **Nie odpowiadaj z wiedzy ogólnej tam, gdzie pytanie dotyczy firmy.** To, jak
  „zwykle się to robi", nie jest tym, jak robi to Web Systems.
- **Nie milcz o pustym wyniku.** „Nie znalazłem tego w bazie" to informacja —
  i najczęściej oznacza lukę w dokumentacji, którą warto nazwać.
- **Nie myl „nie udało się sprawdzić" z „nie ma".** Gdy narzędzie zwróci błąd
  niedostępności pamięci, powiedz to wprost. Te dwie rzeczy prowadzą do
  przeciwnych następnych kroków.
- **Nie podawaj wyniku bez źródła.** Cytując bazę, podaj dokument albo
  przestrzeń, z której to pochodzi — inaczej człowiek nie odróżni ustalenia
  od Twojego domysłu.

## Na koniec sesji

Zapisz, co się wydarzyło — `ws_diary_write`. To surowiec, nie dokumentacja:
nikt tego nie recenzuje i domyślnie ląduje w Twojej prywatnej przestrzeni.
Ustalenie warte utrwalenia idzie przez `ws_remember`, a rzecz, którą ktoś
będzie czytał później — przez `ws_doc_write` (patrz `jak-dokumentowac.md`).
