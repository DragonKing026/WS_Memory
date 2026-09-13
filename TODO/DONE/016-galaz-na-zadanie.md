---
tags: [ws-memory, todo, proces, ci, github, bezpieczenstwo]

---

# TODO-016 — Gałąź na zadanie, sprawdzenia zawężone ścieżkami

**Utworzono:** 2026-09-13 12:55 · **Stan:** ✅ **UKOŃCZONE 2026-09-13 13:36** · **Zależności:** 014

## Powód

Ruleset „Ochrona gałęzi głównej" istnieje i wymaga pull requesta oraz zielonych
checków. **I jest łamany przy każdym commicie.** Skrypt wypychający pcha prosto
na `main` rolą administratora, co widać w jego własnym wyjściu:

```
remote: Bypassed rule violations for refs/heads/main:
remote: - 2 of 2 required status checks are expected.
remote: - Changes must be made through a pull request.
```

Reguła, którą się omija, nie chroni przed niczym. Do tego każda zmiana uruchamia
wszystkie sprawdzenia: poprawka literówki w dokumentacji stawia Postgresa,
instaluje Composera i uruchamia PHPStana.

## Analiza

**Odrzucone: stałe gałęzie warstwowe** (`frontend`, `backend`, `docs`). Zmiany
w tym repozytorium nie dzielą się po warstwach, bo wymuszają to jego własne
reguły — każda zmiana ma wpis w `CHANGELOG.md`, a dokumentacja idzie w tym samym
commicie w dwóch językach. Do tego `CHANGELOG.md` dopisuje się na górze pliku,
czyli w miejscu, w którym równoległe gałęzie konfliktują zawsze.

**Wybrane: gałąź na zadanie.** Struktura `TODO-NNN` już istnieje, więc mapowanie
jest naturalne (`todo-015-aktualizacja-mempalace`). Gałąź żyje godziny, nie
tygodnie, więc konflikt na CHANGELOGU to drobny rebase, a nie stan trwały.

**Pułapka przy zawężaniu ścieżkami.** Pominięte zadanie **nie zgłasza się jako
zielone** — dla reguły ochrony jest wiecznie oczekujące i zablokuje pull request,
którego nie dotyczy. Dlatego ruleset ma wymagać jednego zadania-bramki, które
wykonuje się zawsze i samo rozstrzyga, że pominięcie jest w porządku.

**Kolejność ma znaczenie.** Nie da się przestawić tego jednym ruchem: gdyby
w jednym pull requeście pominąć zadanie, którego ruleset nadal wymaga, ten pull
request zablokowałby sam siebie. Stąd dwa etapy z przestawieniem reguły
pomiędzy nimi.

## Rozwiązanie

1. Etap pierwszy: zadanie-bramka „Wynik sprawdzenia" w `szybkie.yml`, wszystkie
   zadania nadal bezwarunkowe. Pull request przechodzi na starych wymaganiach.
2. Przepisany `scripts/wypchnij.sh`: gałąź zadania → sprawdzenia lokalne →
   push gałęzi → pull request → oczekiwanie na checki → scalenie (merge commit,
   nie squash — commity po każdym zamkniętym kroku mają zostać w historii).
3. Przestawienie rulesetu: wymagany check to „Wynik sprawdzenia".
4. Etap drugi: zawężenie ścieżkami. Zmiana wyłącznie we frontendzie nie
   uruchamia zadania backendu i odwrotnie; zakres liczony z różnicy wobec `main`.
5. Pełne sprawdzenie tylko po scaleniu na `main`, plus harmonogram i ręcznie.
6. Decyzja w `docs/06-decyzje.md` i `docs/en/06-decisions.md`; opis procesu
   w `docs/09-ci.md` i `docs/en/09-ci.md`; zasada gałęzi w `AGENTS.md`.

## Kryteria ukończenia

- `git push` prosto na `main` jest odrzucany, a w dzienniku nie ma już linii
  „Bypassed rule violations".
- Pull request zmieniający wyłącznie `docs/**` nie uruchamia PHPUnita ani
  PHPStana, a mimo to daje się scalić — bramka jest zielona, nie oczekująca.
- Pull request zmieniający `backend/**` uruchamia zadanie backendu.
- `scripts/wypchnij.sh` przeprowadza całą drogę od gałęzi do scalenia bez
  ręcznego kroku i bez omijania reguły.
- Pełne sprawdzenie nie rusza na pull requeście, rusza po scaleniu na `main`.

