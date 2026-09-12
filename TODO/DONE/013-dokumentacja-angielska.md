---
tags: [ws-memory, todo, dokumentacja, i18n]
---

# TODO-013 — Angielskie odpowiedniki dokumentacji

**Utworzono:** 2026-09-12 18:29 · **Stan:** ✅ **UKOŃCZONE 2026-09-12 18:48** · **Zależności:** brak

## Powód

Ustalono, że dokumentacja ma być dwujęzyczna: polska wiodąca, angielska
równoległa. Kod jest już w całości po angielsku, więc osoba nieznająca
polskiego przeczyta źródła — ale nie zrozumie **dlaczego** są takie, a to
właśnie mieszka w `docs/` i w decyzjach `D-0xx`.

Angielska wersja przyda się też agentom AI: modele pracują na angielskim
znacznie pewniej, a instrukcje projektowe będą wystawiane jako zasoby MCP
(D-013).

## Analiza

Do przetłumaczenia jest komplet: `AGENTS.md`, `README.md`, `docs/01`–`docs/07`,
spec projektowy i `TODO/README.md`. To około 1500 wierszy — jednorazowo dużo,
ale utrzymanie jest tanie, jeśli tłumaczenie idzie w tym samym commicie co
zmiana oryginału.

Ryzyko jest jedno i trzeba mu zapobiec konstrukcyjnie: **rozjazd wersji**.
Zapobiegamy tak, że polska jest wiodąca (rozstrzyga spory), a każdy plik
angielski nosi w nagłówku informację, z jakiej wersji polskiego oryginału
został przetłumaczony.

Czego **nie** tłumaczymy: `CHANGELOG.md` (zapis historyczny, rośnie w
nieskończoność), zadań w `TODO/` (robocze, krótkotrwałe) i opisów commitów.
Tłumaczenie ich kosztowałoby stale, a nie służy nikomu poza archiwum.

## Rozwiązanie

1. `docs/en/` — struktura lustrzana wobec `docs/`, nazwy plików angielskie
   (`01-architecture.md`, `02-data-model.md`, …).
2. `AGENTS.en.md` i `README.en.md` w katalogu głównym, z odsyłaczem w obie
   strony w nagłówku każdego pliku.
3. Nagłówek każdego pliku angielskiego: „Translated from `docs/NN-....md`
   (<data ostatniej synchronizacji>). The Polish version is authoritative."
4. Tłumaczenie decyzji `D-001`–`D-015` z zachowaniem numeracji — numer
   decyzji jest wspólnym punktem odniesienia obu wersji.
5. Uzupełnienie `AGENTS.md` o wskazanie, że zmiana dokumentu obejmuje jego
   angielski odpowiednik (już dopisane).
6. Prosty test spójności: skrypt sprawdzający, czy każdy plik z `docs/` ma
   odpowiednik w `docs/en/` i czy numery decyzji się zgadzają.

## Kryteria ukończenia

- Każdy plik z `docs/` ma odpowiednik w `docs/en/`.
- Numery decyzji `D-0xx` są identyczne w obu wersjach.
- Nagłówek każdego pliku angielskiego wskazuje oryginał i datę synchronizacji.
- Skrypt spójności przechodzi i jest wpięty w `make`.
- `AGENTS.en.md` i `README.en.md` istnieją i odsyłają do wersji polskich.


---

## Co zostało zrobione

**Ukończono:** 2026-09-12 18:48

### Kryteria ukończenia — weryfikacja

| Kryterium | Wynik |
|---|---|
| każdy plik z `docs/` ma odpowiednik w `docs/en/` | ✅ siedem dokumentów + spec |
| numery decyzji identyczne w obu wersjach | ✅ D-001…D-015, sprawdzane skryptem |
| nagłówek wskazuje oryginał i datę synchronizacji | ✅ w każdym pliku angielskim |
| skrypt spójności przechodzi i jest w `make` | ✅ `make sprawdz-dokumentacje` |
| `AGENTS.en.md` i `README.en.md` istnieją | ✅ z odsyłaczami do wersji polskich |

### Co powstało

`AGENTS.en.md` (406 wierszy), `README.en.md`, `TODO/README.en.md`, `docs/en/`
z sześcioma dokumentami i specem, `scripts/sprawdz-dokumentacje.py` wpięty
w `make`. Razem około 1700 wierszy tłumaczenia.

### Dlaczego zrobione teraz, a nie później

Zadanie wykonane poza kolejnością, na wniosek: **każde kolejne zadanie dokłada
treści do przetłumaczenia**, więc koszt rośnie liniowo z czasem zwlekania.
Przy dwóch ukończonych zadaniach dokumentacja miała 2225 wierszy; po TODO-002
i TODO-005 miałaby ich znacznie więcej, a tłumaczenie odbywałoby się i tak.

### Czego skrypt nie sprawdza i dlaczego to ważne

Wyłapuje brak odpowiednika, rozjazd numerów decyzji, brak nagłówka i plik bez
wpisu w mapie. **Nie sprawdza, czy treść angielska odpowiada polskiej** — żaden
skrypt tego nie zrobi. Sprawdzony przeciwko obu rodzajom usterki: usunięty plik
i podmieniony numer zostają wykryte, po przywróceniu wraca zielone. Testowanie
własnego testu jest tu istotne, bo skrypt, który zawsze przechodzi, jest gorszy
od jego braku — daje fałszywe poczucie kontroli.

### Rozjazdy wykryte przy tłumaczeniu

Tłumaczenie okazało się skutecznym przeglądem dokumentacji — czytanie zdanie po
zdaniu ujawniło pięć nieaktualnych miejsc, wszystkie poprawione w oryginałach:

1. `README.md` twierdził, że implementacja jest nierozpoczęta, choć TODO-000
   i TODO-001 były ukończone.
2. `docs/05-deployment.md` pisał, że `docker-compose.yml` jeszcze nie istnieje,
   a polecenie backupu używało **nieistniejącej roli** `ws` (jest `postgres`).
3. `AGENTS.md` opisywał `mempalace` jako komponent, który „umie minować" — co
   wprost przeczy D-012.
4. `AGENTS.md` miał nieaktualną datę ostatniej aktualizacji i mówił o siedmiu
   działających usługach, gdy działa sześć.
5. Graf zależności w `TODO/README.md` nie znał `TODO-012` ani `TODO-013`
   i pokazywał anulowane `TODO-010` jako aktywne.

To jest dokładnie ten rodzaj rozjazdu, przed którym ma chronić „Definicja
ukończenia" dopisana do `AGENTS.md` w tym samym zadaniu.

### Czego nie tłumaczymy i dlaczego

`CHANGELOG.md` (zapis historyczny, rośnie bez końca), zadania w `TODO/`
(robocze i krótkotrwałe) oraz opisy commitów. Tłumaczenie ich kosztowałoby
stale, a nie służyłoby nikomu poza archiwum. Zapisane w regule językowej
w `AGENTS.md`.

Spec projektowy przetłumaczono, ale **oznaczono jako zapis historyczny**:
zamyka się na D-009, a późniejsze D-010…D-015 zmieniły trzy opisane w nim
rzeczy. Zamiast go przepisywać — co zatarłoby, jak myślenie się rozwijało —
dopisano adnotację wskazującą, co się zdezaktualizowało.
