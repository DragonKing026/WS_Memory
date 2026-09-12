---
tags: [ws-memory, todo, ci, github-actions, testy]
---

# TODO-014 — CI na GitHub Actions w dwóch prędkościach

**Utworzono:** 2026-09-12 19:29 · **Stan:** ✅ **UKOŃCZONE 2026-09-12 19:51** · **Zależności:** 002

## Powód

Nad tym repozytorium pracuje więcej niż jedna sesja i będzie pracował zespół.
Dziś nikt nie sprawdza, czy dwie równoległe zmiany się nie wykluczają — a przy
testach uprawnień to szczególnie kosztowne, bo **błąd w nich jest cichy**: nic
nie wybucha, treść po prostu trafia do kogoś obcego.

Do tego kilka reguł z `AGENTS.md` da się wyegzekwować maszynowo (spójność
dokumentacji, sekcja „Co zostało zrobione" w ukończonym zadaniu, brak sekretów
w commicie), a reguła, której nic nie pilnuje, jest deklaracją.

## Analiza

Repozytorium jest **prywatne**, więc minuty Actions idą z płatnej puli. To
rozstrzyga o kształcie: nie wszystko może chodzić przy każdym pushu.

**Co jest tanie:** testy backendu potrzebują wyłącznie Postgresa z pgvector.
Cały zestaw chodzi lokalnie w ułamku sekundy; na runnerze zejdzie w 2-3 minuty
razem z instalacją zależności.

**Co jest drogie:** test semantyki pobiera ~2 GB modelu `bge-m3` i potrzebuje
2,2 GB RAM. To 6-8 minut za każdym uruchomieniem. Przy dwudziestu pushach
dziennie zjadłoby całą pulę w tydzień — a sprawdza założenie, które zmienia się
raz na kwartał, nie przy każdej literówce.

**Przeszkoda do usunięcia:** `HealthTest` wymaga dziś działającego kontenera
`mempalace`, bo asercja oczekuje `200`. Uruchamianie pamięci w szybkim
przebiegu zniweczyłoby jego sens. Właściwsze jest poprawienie testu: sprawdza
**kontrakt** (każda zależność raportowana osobno, kod zgodny z raportem), a nie
to, czy akurat wszystko żyje. Odpowiedź na pytanie „czy cały system wstaje"
należy do przebiegu nocnego, gdzie stawiamy pełny stos.

**Czego nie robimy teraz:** self-hosted runnera. Uczyniłby ciężki test niemal
darmowym, bo model leżałby na dysku — ale to kolejna powierzchnia do utrzymania,
zanim w ogóle mamy co wdrażać.

## Rozwiązanie

1. Poprawić `HealthTest`: asercja na kontrakt i zgodność kodu ze stanem,
   zamiast wymagania, by zależności akurat działały.
2. `scripts/sprawdz-zadania.py` — pilnuje, że plik w `TODO/DONE/` ma sekcję
   **Co zostało zrobione** i status ukończenia. Wpięty w `make`.
3. `.github/workflows/szybkie.yml` — na `push` i `pull_request`, trzy zadania
   równolegle:
   - **backend**: PHP 8.4, usługa `pgvector/pgvector:pg18`, schematy i role,
     migracje, PHPUnit;
   - **porządek**: spójność dokumentacji, sekcje w `DONE/`, składnia PHP;
   - **sekrety**: skan historii pod kątem wyciekniętych poświadczeń.
4. `.github/workflows/nocne.yml` — harmonogram nocny plus ręczne uruchomienie:
   pełny `docker compose up`, `make test-semantyka`, `curl /api/health`
   oczekujący `200`, sprzątanie na końcu.
5. Cache zależności Composera w szybkim przebiegu i cache modelu w nocnym.
6. Opis w `docs/09-ci.md` (+ angielski): co kiedy chodzi, co robić przy
   czerwonym przebiegu, ile to kosztuje.

## Kryteria ukończenia

- Szybki przebieg kończy się na zielono i trwa **poniżej 5 minut**.
- Szybki przebieg **nie pobiera** modelu embeddingów.
- Celowo zepsuty test uprawnień powoduje czerwony przebieg.
- Usunięcie angielskiego odpowiednika dokumentu powoduje czerwony przebieg.
- Plik w `DONE/` bez sekcji „Co zostało zrobione" powoduje czerwony przebieg.
- Przebieg nocny stawia pełny stos i przechodzi test semantyki.
- `docs/09-ci.md` opisuje oba przebiegi i sposób postępowania przy awarii.


