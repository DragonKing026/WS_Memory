---
tags: [ws-memory, todo, maile, zaproszenia, panel-admina, audyt]
---

# TODO-017 — Wysyłka maili: szablony w panelu i dziennik

**Utworzono:** 2026-09-13 19:40 · **Stan:** do zrobienia · **Zależności:** 008

## Powód

**System nie wysyła ani jednego maila i nigdy nie wysyłał.** Nie ma zależności
`symfony/mailer`, nie ma `MAILER_DSN`, nie ma nadawcy. Zaproszenie wypisuje
link, a człowiek przekazuje go dalej sam.

Dopóki instancję stawia jedna osoba dla siebie, to działa. Przy wdrażaniu
zespołu przestaje: **link do zaproszenia pokazuje się raz** i ginie razem
z zamkniętym oknem, a jedyną drogą naprawy jest wystawienie nowego.

Dokumentacja w tym miejscu **kłamie**: `docs/05-deployment.md` opisuje
`WS_DOMAIN` jako „domena publiczna (certyfikat, **linki w mailach**)". Opis
mówi o mailach, których nie ma — a to jest wsad dla agentów AI, więc
nieaktualne zdanie zostaje potraktowane jako fakt i powielone.

## Analiza

### Czego nie wolno wpuścić do dziennika maili

**Treść zaproszenia zawiera działający token.** Dziennik przechowujący ciało
wiadomości byłby drugą kopią poświadczenia — obok bazy, która celowo trzyma
wyłącznie jego skrót, i w miejscu, do którego zagląda się swobodnie, bo „to
tylko logi". Dziennik zapisuje **metadane i nazwę szablonu**, nigdy treść
z podstawionymi wartościami.

To samo dotyczy podglądu w panelu: podgląd renderuje się na **wartościach
przykładowych**, nie na prawdziwym tokenie.

### Szablony edytowane przez człowieka a silnik szablonów

Backend **nie ma Twiga i mieć nie będzie** (D-008). Tu wychodzi to na dobre:
szablon edytowany w przeglądarce, renderowany silnikiem szablonów, jest
wykonywaniem cudzego kodu na serwerze. Twig ma tryb piaskownicy, ale jest to
zabezpieczenie, które trzeba utrzymywać, a nie właściwość.

Dlatego **podstawianie miejsc, nie szablonowanie**: `{{ imie }}`, `{{ link }}`
z **zamkniętej listy** dopuszczonej dla danego szablonu. Bez pętli, bez
warunków, bez wywołań. Nieznane miejsce to błąd walidacji przy zapisie, a nie
puste miejsce w wysłanym mailu.

### Wysyłka nie może blokować żądania

Zaproszenie wystawia się z panelu; niedostępny SMTP nie może zawiesić tego
żądania ani go wywrócić. Wysyłka idzie przez **Messenger** (worker już
istnieje), a dziennik pokazuje stan. Zaproszenie **istnieje** niezależnie od
tego, czy mail doszedł — link nadal da się skopiować z panelu, tak jak dziś.

### Retencja

Dziennik maili rośnie liniowo z użyciem. `docs/05-deployment.md` ma sekcję
o retencji — nowa tabela musi się w niej znaleźć, inaczej powtórzymy historię
dziennika audytu, który urósł do 40 tysięcy wpisów, zanim ktokolwiek spojrzał.

## Rozwiązanie

- [ ] **1.** `symfony/mailer` + `MAILER_DSN`, nadawca (`MAIL_FROM`, `MAIL_FROM_NAME`)
      w konfiguracji i w `.env.example`. W środowisku testowym transport `null`.
- [ ] **2.** Tabela `ws.mail_templates`: klucz szablonu, temat, treść, kto i kiedy
      zmienił. Zmiana szablonu **idzie do dziennika audytu**.
- [ ] **3.** Szablony domyślne wgrywane migracją, żeby świeża instancja miała działającą
      treść, zanim ktokolwiek cokolwiek edytuje.
- [ ] **4.** Podstawianie miejsc z zamkniętej listy per szablon, walidowane **przy
      zapisie** — nie przy wysyłce, kiedy jest za późno.
- [ ] **5.** Tabela `ws.mail_log`: adresat, szablon, temat, stan (`w kolejce`,
      `wysłany`, `nieudany`), powód porażki, znaczniki czasu. **Bez treści.**
- [ ] **6.** Wysyłka przez Messenger, z ponowieniem i widocznym stanem w dzienniku.
- [ ] **7.** Ekran w panelu administracyjnym: lista szablonów, edycja, podgląd na
      wartościach przykładowych, wysyłka próbna do siebie.
- [ ] **8.** Ekran dziennika maili: filtr po stanie i adresacie, powód porażki widoczny.
- [ ] **9.** Pierwszy szablon: **zaproszenie**. Kolejne dokładamy, gdy będą potrzebne —
      nie na zapas.
- [ ] **10.** Retencja dziennika maili opisana w `docs/05-deployment.md`.

## Kryteria ukończenia

- [ ] Wystawienie zaproszenia z panelu **wysyła maila** z działającym linkiem.
- [ ] Niedostępny SMTP **nie wywraca** wystawienia zaproszenia; wpis w dzienniku
  maili ma stan `nieudany` i czytelny powód, a link nadal da się skopiować.
- [ ] W `ws.mail_log` **nie ma treści maila ani tokena** — sprawdzone zapytaniem
  po całej tabeli, nie przeglądem kodu.
- [ ] Zmiana szablonu przez administratora zmienia treść następnego maila.
- [ ] Szablon z nieznanym miejscem (`{{ nieistniejace }}`) **nie zapisuje się**,
  z komunikatem mówiącym, które miejsca są dozwolone.
- [ ] Szablon nie jest wykonywany: wpisanie w treść konstrukcji silnika szablonów
  albo kodu trafia do maila **dosłownie**, jako tekst.
- [ ] Podgląd i wysyłka próbna używają **wartości przykładowych**, nie prawdziwego
  tokena.
- [ ] Nieudana wysyłka jest ponawiana, a dziennik pokazuje liczbę prób.
- [ ] `docs/05-deployment.md` przestaje kłamać o `WS_DOMAIN`.
