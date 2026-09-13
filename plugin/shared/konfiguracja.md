---
name: ws-memory-setup
description: Konfiguracja połączenia z firmową bazą wiedzy Web Systems — wystawienie tokena agenta i sprawdzenie, czy działa. Użyj, gdy narzędzia ws_* odmawiają, nie odpowiadają albo gdy ktoś podłącza WS_Memory pierwszy raz.
---

# Podłączenie do WS_Memory

Wtyczka pyta o dwie rzeczy przy włączeniu: **adres instancji** i **token
agenta**. Obie zostają w konfiguracji klienta AI — nie w repozytorium, nie
w zmiennych środowiskowych ustawianych ręcznie, nie w żadnym pliku projektu.

## Skąd wziąć token

1. Zaloguj się do WS_Memory w przeglądarce.
2. **Ustawienia → Tokeny agentów → Wystaw nowy.**
3. Nazwij go tak, żebyś po miesiącu wiedział, co to jest — nazwa maszyny albo
   klienta AI, na przykład „laptop-artur / Claude Code".
4. Skopiuj token **od razu**. Pokazuje się raz i nie da się go odczytać
   ponownie — można tylko wystawić nowy.

Token nigdy nie ma więcej uprawnień niż jego właściciel. Może mieć mniej:
przy wystawianiu da się zawęzić go do wybranych przestrzeni. Warto, jeśli
podłączasz maszynę, której nie masz przy sobie.

## Sprawdzenie, czy działa

Wywołaj `ws_status`. Poprawna odpowiedź mówi, do jakich przestrzeni token ma
prawo i gdzie wyląduje zapis bez wskazanej przestrzeni.

| Objaw | Co to znaczy |
|---|---|
| brak narzędzi `ws_*` | wtyczka nie jest włączona albo klient nie został zrestartowany |
| odmowa uwierzytelnienia | token wygasł, został odwołany albo jest z innej instancji |
| `ws_status` odpowiada, ale lista przestrzeni jest pusta | konto nie ma jeszcze dostępu do żadnej przestrzeni zespołowej — poproś administratora |
| błąd niedostępności pamięci | serwer żyje, ale pałac nie odpowiada; to nie jest „nic nie znaleziono" |

## Token przestał działać

Nie próbuj go naprawiać — **wystaw nowy i odwołaj stary**. Token jest tani,
a token, co do którego nie wiadomo, czemu przestał działać, jest podejrzany.

Token wykorzystany do jednorazowej pracy odwołuje się po jej zakończeniu.

## Czego tu nie ma

Adresu bazy danych ani tokena do MemPalace. Agent ich nie dostaje i nie
potrzebuje: jedyną drogą do wspólnej pamięci jest gateway `/mcp`, bo tylko
tam działają uprawnienia i audyt. Lokalny pałac na Twojej maszynie to osobny
serwer MCP (`mempalace`) i osobna sprawa.