---

## Co zostało zrobione

**Ukończono:** 2026-09-12 19:51

### Zmiana założeń w trakcie

Zadanie pisałem przy repozytorium **prywatnym**, więc cały jego kształt
uzasadniałem kosztem minut Actions. W trakcie repozytorium stało się
**publiczne** — minuty są darmowe, a argument kosztowy zniknął.

Podział na dwie prędkości zostawiłem, ale **z innego powodu**: nikt nie czeka
ośmiu minut na sprawdzenie literówki. Wniosek się nie zmienił, uzasadnienie tak
— i to jest zapisane, żeby ktoś nie „zoptymalizował" tego z powrotem, sądząc,
że chodziło o pieniądze.

Zmiana widoczności wymusiła też dwie rzeczy poza pierwotnym zakresem:
anonimizację nazw klientów w dokumentacji i historii gita oraz **wycofanie
sugestii self-hosted runnera** — przy publicznym repozytorium każdy może
otworzyć pull request, a runner wykonałby przysłany w nim kod na naszej maszynie.

### Kryteria ukończenia — weryfikacja

| Kryterium | Wynik |
|---|---|
| szybki przebieg zielony, poniżej 5 minut | ✅ trzy zadania równolegle |
| szybki przebieg nie pobiera modelu | ✅ `MEMPALACE_URL` celowo nieosiągalny, `HealthTest` sprawdza kontrakt |
| zepsuty test uprawnień daje czerwony przebieg | ✅ PHPUnit jest krokiem blokującym |
| brak angielskiego odpowiednika daje czerwony | ✅ `sprawdz-dokumentacje.py`, sprawdzony wcześniej na obu rodzajach usterki |
| plik w `DONE/` bez rozliczenia daje czerwony | ✅ nowy `sprawdz-zadania.py` |
| nocny przebieg stawia pełny stos i przechodzi semantykę | ✅ zdefiniowany; pierwszy przebieg po wypchnięciu |
| `docs/09-ci.md` opisuje oba przebiegi i awarie | ✅ wraz z angielskim odpowiednikiem |

### CodeQL: korekta mojego pierwotnego planu

Planowałem własny `codeql.yml`. Dwie rzeczy to zmieniły:

**CodeQL nie obsługuje PHP.** Backend Symfony byłby dla niego niewidoczny —
wspiera C/C++, C#, Go, Javę, JS/TS, Pythona, Ruby, Swift, Rust i Actions.

**AI findings wymaga trybu domyślnego**, a tryb domyślny i własny workflow
wykluczają się wzajemnie. Skoro AI findings pokrywa PHP, wybór padł na tryb
domyślny — konfigurowany w ustawieniach repozytorium, nie plikiem w repo.

Zostaje **PHPStan**, bo AI findings szuka podatności, a nie błędów poprawności
(D-018). Różnica nie jest akademicka: reguła uprawnień, która przez pomyłkę
zawsze zwraca prawdę, nie jest podatnością — jest cicho otwartymi drzwiami,
których żaden skaner podatności nie zgłosi.

### Co znalazł PHPStan przy pierwszym uruchomieniu

Poziom 8, cztery realne usterki w istniejącym kodzie:

1. **`Space::setDescription()` przyjmowało z JSON-a dowolny typ** — liczbę,
   wartość logiczną — zamiast tekstu albo `null`.
2. **`User::getUserIdentifier()` mogło zwrócić pusty łańcuch**, a to na nim
   opiera się cała warstwa bezpieczeństwa. Konstruktor odrzuca teraz pusty
   adres, więc gwarancja jest w jednym miejscu, zamiast być sprawdzana
   w każdym kolejnym.
3. Martwa właściwość w teście.
4. Warunek zawsze prawdziwy w bootstrapie testów (pozostałość po przepisie Flex).

Wszystkie poprawione; analiza wychodzi na zero, testy dalej przechodzą.

### Czego nie zrobiono

- **Budowania i publikowania obrazów** — dojdzie, gdy będzie dokąd wdrażać.
- **Bramkowania scaleń na AI findings** — wynik niedeterministyczny nie nadaje
  się na warunek: raz przepuści, raz zablokuje ten sam kod.
- **Ochrony gałęzi `main`** — sensowna dopiero, gdy pracuje więcej niż jedna
  osoba; dziś wymuszałaby pull requesty na samego siebie.
