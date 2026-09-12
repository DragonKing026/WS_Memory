---
noteId: "7a6c84e1aeb211f1997d030a3cd38ca7"
tags: []

---

# 007 — Frontend: wyszukiwanie i przeglądanie bazy

**Utworzono:** 2026-09-12 16:05 · **Stan:** do zrobienia · **Zależności:** 006, 003

## Powód

Główny powód, dla którego człowiek wchodzi do WS_Memory, to **znaleźć coś**.
Jeśli wyszukiwanie jest wolne albo nieczytelne, reszta funkcji nie ma
znaczenia — ludzie wrócą do pytania kolegi na Slacku.

## Analiza

Baza będzie liczona w setkach tysięcy szuflad (lokalny pałac jednego
użytkownika ma 97 tys.). Przy tej skali surowa lista wyników jest bezużyteczna
— potrzebne są trzy informacje przy każdym trafieniu: **z której przestrzeni**,
**jak mocno pasuje** i **kto to napisał** (człowiek czy AI, zweryfikowane czy
nie).

Wyszukiwanie semantyczne ma nieintuicyjną własność: zwraca wyniki zawsze, tylko
coraz słabsze. Dlatego interfejs musi pokazywać trafność, a wyniki poniżej
progu wyraźnie oddzielać, zamiast udawać, że to też odpowiedzi.

Dwa tryby są potrzebne, bo odpowiadają na różne pytania: „czy ktoś coś o tym
wie" (semantyczne) i „gdzie dokładnie występuje ta nazwa" (leksykalne).

## Rozwiązanie

1. Ekran `/` — pole wyszukiwania, wyniki z: przestrzenią, trafnością, autorem,
   znacznikiem weryfikacji, datą i fragmentem z podświetleniem.
2. Filtry: przestrzeń, klasa wiedzy (dokument / notatka / dziennik / transkrypt),
   zakres daty, tylko zweryfikowane.
3. Przełącznik trybu: semantyczny / leksykalny, z jednozdaniowym wyjaśnieniem
   różnicy w interfejsie (nie każdy użytkownik wie, czym się różnią).
4. Wyniki słabe (poniżej progu trafności) w zwiniętej sekcji „dalsze, słabiej
   pasujące" — widoczne, ale nieudające odpowiedzi.
5. Ekran `/s/:space` — drzewo dokumentów przestrzeni, ostatnie zapisy agentów,
   liczby.
6. Ekran `/memory` — surowa pamięć z filtrami; osobno, bo to inny rodzaj treści
   niż dokumentacja i nie powinien się z nią mieszać w jednej liście.
7. Ekran `/s/:space/:slug` — dokument: treść, autor, weryfikacja, odnośnik do
   historii, przycisk edycji.
8. Stan pusty z sensem: brak wyników mówi, **czego** szukano i proponuje
   szersze kryteria albo zgłoszenie luki w dokumentacji.

## Kryteria ukończenia

- Wyszukanie frazy po polsku zwraca trafienia z widoczną przestrzenią,
  trafnością i autorem.
- Każdy wynik pokazuje, czy napisał go człowiek czy AI, i czy jest zweryfikowany.
- Filtry działają łącznie (przestrzeń + klasa + data).
- Wyniki o niskiej trafności są oddzielone od dobrych.
- Brak wyników daje komunikat z podpowiedzią, nie pustą stronę.
- Widok działa na szerokości telefonu (baza wiedzy czytana też z telefonu).
- Wyszukiwanie na bazie testowej z 10 tys. szuflad odpowiada poniżej sekundy
  (pomiar udokumentowany w sekcji „Co zostało zrobione").
