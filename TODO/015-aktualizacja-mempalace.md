---
tags: [ws-memory, todo, utrzymanie, mempalace, bezpieczenstwo, panel-admina]

---

# TODO-015 — Sprawdzanie i zakładanie aktualizacji MemPalace

**Utworzono:** 2026-09-13 12:40 · **Stan:** do zrobienia · **Zależności:** 008

## Powód

MemPalace jest zależnością, od której zależy sens całego systemu, i jest
**przypięty na sztywno** (`MEMPALACE_VERSION` w `.env`, obraz budowany z
`pip install mempalace==...`). Przypięcie jest świadome i słuszne — aktualizacja
pałaca dotyka wektorów, więc nie ma być przypadkiem. Ale ma skutek uboczny:
**nikt nie wie, że wyszło coś nowego.**

Sprawdzone przy pisaniu tego zadania: działa 3.7.0, na PyPI jest **3.9.0**.
Dwie wersje mniejsze w tyle, wydane 2026-08-31, i dowiedzieliśmy się o tym tylko
dlatego, że ktoś ręcznie zapytał. To jest dokładnie ten rodzaj długu, który rośnie
po cichu, aż aktualizacja przestaje być krokiem i staje się projektem.

## Analiza

**Skąd wersja działająca.** MemPalace nie ma endpointu `/version`, ale MCP
`initialize` zwraca `serverInfo.version`. To lepsze źródło niż `MEMPALACE_VERSION`
z `.env`: zmienna mówi, co *miało* być zbudowane, a `initialize` — co **naprawdę
odpowiada**. Rozjazd między nimi jest sam w sobie informacją (ktoś zmienił `.env`
i nie przebudował obrazu).

**Skąd wersja najnowsza.** `https://pypi.org/pypi/mempalace/json`. Trzeba pominąć
wydania wycofane (`yanked`) i przedpremierowe — inaczej panel zaproponuje
aktualizację do wersji, którą autor sam wycofał.

**Porównanie wersji.** Porównanie napisów jest błędne: `3.10.0 < 3.9.0`
leksykograficznie. Potrzebny jest obiekt wartości porównujący liczbowo,
człon po członie.

**Kto sięga do Dockera — decyzja o bezpieczeństwie.** Kliknięcie w panelu ma
przebudować obraz i wymienić kontener, a backend siedzi w kontenerze. Rozważone
trzy drogi:

1. gniazdo Dockera do backendu — najprostsze i **odrzucone**: kontener obsługujący
   ruch z sieci dostałby władzę równoważną rootowi na hoście, więc dowolne RCE
   w Symfony albo przejęcie konta administratora kończyłoby się przejęciem maszyny;
2. osobna usługa-aktualizator z gniazdem Dockera — mniejsza powierzchnia, ale
   backend nadal może ją wywołać, więc włamanie do backendu nadal daje Dockera;
3. **agent na hoście** — wybrane. Backend tylko **zapisuje zlecenie** do bazy;
   skrypt na hoście je podejmuje. Żaden kontener nie widzi Dockera, a włamanie do
   aplikacji pozwala co najwyżej zlecić aktualizację do wersji, która istnieje
   na PyPI.

**Jak agent rozmawia z aplikacją.** Przez `docker compose exec backend php
bin/console`, nie przez HTTP. Nie trzeba wtedy wymyślać uwierzytelniania dla
agenta ani wystawiać endpointu, który musiałby być chroniony inaczej niż
sesją użytkownika.

**Walidacja celu aktualizacji.** Wersja ze zlecenia trafia do `pip install
mempalace==<wersja>`. Musi być sprawdzona wzorcem **po obu stronach** — przy
zapisie zlecenia i w agencie — bo to jedyne miejsce, gdzie dane z aplikacji
wpływają na polecenie wykonywane na hoście.

**Bezpieczeństwo samej operacji.** Aktualizacja pałaca bez kopii zapasowej jest
nieodwracalna. Agent robi `pg_dump` schematu `palace` **przed** przebudową i
uruchamia `test/semantyka.sh` **po** — bo zepsuta trafność wyszukiwania jest
cicha (D-003) i inaczej nikt by jej nie zauważył.

## Rozwiązanie

1. Obiekt wartości `Version` z porównaniem liczbowym + testy (`3.10.0 > 3.9.0`).
2. Porty `ReleaseCatalog` (co jest najnowsze) i `RunningVersion` (co działa),
   adaptery: PyPI po `symfony/http-client`, MemPalace po MCP `initialize`.
3. Migracja: `ws.dependency_state` (ostatni wynik sprawdzenia) i
   `ws.dependency_updates` (zlecenia: kto, kiedy, z której na którą, stan, dziennik).
4. Scheduler (`symfony/scheduler`) — sprawdzenie co 6 godzin, konsumowane przez
   istniejącego workera. Wynik do `ws.dependency_state`, błąd sprawdzenia też
   (niedostępne PyPI to nie to samo co „brak aktualizacji").
5. Polecenia dla agenta: `ws:updater:heartbeat`, `ws:updater:claim`,
   `ws:updater:finish`.
6. API administratora: `GET /api/admin/dependencies`,
   `POST /api/admin/dependencies/{name}/check`,
   `POST /api/admin/dependencies/{name}/update`.
7. Agent na hoście: `scripts/aktualizator.sh` + jednostka i timer systemd,
   z kopią zapasową schematu `palace` przed i testem semantyki po.
8. Panel administratora — sekcja „Zależności": wersja działająca, najnowsza,
   kiedy sprawdzano, stan agenta, przycisk aktualizacji z potwierdzeniem
   mówiącym wprost, co się wydarzy i ile to potrwa.
9. Dokumentacja: `docs/05-deployment.md` i `docs/en/05-deployment.md`
   (instalacja agenta), decyzja w `docs/06-decyzje.md` i `docs/en/06-decisions.md`.

## Kryteria ukończenia

- `Version` porównuje `3.10.0` jako nowsze niż `3.9.0`; test tego pilnuje.
- `GET /api/admin/dependencies` zwraca 3.7.0 jako działającą i 3.9.0 jako
  najnowszą; dla użytkownika bez roli administratora zwraca 403.
- Zlecenie aktualizacji do wersji spoza PyPI jest odrzucane z kodem 422,
  a wersja niezgodna ze wzorcem nie dociera do agenta.
- Panel bez zainstalowanego agenta mówi o tym wprost, zamiast dawać przycisk,
  który nic nie robi.
- Agent wykonuje pełną drogę: kopia zapasowa → przebudowa → test semantyki →
  wynik widoczny w panelu; nieudany test semantyki zostawia ślad w dzienniku
  zlecenia.
- Sprawdzenie działa bez internetu: niedostępne PyPI daje w panelu „nie udało
  się sprawdzić" z datą ostatniej udanej próby, a nie „brak aktualizacji".
