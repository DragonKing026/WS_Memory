---
tags: [ws-memory, todo, maile, zaproszenia, panel-admina, audyt]
---

# TODO-017 — Wysyłka maili: szablony w panelu i dziennik

**Utworzono:** 2026-09-13 19:40 · **Stan:** ✅ **UKOŃCZONE 2026-09-13** · **Zależności:** 008

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

- [x] **1.** `symfony/mailer` + `MAILER_DSN`, nadawca (`MAIL_FROM`, `MAIL_FROM_NAME`)
      w konfiguracji i w `.env.example`. W środowisku testowym transport `null`.
- [x] **2.** Tabela `ws.mail_templates`: klucz szablonu, temat, treść, kto i kiedy
      zmienił. Zmiana szablonu **idzie do dziennika audytu**.
- [x] **3.** Szablony domyślne wgrywane migracją, żeby świeża instancja miała działającą
      treść, zanim ktokolwiek cokolwiek edytuje.
- [x] **4.** Podstawianie miejsc z zamkniętej listy per szablon, walidowane **przy
      zapisie** — nie przy wysyłce, kiedy jest za późno.
- [x] **5.** Tabela `ws.mail_log`: adresat, szablon, temat, stan (`w kolejce`,
      `wysłany`, `nieudany`), powód porażki, znaczniki czasu. **Bez treści.**
- [x] **6.** Wysyłka przez Messenger, z ponowieniem i widocznym stanem w dzienniku.
- [x] **7.** Ekran w panelu administracyjnym: lista szablonów, edycja, podgląd na
      wartościach przykładowych, wysyłka próbna do siebie.
- [x] **8.** Ekran dziennika maili: filtr po stanie i adresacie, powód porażki widoczny.
- [x] **9.** Pierwszy szablon: **zaproszenie**. Kolejne dokładamy, gdy będą potrzebne —
      nie na zapas.
- [x] **10.** Retencja dziennika maili opisana w `docs/05-deployment.md`.

## Kryteria ukończenia

- [x] Wystawienie zaproszenia z panelu **wysyła maila** z działającym linkiem.
- [x] Niedostępny SMTP **nie wywraca** wystawienia zaproszenia; wpis w dzienniku
  maili ma stan `nieudany` i czytelny powód, a link nadal da się skopiować.
- [x] W `ws.mail_log` **nie ma treści maila ani tokena** — sprawdzone zapytaniem
  po całej tabeli, nie przeglądem kodu.
- [x] Zmiana szablonu przez administratora zmienia treść następnego maila.
- [x] Szablon z nieznanym miejscem (`{{ nieistniejace }}`) **nie zapisuje się**,
  z komunikatem mówiącym, które miejsca są dozwolone.
- [x] Szablon nie jest wykonywany: wpisanie w treść konstrukcji silnika szablonów
  albo kodu trafia do maila **dosłownie**, jako tekst.
- [x] Podgląd i wysyłka próbna używają **wartości przykładowych**, nie prawdziwego
  tokena.

## Co zostało zrobione

**2026-09-13 21:00.** Wszystkie dziesięć punktów i wszystkie kryteria.

Sprawdzone empirycznie, nie tylko testami: mail wyszedł przez prawdziwy SMTP
(mailpit za profilem `dev`), a **skrót tokena z treści maila zgadza się
z wierszem w `ws.invitations`** — czyli link w skrzynce jest tym linkiem, który
zakłada konto. Na martwym porcie SMTP: cztery próby (1 + 3 ponowienia), stan
`nieudany` z powodem od serwera, zaproszenie nietknięte.

Dwie rzeczy wyszły dopiero z prób i żadnej nie dało się przewidzieć z kodu:

- **`TRUNCATE ws.users CASCADE`** z `setUp` każdego testu integracyjnego
  kaskaduje na tabele z kluczem obcym do `users`. Z kluczem przy szablonach
  pierwszy test wyczyściłby treści wgrane migracją i każdy kolejny mail byłby
  „brak szablonu". Autor zmiany jest więc **adresem**, nie referencją do konta.
- **Maile wysyła `worker`, nie `backend`.** Rozjazd konfiguracji między nimi
  jest niewidoczny: backend z prawdziwym DSN i worker z `null://null` dają
  w dzienniku stan **wysłany**, choć nic nie poszło. Opisane w deploymencie.

Ekrany obejrzane w przeglądarce: [017-szablony-maili.png](zrzuty/017-szablony-maili.png)
i [017-dziennik-maili.png](zrzuty/017-dziennik-maili.png). Na drugim widać
przypadek, dla którego powód porażki zostaje obok stanu „wysłany": dwie odmowy
serwera, sukces przy trzeciej próbie.

Cena asynchroniczności zapisana jako **D-038**: token jedzie w wierszu kolejki,
a wiadomość po wyczerpaniu ponowień zostaje w kolejce `failed` z treścią
w środku. Sprawdzone zapytaniem — i stąd retencja każąca tę kolejkę czyścić.

Poza zakresem, odkryte przez zmianę systemu: `drainQueue()` w teście pałaca
liczył wszystkie wiadomości zamiast zleceń publikacji, a `make analiza` padało
na OOM (PHPStan brał 16 procesów w kontenerze z 1 GiB).
- [x] Nieudana wysyłka jest ponawiana, a dziennik pokazuje liczbę prób.
- [x] `docs/05-deployment.md` przestaje kłamać o `WS_DOMAIN`.
