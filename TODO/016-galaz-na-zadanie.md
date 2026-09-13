---
tags: [ws-memory, todo, proces, ci, github, bezpieczenstwo]

---

# TODO-016 — Gałąź na zadanie, sprawdzenia zawężone ścieżkami

**Utworzono:** 2026-09-13 12:55 · **Stan:** do zrobienia · **Zależności:** 014

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
