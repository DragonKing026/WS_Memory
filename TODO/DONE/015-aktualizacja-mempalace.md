---
tags: [ws-memory, todo, utrzymanie, mempalace, bezpieczenstwo, panel-admina]

---

# TODO-015 — Sprawdzanie i instalowanie aktualizacji MemPalace

**Utworzono:** 2026-09-13 12:40 · **Stan:** ✅ **UKOŃCZONE 2026-09-13 14:47** · **Zależności:** 008

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

## Co zostało zrobione

**2026-09-13 14:47**

Sprawdzanie wersji, zlecanie aktualizacji, agent na hoście i sekcja
„Zależności" w panelu administratora. Zweryfikowane na działającym stosie:
panel pokazuje 3.7.0 działającą, 3.7.0 przypiętą i 3.9.0 najnowszą, a ponieważ
jednostka systemd nie jest zainstalowana — mówi o tym wprost i **nie pokazuje
przycisku**. Zrzuty: `TODO/zrzuty/015-panel-zaleznosci-bez-agenta.png` oraz
`015-panel-zaleznosci-telefon.png`.

### Rzeczy, których nie było w planie

**Wersja działająca bierze się z MCP `initialize`, nie z `.env`.** Pałac nie ma
endpointu `/version`, ale handshake zwraca `serverInfo.version`. To lepsze
źródło: zmienna mówi, co *miało* zostać zbudowane, a handshake — co **naprawdę
odpowiada**. Rozjazd między nimi jest sam w sobie informacją i panel go pokazuje.

**`MEMPALACE_VERSION` nie docierała do kontenera backendu** — compose przekazywał
ją tylko usłudze `mempalace`, jako argument budowania. Gdyby to zostało,
„przypięta" pokazywałaby wartość zastępczą z `backend/.env`, czyli rozjazd byłby
niewidoczny **dokładnie wtedy, gdy zaistnieje**. Złapane przy składaniu części.

**Panel i backend przeczytały ten sam kontrakt inaczej.** Powstawały równolegle:
backend wysyła `{dependency, updater}`, panel spodziewał się samej zależności.
Wyszło przy zszywaniu, nie przy pisaniu — schematy przyjmują teraz oba kształty
i mają na to testy.

### Poprawka po przeglądzie: administracja to osobne miejsce

Pierwsza wersja wieszała „Administracja → Zależności" w pasku bocznym bazy
wiedzy, obok przestrzeni i surowej pamięci. Zwrócone w przeglądzie i słusznie:
to dwie różne role i dwa różne pytania. Pasek boczny odpowiada „gdzie jest
wiedza", a utrzymanie instalacji — „czy to w ogóle działa i kto może tu wejść".
Zmieszane, każdy czytający dokument miał maszynownię w kącie oka, a
administrator musiał ją mijać, żeby dojść do swojego.

Administracja ma teraz własny układ (`AdminLayout.vue`) pod `/admin`, z własnym
paskiem i powrotem do bazy wiedzy; wejście jest ikoną w nagłówku, widoczną tylko
dla administratora globalnego. Rozstrzyga to od razu pytanie, które wracałoby
przy każdym kolejnym ekranie administracyjnym z TODO-008: gdzie go powiesić.

Odmowa dla osoby bez roli mieszka w układzie, nie na stronie — obowiązuje każdy
ekran administracyjny, a powtórzona na każdym z osobna rozjechałaby się przy
pierwszym, który o niej zapomni.

### Bezpieczeństwo

Wersja docelowa jest walidowana wzorcem **po obu stronach** — przy zapisie
zlecenia i w agencie. Nie z nieufności do backendu, tylko dlatego, że jedna
warstwa walidacji to zero warstw w dniu, w którym akurat ta jedna ma błąd.
Sprawdzone: `--wersja='3.9.0; touch /tmp/wlamanie'` jest odrzucane i plik nie
powstaje.

Wyścig dwóch kliknięć rozbija się o **indeks częściowy w bazie**, nie o
sprawdzenie w PHP. Sprawdzone realnie, nie tylko testem: sześć równoległych
zleceń → jedno przechodzi, pięć odrzuconych. Sześć równoległych `claim` przy
jednym zleceniu → dokładnie jedno niepuste wyjście.

