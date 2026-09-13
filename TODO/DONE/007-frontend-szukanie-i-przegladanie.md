---
noteId: "7a6c84e1aeb211f1997d030a3cd38ca7"
tags: [ws-memory, todo, frontend, wyszukiwanie, ui]

---

# TODO-007 — Frontend: wyszukiwanie i przeglądanie bazy

**Utworzono:** 2026-09-12 16:03 · **Ukończono:** 2026-09-13 11:17 · **Stan:** zrobione · **Zależności:** 006, 003

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

---

## Co zostało zrobione

**2026-09-13 11:17**

### Zakres wyszedł poza frontend, i to trzeba było ustalić najpierw

Zadanie było opisane jako frontendowe, ale **REST API nie miało wyszukiwania
w ogóle** — istniało wyłącznie przez bramkę MCP, czyli dla agentów. Bez
endpointu nie było czego wyświetlać, więc backend wszedł w zakres.

Dwa ograniczenia wyszły przy analizie i obie zostały zapisane jako decyzje,
zamiast obejść:

- **Pałac nie umie trybu leksykalnego** (D-029). `mempalace_search` przyjmuje
  `query`, `wing`, `room`, `since`, `before` i `max_distance` — parametru trybu
  nie ma. Tryb leksykalny robimy u siebie, w PostgreSQL, z **wypowiedzianą
  wprost konsekwencją**: nie sięga treści szuflad innych niż dokumenty, bo ta
  mieszka wyłącznie w pałacu. Odpowiedź API niesie własne pokrycie, żeby
  interfejs nie musiał tego zgadywać ani powtarzać.
- **Postgres nie ma polskiej konfiguracji tekstowej** (D-030). `simple` bez
  rdzeniowania to właściwy wybór, nie ustępstwo: tryb leksykalny odpowiada na
  „gdzie dokładnie występuje ta nazwa”, a przy `PalaceWing` rdzeniowanie dokłada
  trafienia błędne. Odmianę obsługuje tryb semantyczny. `pg_trgm` odrzucone
  świadomie — wymaga uprawnienia `CREATE` na bazie, którego rola aplikacji
  celowo nie ma.

### Backend

`GET /api/search` (oba tryby, filtry), `GET /api/memory` (przeglądanie),
stronicowanie listy dokumentów. Migracja `Version20260912000006` z indeksami GIN,
sprawdzona w obie strony.

### Frontend

Ekran `/` jako wyszukiwarka, drzewo dokumentów przestrzeni, ekran dokumentu
z Markdownem, ekran `/memory`. Podświetlenie trafień jedzie jako **struktura,
nigdy jako HTML z bazy** — treść pisana przez ludzi i agentów może zawierać
dowolny znacznik.

### Cztery rzeczy naprawione po zobaczeniu ich na ekranie albo w pomiarze

Żadnej z nich nie widać było w kodzie:

1. **`D-029` nie dawało się znaleźć.** Tokenizowałem zapytanie w PHP, a Postgres
   czyta `D-029` jako lekseny `d` **oraz `-029`** — z minusem, bo to liczba ze
   znakiem. Szukanie identyfikatora decyzji zwracało pustkę, czyli tryb
   „znajdź dokładnie tę nazwę” nie działał dla nazw, których się szuka. Teraz
   zapytanie tokenizuje ten sam parser co treść. Dwa testy padają po powrocie do
   ręcznej tokenizacji — sprawdzone przez cofnięcie poprawki.
2. **Indeks GIN nie był używany.** Zapytanie napisane „od dokumentów” kazało
   planiście czytać wszystkie rewizje i liczyć `to_tsvector` dla każdej.
   Przebudowane tak, by startowało od predykatu tekstowego: **157 ms → 2 ms**.
3. **Procent trafności w trybie dokładnym mylił.** `ts_rank` ma inną skalę niż
   podobieństwo kosinusowe — trafienie idealne pokazywało się jako „14%”.
   Sam to udokumentowałem w `SearchHit` i mimo to wyrenderowałem jako procent.