## Co zostało zrobione

**2026-09-13 13:36**

Zrobione w dwóch pull requestach, bo inaczej się nie dało: gdyby w jednym ruchu
pominąć zadanie, którego ruleset nadal wymaga, ten pull request zablokowałby sam
siebie.

**#4 — model pracy.** Zadanie-bramka `Wynik sprawdzenia`, przepisany
`scripts/wypchnij.sh` (gałąź → sprawdzenia lokalne → push → pull request →
checki → merge commit, bez `--admin`), D-031 w obu językach, zasada gałęzi
w `AGENTS.md` i `AGENTS.en.md`. Pierwszy push w historii tego repozytorium bez
linii `Bypassed rule violations`.

**#5 — zawężanie.** Zadanie `Zakres zmian` liczy różnicę wobec punktu rozejścia
z `main` i wystawia dwie flagi. Logika sprawdzona lokalnie na dziesięciu
przypadkach **zanim** pojechała na CI: sam frontend, sam backend, sama
dokumentacja, samo zadanie, compose, workflow, nginx, postgres, obie strony
naraz, `.env.example`.

**Ruleset** przestawiony na jeden wymagany check. Musiał to zrobić człowiek
w interfejsie: `PATCH /repos/.../rulesets/{id}` zwraca 404 dla tokenu
z `gh auth login`, mimo zakresu `repo` i uprawnień administratora. Odczyt tego
samego zasobu działa — GitHub nie dopuszcza tokenów aplikacji OAuth do zapisu
rulesetów. Warto o tym pamiętać przy każdej następnej zmianie reguł.

### Dwie rzeczy wyszły dopiero w praktyce

**Wyzwalacz szybkiego sprawdzenia był zły.** Stało w nim `branches: [main]`, więc
push na gałąź zadania nie uruchamiał **niczego** aż do otwarcia pull requesta.
Przy modelu, który właśnie wprowadzaliśmy, znaczyło to brak feedbacku dokładnie
tam, gdzie jest najbardziej potrzebny. Zauważone przez właściciela projektu, nie
przeze mnie. Teraz rusza na każdej gałęzi, bez `pull_request` — statusy
przypinają się do commita, więc drugi wyzwalacz dawałby dwa identyczne przebiegi.

**Pełne sprawdzenie tylko po scaleniu było gorszym pomysłem, niż sądziłem.**
Zapisałem ten kompromis świadomie i nazwałem jego koszt, ale koszt zmaterializował
się natychmiast: zmiana dotykająca testów E2E i konfiguracji frontendu poszła do
scalenia **bez ani jednego uruchomienia pełnego stosu**. Usterka integracyjna
wykryta minutę po scaleniu jest już usterką na `main`. Poprawione: rusza na pull
requeście, a po scaleniu również, bo pull request sprawdza commit scalający,
którego na `main` potem nie ma. Kryterium ukończenia „pełne sprawdzenie nie rusza
na pull requeście" zostało tym samym **odwrócone** i tak jest lepiej.

### Czego nie zrobiono

**`git push` prosto na `main` nadal przechodzi administratorowi.** Wyjątek
w rulesecie zostaje jako zawór bezpieczeństwa na wypadek trwale zepsutego
wymaganego checka. Zmieniło się to, że nikt go nie używa, a skrypt wypychający
nie umie tego zrobić — dyscyplina jest proceduralna i narzędziowa, nie absolutna.
Usunięcie wyjątku to jedno kliknięcie, gdy uznamy je za potrzebne.

**Uruchomienie zadania backendu przy zmianie w `backend/` zweryfikowane
logiką i testem lokalnym, nie przebiegiem na żywo** — pierwsza taka zmiana
(TODO-015) to potwierdzi.

### Efekt uboczny, którego się nie spodziewałem

Zawężanie odsłoniło dwie niezależne przyczyny tego, że przebieg pobierał 2,3 GB
za każdym razem. Druga była nieoczywista: `actions/cache` zapisuje **wyłącznie po
sukcesie zadania**, a zadanie padało na E2E — więc dopóki cokolwiek było czerwone,
cache nie miał prawa powstać. Po rozdzieleniu odczytu i zapisu oraz naprawie E2E
cache zapisał się pierwszy raz w historii repozytorium (`embeddings-BAAI-bge-m3-v2`,
1278 MB), a następny przebieg zameldował `Cache restored successfully`.
