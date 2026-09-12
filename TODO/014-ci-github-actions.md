---
tags: [ws-memory, todo, ci, github-actions, testy]
---

# TODO-014 — CI na GitHub Actions w dwóch prędkościach

**Utworzono:** 2026-09-12 22:10 · **Stan:** do zrobienia · **Zależności:** 002

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
