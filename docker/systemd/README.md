# Agent aktualizacji MemPalace — instalacja w systemd

Ten katalog zawiera jednostkę i timer dla `scripts/aktualizator.sh`, czyli
agenta, który **na hoście** wykonuje aktualizacje MemPalace zlecone z panelu
administratora.

## Co się stanie, jeśli agenta NIE zainstalujesz

Nic się nie zepsuje, ale **aktualizacja z panelu nie będzie działać**:

- sekcja „Zależności" w panelu administratora pokaże, że **aktualizator jest
  niedostępny** — bo rozpoznaje agenta po pulsie, który ten zgłasza co minutę,
  a bez timera puls nie przychodzi;
- **przycisk aktualizacji nie zadziała**. Zlecenie zostanie zapisane w bazie
  i będzie tam czekać na kogoś, kto je podejmie. Nikt tego nie zrobi, więc
  zostanie w stanie „oczekuje" na zawsze.

Jest to widoczne celowo. Backend **nie ma i nie będzie mieć** dostępu do
Dockera (kontener z gniazdem Dockera ma władzę równoważną rootowi na maszynie,
a backend obsługuje ruch z sieci), więc bez agenta na hoście nie ma czym
przebudować obrazu. Lepiej, żeby panel mówił o tym wprost, niż dawał przycisk,
po którym nic się nie dzieje.

Sprawdzenie wersji (co jest nowego na PyPI) działa **bez agenta** — robi je
backend po HTTP. Bez agenta znika tylko możliwość aktualizacji jednym
kliknięciem; ręcznie zawsze można to zrobić jak dotąd, przez podmianę
`MEMPALACE_VERSION` w `.env` i przebudowę.

## Kto ma to uruchamiać

Użytkownik z **dostępem do gniazda Dockera** (grupa `docker` albo Docker
w trybie rootless) i **prawem zapisu do katalogu projektu** — agent podmienia
`MEMPALACE_VERSION` w `.env` i zapisuje kopie zapasowe do `kopie/`.

**Nie jako root.** Agent nie potrzebuje roota do niczego, a dostęp do Dockera
i tak jest najmocniejszym uprawnieniem w tym układzie — nie ma po co dokładać
drugiego.

## Instalacja — wariant zalecany (jednostka użytkownika)

Wszystkie polecenia z katalogu projektu, jako ten użytkownik:

```bash
# 1. Sprawdź na sucho, czy host jest gotowy — nie rusza działającego systemu.
./scripts/aktualizator.sh --na-sucho

# 2. Skopiuj jednostkę i timer, podstawiając ścieżkę projektu.
mkdir -p ~/.config/systemd/user
cp docker/systemd/ws-memory-aktualizator.* ~/.config/systemd/user/
sed -i "s|__KATALOG_PROJEKTU__|$PWD|g" ~/.config/systemd/user/ws-memory-aktualizator.*

# 3. Włącz timer (usługi się nie włącza — uruchamia ją timer).
systemctl --user daemon-reload
systemctl --user enable --now ws-memory-aktualizator.timer

# 4. Pozwól, by timer chodził bez zalogowanej sesji. Bez tego zniknie
#    z pamięci przy wylogowaniu i puls przestanie przychodzić.
loginctl enable-linger "$USER"

# 5. Sprawdź, że tyka i że przebieg kończy się czysto.
systemctl --user list-timers ws-memory-aktualizator.timer
journalctl --user -u ws-memory-aktualizator.service -n 30
```

Po minucie w dzienniku powinna pojawić się linia „Brak zlecenia — nie ma nic do
zrobienia", a w panelu administratora aktualizator przestać być niedostępny.

## Instalacja — wariant systemowy

Wybierz go, gdy maszyna nie ma sesji użytkownika (serwer bez logowania), a
`enable-linger` nie jest po drodze:

```bash
sudo cp docker/systemd/ws-memory-aktualizator.* /etc/systemd/system/
sudo sed -i "s|__KATALOG_PROJEKTU__|$PWD|g" /etc/systemd/system/ws-memory-aktualizator.*
sudoedit /etc/systemd/system/ws-memory-aktualizator.service
#   → odkomentuj After=docker.service i Wants=docker.service w sekcji [Unit]
#   → odkomentuj User= i Group= w sekcji [Service] i wpisz użytkownika
#     z dostępem do Dockera i do katalogu projektu
sudo systemctl daemon-reload
sudo systemctl enable --now ws-memory-aktualizator.timer
systemctl list-timers ws-memory-aktualizator.timer
```