4. **Layout na telefonie był zepsuty** — panel boczny zabierał 256 z 390 pikseli.

Do tego dwie usterki wywleczone przez samą bazę testową, obie na głównych
ścieżkach i obie niezależne od danych testowych: **lista dokumentów bez
stronicowania** przewracała się na 10 tys. wpisów, oddając błąd krytyczny PHP
jako HTML **po** nagłówku 200; **zapis tej samej treści dwa razy** kończył się
błędem 500, bo pałac scala duplikaty, a rejestr odrzucał je przez indeks
unikalny — czyli agent ponawiający wywołanie dostawał nieczytelny błąd.

## Kryteria ukończenia — rozliczenie

| Kryterium | Stan |
|---|---|
| Fraza po polsku zwraca trafienia z przestrzenią, trafnością i autorem | ✅ „wolne dni” → *Zasady urlopów*, „jak rozliczyć hotel” → *Zwrot kosztów podróży* — bez wspólnych słów |
| Każdy wynik pokazuje człowiek/AI i weryfikację | ✅ w wynikach, w drzewie i na ekranie dokumentu |
| Filtry działają łącznie (przestrzeń + klasa + data) | ✅ |
| Wyniki o niskiej trafności oddzielone | ✅ próg **względny**, nie stały — patrz niżej |
| Brak wyników daje komunikat z podpowiedzią | ✅ nazywa frazę i podaje trzy realne przyczyny |
| Widok działa na szerokości telefonu | ✅ sprawdzone przy 390 px, bez przewijania poziomego |
| 10 tys. szuflad poniżej sekundy | ✅ patrz pomiar |

### Próg słabych wyników jest względny i to wynik pomiaru

Trafność właściwego dokumentu: 0,477–0,611. Trafność szumu: 0,29–0,44.
**Przedziały zachodzą na siebie** — 0,438 to szum w jednym zapytaniu, a 0,477
odpowiedź w innym. Żadna stała ich nie rozdziela, bo skala przesuwa się razem
z tym, jak dobrze zapytanie pasuje do czegokolwiek. Stały jest **odstęp**:
właściwa odpowiedź odstaje od reszty w obrębie jednego zapytania. Stąd „mocny”
znaczy „w granicach jednej czwartej od najlepszego wyniku w tej odpowiedzi”.

### Pomiar wydajności

Baza testowa: **10 037 szuflad** w pałacu, **10 005 dokumentów** w bazie.

| Co | Czas |
|---|---|
| Wyszukiwanie semantyczne (20 wyników) | **0,10 s** |
| Wyszukiwanie leksykalne, zapytanie wybiórcze | **0,02 s** |
| Wyszukiwanie leksykalne, słowo w każdym dokumencie | **0,40 s** |
| Przeglądanie pamięci (20 wpisów) | **0,23 s** |
| Lista dokumentów (strona po 100) | **0,25 s** |

Wszystko z narzutem trybu deweloperskiego Symfony (samo `/api/me` to 0,02–0,04 s),
więc w produkcji będzie szybciej. Najgorszy przypadek leksykalny to 151 ms samego
SQL-a — słowo obecne w dziesięciu tysiącach dokumentów, czyli sytuacja, w której
indeks nie ma czego zawęzić.

Po pomiarze baza testowa została usunięta, token agenta unieważniony,
a tymczasowe podniesienie limitu tempa skasowane.

## Czego tu nie ma

- **Graf wiedzy na ekranie `/memory`** — widać szuflady, dziennik i transkrypty,
  ale nie fakty grafu. Fakt nie jest szufladą i nie wraca z listowania rejestru;
  potrzebuje własnego widoku, a ten należy do osobnego zadania.
- **Historia rewizji i edytor** — przyciski są na ekranie dokumentu, ale
  wyłączone, z widocznym zdaniem, że jeszcze nie działają. To `TODO-008`.
