---
noteId: "8a8ba060afaf11f18a50cfad3ca0cc8a"
tags: []
description: "Wyślij wiedzę z lokalnego pałaca MemPalace do firmowej bazy"
argument-hint:
  - "skrzydło albo zakres, np. wing_websystems albo --od 2026-09-01"

---

Do wysłania: **$ARGUMENTS**

Wysyłką zajmuje się skrypt, a nie Ty: czytanie stu tysięcy szuflad przez
wywołania narzędzi zjadłoby kontekst i trwałoby godzinami, a skrypt robi to
jednym procesem i jednym połączeniem do pałaca.

**Najpierw podgląd, zawsze.** Serwer nic wtedy nie zapisuje, a Ty widzisz, ile
szuflad pasuje do filtra i co zostanie pominięte przez filtr sekretów:

```bash
"${CLAUDE_PLUGIN_ROOT}/skrypty/wyslij.py" --podglad <filtry>
```

Jeśli liczba się zgadza, to samo bez `--podglad`.

Filtry, które ma skrypt: `--skrzydlo`, `--pokoj`, `--od`, `--do`, `--limit`,
`--przestrzen`, `--partia`. Bez `--przestrzen` o miejscu decyduje **reguła
lądowania**: skrzydło z potwierdzonym mapowaniem idzie do przestrzeni
zespołowej, wszystko inne do prywatnej przestrzeni właściciela tokena (D-014).
Nie podawaj `--przestrzen` „dla pewności" — to jest właśnie ta decyzja, która ma
zapadać świadomie.

Zanim wyślesz cokolwiek dużego:

1. `mempalace_list_wings` — zobacz, co w ogóle jest i ile tego jest.
2. Wyślij **jedno skrzydło** i sprawdź w bazie (`ws_search`), czy treść wygląda
   tak, jak powinna. Sto tysięcy szuflad wysłanych bez sprawdzenia jednej to sto
   tysięcy szuflad do wycofania.

Powtórzenie polecenia jest bezpieczne: para (replika, identyfikator szuflady)
ma unikalność po stronie serwera, więc druga wysyłka **aktualizuje** wiersz,
zamiast tworzyć drugi. Przerwany przebieg powtarza się bez sprzątania.

Wycofanie partii: `POST /api/publish/<id>/revert` — skrypt wypisuje
identyfikatory partii na końcu.