W tym wariancie `journalctl -u ws-memory-aktualizator.service` (bez `--user`).

## Co robi jeden przebieg

1. **puls** — `ws:updater:heartbeat`; to po nim panel wie, że agent żyje;
2. **zlecenie** — `ws:updater:claim`; brak zlecenia to koniec przebiegu (kod 0);
3. **walidacja** wersji docelowej wzorcem `X.Y.Z` — wersja jedzie do
   `pip install mempalace==…`, więc jest sprawdzana także tutaj, nie tylko
   w backendzie;
4. **kopia zapasowa** schematu `palace` do `kopie/` — bez udanej kopii agent
   **nie rusza dalej**;
5. podmiana `MEMPALACE_VERSION` w `.env`, `docker compose build mempalace`,
   `docker compose up -d --wait mempalace`;
6. **test semantyki** (`test/semantyka.sh`) — bo zepsuta trafność wyszukiwania
   jest cicha: usługa odpowiada, tylko przestaje znajdować;
7. **wynik** — `ws:updater:finish` z całym dziennikiem przebiegu, który panel
   pokazuje administratorowi.

Gdy test semantyki nie przejdzie, agent **wycofuje się**: przywraca poprzednią
wersję w `.env`, przebudowuje obraz, podnosi go i zgłasza `failed` z dziennikiem
mówiącym wprost, że nastąpiło wycofanie.

Wycofanie cofa **wersję obrazu, nie zawartość bazy**. Jeśli nowsza wersja
pałaca zmieniła schemat `palace`, trzeba odtworzyć kopię z `kopie/` ręcznie —
polecenie `pg_restore` agent wypisuje w dzienniku. Odtwarzanie wektorów nie
dzieje się automatycznie świadomie: robione w tle, bez człowieka patrzącego na
wynik, jest groźniejsze niż zatrzymanie się z jasnym komunikatem.

## Uruchomienie ręczne

```bash
./scripts/aktualizator.sh --na-sucho                  # cała ścieżka decyzyjna, zero zmian
./scripts/aktualizator.sh --na-sucho --wersja=3.9.0   # jw., z udawanym zleceniem
./scripts/aktualizator.sh                             # normalny przebieg
systemctl --user start ws-memory-aktualizator.service  # to samo przez systemd
```

Równoległe uruchomienia są bezpieczne: skrypt trzyma blokadę (`flock` na
`kopie/.blokada`) i drugi przebieg kończy się natychmiast, bez działania.

## Wyłączenie i odinstalowanie

```bash
systemctl --user disable --now ws-memory-aktualizator.timer
rm ~/.config/systemd/user/ws-memory-aktualizator.{service,timer}
systemctl --user daemon-reload
```

Po wyłączeniu panel wróci do stanu „aktualizator niedostępny". Kopie zapasowe
w `kopie/` zostają — to jedyne, co po agencie zostaje na dysku.

## Gdy coś nie działa

| Objaw | Przyczyna i co zrobić |
|---|---|
| `docker compose nie odpowiada` w dzienniku | Użytkownik nie ma dostępu do gniazda Dockera. Dodaj go do grupy `docker` (`sudo usermod -aG docker $USER`) i zaloguj ponownie. |
| Panel nadal mówi „aktualizator niedostępny" | Sprawdź `systemctl --user list-timers`. Jeśli timera nie ma po wylogowaniu — brakuje `loginctl enable-linger`. |
| `backend nie zna polecenia ws:updater:*` | Poleceń jeszcze nie ma w backendzie (albo kontener jest po starym obrazie). Bez nich agent nie ma z kim rozmawiać. |
| Jednostka kończy się kodem 1 co minutę | Przeczytaj `journalctl --user -u ws-memory-aktualizator.service -n 50`. Najczęściej: backend nie odpowiada, więc puls nie dochodzi. |
| Przebieg trwa i trwa | Przebudowa obrazu na zimnej pamięci podręcznej to kilkanaście minut. Limit jednostki to 30 minut, po nim systemd przerwie przebieg. |
| `kopie/` rośnie | Każda aktualizacja to jeden zrzut schematu `palace`. Katalog jest w `.gitignore`; czyszczenie starych kopii jest świadomie ręczne — to ostatnia linia obrony przed nieodwracalną zmianą. |
