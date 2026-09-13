---
noteId: "894f53d1af8311f18a50cfad3ca0cc8a"
tags: []
name: "ws-memory-document"
description: "Jak pisać firmową dokumentację Web Systems — co jest notatką, co dokumentem, jak nazwać adres, co napisać w opisie zmiany. Użyj, gdy masz coś zapisać do wspólnej bazy wiedzy."

---

# Jak pisać do firmowej bazy wiedzy

## Najpierw rozstrzygnij, którą rzecz piszesz

Trzy klasy wiedzy, trzy różne narzędzia. Pomylenie ich to najczęstszy sposób
zaśmiecenia bazy.

| To jest… | Narzędzie | Wersjonowane | Kto to przeczyta |
|---|---|---|---|
| **zapis sesji** — co się działo, czego się dowiedziałeś | `ws_diary_write` | nie | Ty, w kolejnej sesji |
| **ustalenie** — pojedynczy fakt, decyzja, obserwacja | `ws_remember` | nie | ktoś szukający tego tematu |
| **dokumentacja** — rzecz, do której się wraca | `ws_doc_write` | tak, pełne rewizje | człowiek, za pół roku |

Sprawdzian jest jeden: **czy ktoś będzie tego szukał, nie wiedząc, że to
istnieje?** Jeśli tak — to dokument. Jeśli szukałby tego tylko ten, kto już
wie, że gdzieś to zapisano — to notatka.

Nie rób dokumentu z czegoś, co jest przebiegiem jednej sesji. Dokumentacja,
w której leży dziennik pracy, przestaje być czytana.

## Zanim napiszesz — sprawdź, czy to już istnieje

`ws_doc_list` i `ws_search`. **Nowa rewizja istniejącego dokumentu jest prawie
zawsze lepsza niż drugi dokument o tym samym.** Dwa dokumenty o jednej rzeczy
to nie nadmiar informacji, to pytanie „który jest aktualny", na które nikt nie
zna odpowiedzi.

Nowy dokument zakładasz, gdy temat jest naprawdę osobny — nie gdy Twoje ujęcie
jest inne.

## Adres dokumentu

Małe litery bez ogonków, cyfry, łączniki; ukośnik działa jak folder:

```
wdrozenia/backup-bazy
klienci/tenanto/integracja-platnosci
konwencje/nazewnictwo-galezi
```

Adres jest trwały i widoczny w linkach. Nazywaj **rzecz**, nie okazję:
`wdrozenia/backup-bazy`, nie `notatki-ze-spotkania-12-09`.

## Struktura dokumentu

Zaczynaj od tego, po co ktoś tu trafił, nie od historii.

1. **Jedno zdanie: co to jest i kogo dotyczy.**
2. **Jak to działa / jak to zrobić** — konkret, polecenia, ścieżki, nazwy.
3. **Dlaczego tak** — jeśli wybór był nieoczywisty. To ta część, która za rok
   powstrzyma kogoś przed „uproszczeniem" działającego rozwiązania.
4. **Czego to nie obejmuje** — granice są informacją. Dokument, który nie mówi,
   gdzie się kończy, bywa czytany jako kompletny.

Po polsku. Konkretnie. Bez wstępu o tym, jak ważny jest ten temat.

## Opis zmiany (`change_note`)

To **jedyny** opis, jaki zobaczy człowiek przeglądający historię dokumentu.
Napisz, **co się zmieniło i po co**, nie że „aktualizacja".

```
źle:  aktualizacja dokumentu
źle:  poprawki
dobrze: dopisany krok przywracania z kopii — brakowało go, więc nikt nie
        wiedział, że backup w ogóle da się odtworzyć
```

## Gdzie to ląduje

**Zapis bez wskazanej przestrzeni idzie do prywatnej przestrzeni właściciela
tokena.** To nie jest awaria — to zabezpieczenie: Twoja pomyłka nie zaśmieca
wspólnej bazy. Ale znaczy też, że **dokument do zespołu musi mieć jawnie
podaną przestrzeń**. Nie wiesz, jakie masz — zapytaj `ws_status`.

Jeśli przestrzeń wymaga przeglądu przed publikacją, `ws_doc_write` odmówi
i odeśle Cię do `ws_propose`. To nie błąd, tylko inna droga: treść czeka wtedy
na człowieka.

## Reguła, o której trzeba powiedzieć wprost

Piszesz jako AI. Dokument dostaje status **„autor: AI"** i osobną flagę
**„zweryfikowane przez człowieka"**, której **sam sobie nie postawisz** —
narzędzia do tego nie ma i nie będzie.

**Jeśli napisałeś dokument, którego nikt nie zweryfikuje, powiedz to wprost
w podsumowaniu sesji.** Człowiek ma wiedzieć, że w bazie leży coś, co ma jego
zaufanie tylko dlatego, że nikt tego jeszcze nie przeczytał.

Nie zmyślaj konkretów, żeby dokument wyglądał na kompletny. **Luka nazwana
w dokumencie jest wartościowa; luka wypełniona domysłem jest szkodliwa**, bo
wygląda identycznie jak wiedza.