### Aktualizacja wykonana naprawdę — i dwie usterki, które to obnażyło

**2026-09-13 15:24.** MemPalace podniesiony **3.7.0 → 3.9.0** przez panel, agentem na
hoście. Test semantyki przeszedł na nowej wersji: polskie zapytanie bez wspólnych
słów z treścią znalazło ją z podobieństwem 0,743. Wektory nietknięte. Kopia
zapasowa sprzed operacji leży w `kopie/palace-20260913-151714-z-3.7.0.dump`
(2,9 MB).

Uruchomienie na żywo pokazało dwie rzeczy, których **nie złapał żaden test**:

**Panel po udanej aktualizacji nadal pokazywał 3.7.0.** Zapisany stan
przepisywało wyłącznie sprawdzenie, a nikt o nie nie prosił — więc ekran
raportował „zakończone powodzeniem" i obok tego wersje sprzed operacji, którą
właśnie ogłosił za zakończoną. Naprawione: udane zlecenie odświeża stan.
Niepowodzenie sprawdzenia jest przy tym połykane celowo — aktualizacja się
udała i to już jest zapisane, a zamiana tego w „nieudane zlecenie" raportowałaby
odwrotność tego, co się stało.

**Backend nie widział nowego `.env`.** Agent wymieniał tylko kontener pałaca,
a `MEMPALACE_VERSION` wchodzi do środowiska kontenerów przy ich **tworzeniu**.
Pole „przypięta" pokazywało więc starą wartość — czyli dokładnie ten rozjazd,
który ta funkcja ma wykrywać, wywołany przez nią samą. Agent odtwarza teraz
`backend` i `worker` po podmianie pliku, przed testem semantyki i przed
zgłoszeniem wyniku (wymiana po zgłoszeniu ucięłaby raport w połowie).

Obie poprawki sprawdzone tak, jak należy: test regresji **najpierw pokazano jako
czerwony** po tymczasowym cofnięciu poprawki, a całą pętlę przejechano po raz
drugi z celowo zafałszowanym stanem — który sam się poprawił.

### Czego nie zrobiono i co jest kruche

- **Automatyczne wycofanie cofa wersję obrazu, nie zawartość bazy.** Gdyby nowsza
  wersja pałaca zmigrowała schemat `palace`, stary obraz może go nie zrozumieć —
  wtedy potrzebna jest ręczna interwencja. Agent wypisuje w dzienniku gotowe
  polecenie `pg_restore`. To najsłabsze miejsce całego rozwiązania i świadomie
  nie odtwarzam kopii automatycznie: przywracanie wektorów bez człowieka jest
  groźniejsze niż postój.
- **Zlecenie `pending` nie ma limitu czasu.** `running` starsze niż 45 minut jest
  domykane, ale gdy agent padnie tuż po zapisaniu zlecenia, wiersz zostaje
  `pending` i blokuje kolejne przez indeks częściowy. Dziś jedyne wyjście to
  ręczna zmiana w tabeli — brakuje przycisku „anuluj".
- **Downgrade jest niemożliwy**: katalog wydań umie odpowiedzieć tylko „co
  najnowsze", więc zlecenie do starszej istniejącej wersji dostanie 422.
- **Test semantyki jest bramką jakości i sam bywa kruchy** — zależy od usługi
  embeddingów, której chwilowa niedostępność wygląda identycznie jak zepsuta
  trafność i wywoła wycofanie działającej w istocie aktualizacji. Kierunek
  pomyłki wybrany świadomie: fałszywy alarm zamiast cichej utraty trafności.
- **Pełnej ścieżki aktualizacji nie przećwiczono na żywo.** Agent przeszedł tryb
  `--na-sucho`, ale prawdziwego podniesienia 3.7.0 → 3.9.0 nie wykonano — to
  operacja dotykająca wektorów i należy do decyzji człowieka, nie do weryfikacji
  zadania.
