---
noteId: "5cbffb40aee711f1835b4b4fc1577c80"
tags:
  - "ws-memory"
  - "bezpieczenstwo"
  - "zgloszenia"
  - "polityka"

---

# Polityka bezpieczeństwa

**English version below.** Ten jeden plik jest dwujęzyczny, bo GitHub czyta
dokładnie jeden `SECURITY.md` — a osoba zgłaszająca podatność musi go zrozumieć
niezależnie od tego, w jakim języku pracuje.

WS_Memory jest wspólną bazą wiedzy Web Systems. Trzyma dokumentację firmową,
ustalenia z rozmów i transkrypty sesji z agentami AI — **treść z założenia
poufną, podzieloną na przestrzenie z osobnymi uprawnieniami**. Dlatego błąd
w warstwie uprawnień jest tu poważniejszy niż typowa usterka: nie wywraca
aplikacji, tylko po cichu pokazuje komuś treść, do której nie ma prawa.

## Jak zgłosić podatność

**Nie otwieraj publicznego zgłoszenia (issue).** Opis podatności w publicznym
repozytorium jest instrukcją dla każdego, kto ją zobaczy przed poprawką.

1. **Zgłoszenie prywatne przez GitHuba** (droga preferowana) — zakładka
   **Security → Report a vulnerability**. Prywatne zgłaszanie jest w tym
   repozytorium włączone; rozmowa toczy się tam, gdzie powstanie poprawka.
2. **E-mail:** a.ograbek@web-systems.pl — jeśli nie masz konta GitHuba albo
   sprawa dotyczy samego GitHuba.

Co pomaga w zgłoszeniu: **wersja albo skrót commita**, kroki odtworzenia,
przewidywany wpływ i — jeśli to możliwe — najmniejszy przypadek, który pokazuje
problem. Nie musisz mieć gotowej poprawki.

**Czego prosimy nie robić:** nie testuj na cudzej instancji. WS_Memory jest
oprogramowaniem uruchamianym u siebie; postaw własny stos (`make start`), a jeśli
podatność da się pokazać tylko na danych produkcyjnych, opisz ją i umów się na
sprawdzenie. Nie ma programu nagród; jest uznanie w `CHANGELOG.md`, jeśli je
chcesz.

### Czas odpowiedzi

To projekt małego zespołu, nie firma z dyżurem całodobowym. Zobowiązujemy się do
tego, co możemy dowieźć:

| Zdarzenie | Termin |
|---|---|
| potwierdzenie, że zgłoszenie dotarło | 3 dni roboczych |
| pierwsza ocena (czy uznajemy i jak poważnie) | 7 dni |
| poprawka albo plan z terminem | 30 dni dla wysokiej wagi |

Jeśli nie odpowiadamy w terminie, ponów — wiadomość mogła utknąć.

## Które wersje są wspierane

Wspierana jest **gałąź `main`** i nic więcej. Projekt nie ma jeszcze wydań; nie
utrzymujemy poprawek dla wcześniejszych commitów. Aktualizacja to `git pull`
i `make migracje` (`docs/05-deployment.md`).

## Co jest podatnością w tym projekcie

Powyżej zwykłych kategorii (wstrzyknięcia, XSS, obejście uwierzytelnienia) —
**złamanie którejkolwiek z tych reguł traktujemy jako błąd krytyczny**, nawet
jeśli nic się nie wywraca i nic nie pojawia się w logu. Pełna lista
w `AGENTS.md`, sekcja „Nienaruszalne reguły"; tu te, które da się zaatakować:

1. **Treść z przestrzeni, do której aktor nie ma roli, nie może wyjść.** Ani
   przez `/api`, ani przez `/mcp`, ani w odpowiedzi na błąd.
2. **Token agenta nigdy nie ma więcej uprawnień niż jego właściciel** i zakres
   tokena może je tylko zawężać, nigdy poszerzać.
3. **Żadne narzędzie MCP nie przyjmuje autora ani skrzydła pałaca.** Tożsamość
   wynika z tokena; gdyby dało się ją podać w żądaniu, cały model uprawnień
   byłby fikcją.
4. **Odebranie roli albo unieważnienie tokena działa przy następnym
   wywołaniu**, nie po wygaśnięciu czegokolwiek.
5. **Każdy odczyt i zapis zostawia wpis w `audit_log`.** Operacja bez śladu to
   podatność, nie brak funkcji.
6. **„Nie masz dostępu" nigdy nie jest odpowiedzią na odczyt** — sam komunikat
   ujawnia, że dana przestrzeń istnieje. Odpowiedzią jest pusty wynik, bajt
   w bajt taki sam jak dla treści, której nie ma.
7. **Token MemPalace i DSN do bazy nie wychodzą poza backend.** Agent dostaje
   wyłącznie własny token do `/mcp`.

Osobna kategoria: **wyciek sekretu do dziennika albo do komunikatu błędu**.
Tokeny i hasła nie mogą pojawiać się w żadnym z nich, również pośrednio —
w zrzucie żądania czy w treści wyjątku.

## Czego nie uznajemy

- **Podatności w MemPalace** — to zależność zewnętrzna (`docs/06-decyzje.md`,
  D-001). Zgłoś je [w projekcie MemPalace](https://github.com/MemPalace/mempalace);
  jeśli dotyczą sposobu, w jaki **my** go używamy, zgłoś tutaj.
- **Podatności w zależnościach** — pilnuje ich Dependabot, a aktualizacje
  bezpieczeństwa wchodzą automatycznie. Zgłoś, jeśli znasz konkretną ścieżkę
  wykorzystania w naszym kodzie.
- **Skutki błędnej konfiguracji u siebie** — brak TLS, Postgres wystawiony na
  świat, słabe hasła. `docs/05-deployment.md` opisuje, jak ma być; odstępstwo od
  tego jest sprawą wdrożenia, nie kodu.
- **Zgłoszenia z samego skanera** bez ścieżki wykorzystania. Chętnie za to
  przyjmiemy analizę, dlaczego akurat u nas to jest osiągalne.

## Co robimy po naszej stronie

- **Sekrety nie trafiają do repozytorium.** Repozytorium jest publiczne; hasła
  i tokeny podaje `docker-compose.yml` ze `.env`, który nigdy nie jest
  commitowany. Włączone jest skanowanie sekretów razem z ochroną przy wypchnięciu.
- **Poświadczenia trzymamy jako skróty.** Tokeny agentów i zaproszenia:
  `sha256` w bazie, jawna wartość widoczna **raz**, przy wystawieniu.
- **Trzy niezależne skanowania kodu** — CodeQL w trybie domyślnym, AI findings
  (bo CodeQL nie obsługuje PHP) i PHPStan na poziomie 8 (`docs/06-decyzje.md`,
  D-018). PHPStan i testy są bramką blokującą scalenie.
- **Testy negatywne przy każdym commicie.** Reguły uprawnień mają testy, które
  sprawdzają, czego system **nie** robi — bo błąd w uprawnieniach jest cichy.
- **Gałąź `main` chroniona** rulesetem; zmiany przez pull request z zielonym CI.

## Ryzyka przyjęte świadomie

Nazywamy je wprost, bo niewypowiedziane ryzyko wygląda jak przeoczenie:

- **Okno limitu tempa jest stałe, nie przesuwane** (D-022). Na przełomie okien
  token może wykonać dwa razy limit. Limit chroni pałac przed pętlą w agencie,
  nie rozlicza kwot.
- **Wpis audytu wykonany w transakcji, która się cofnie, cofnie się razem z nią**
  (D-024). Wybraliśmy to zamiast drugiego połączenia; przy nieudanym wywołaniu
  MCP ślad zostaje niezależnie, bo dekorator zapisuje go po wycofaniu.
- **Administrator globalny może nadać sobie rolę w dowolnej przestrzeni.** Nie
  czyta cudzych treści po cichu, ale nadanie roli jest w jego zasięgu — i dlatego
  zostaje w dzienniku audytu (D-016).
- **Zmielony tekst kodu trafia na serwer** jako szuflady, choć samo mielenie jest
  lokalne (D-012). Serwer nie ma dostępu do repozytoriów ani kluczy do gita, ale
  treść plików może się w nim znaleźć.

---

# Security policy

WS_Memory is Web Systems' shared knowledge base. It holds company documentation,
decisions from conversations and transcripts of sessions with AI agents —
**content that is confidential by design, divided into spaces with separate
permissions**. A bug in the permission layer is therefore worse here than an
ordinary defect: it does not crash anything, it quietly shows somebody content
they have no right to.

## Reporting a vulnerability

**Do not open a public issue.** A description of a vulnerability in a public
repository is an instruction for everybody who reads it before the fix lands.

1. **A private report through GitHub** (preferred) — the **Security → Report a
   vulnerability** tab. Private reporting is enabled on this repository, and the
   conversation then happens where the fix will be written.
2. **E-mail:** a.ograbek@web-systems.pl — if you have no GitHub account, or the
   matter concerns GitHub itself.

What helps: **the version or commit hash**, steps to reproduce, the expected
impact and, if you can, the smallest case that shows the problem. You do not need
to bring a fix.

**Please do not** test against somebody else's instance. WS_Memory is software you
run yourself; stand up your own stack (`make start`). If a vulnerability can only
be shown against production data, describe it and arrange a check. There is no
bounty programme; there is credit in `CHANGELOG.md` if you want it.

### Response times

This is a small team's project, not a company with a 24-hour rota. We commit to
what we can deliver:

| Event | Deadline |
|---|---|
| acknowledging that the report arrived | 3 working days |
| first assessment (whether we accept it, and how serious) | 7 days |
| a fix, or a plan with a date | 30 days for high severity |

If we miss a deadline, send it again — the message may have got lost.

## Supported versions

**The `main` branch** is supported and nothing else. The project has no releases
yet and we do not backport fixes to earlier commits. Updating means `git pull` and
`make migracje` (`docs/en/05-deployment.md`).

## What counts as a vulnerability here

Beyond the usual categories (injection, XSS, authentication bypass) — **breaking
any of these is treated as a critical bug**, even when nothing crashes and nothing
appears in a log. The full list is in `AGENTS.en.md` under "Inviolable rules";
these are the ones that can be attacked:

1. **Content from a space the actor holds no role in must not leave.** Not through
   `/api`, not through `/mcp`, and not inside an error message.
2. **An agent token never has more permissions than its owner**, and a token's
   scope may only narrow them, never widen.
3. **No MCP tool accepts an author or a palace wing.** Identity comes from the
   token; if it could be supplied in a request, the whole permission model would be
   a fiction.
4. **Revoking a role or a token bites at the next call**, not when something
   expires.
5. **Every read and write leaves an entry in `audit_log`.** An operation with no
   trace is a vulnerability, not a missing feature.
6. **"You have no access" is never an answer to a read** — the message itself
   reveals that the space exists. The answer is an empty result, byte for byte the
   same as for content that does not exist.
7. **The MemPalace token and the database DSN never leave the backend.** An agent
   gets only its own token for `/mcp`.

A category of its own: **a secret leaking into a log or an error message**. Tokens
and passwords must appear in neither, including indirectly — in a dumped request or
inside an exception message.

## What we do not accept

- **Vulnerabilities in MemPalace** — it is an external dependency (D-001). Report
  them [to MemPalace](https://github.com/MemPalace/mempalace); if they concern the
  way **we** use it, report them here.
- **Vulnerabilities in dependencies** — Dependabot watches those and security
  updates land automatically. Do report one if you know a concrete path to exploit
  it in our code.
- **Consequences of misconfiguring your own deployment** — no TLS, Postgres exposed
  to the world, weak passwords. `docs/en/05-deployment.md` describes how it should
  be; departing from that is a deployment matter, not a code one.
- **Raw scanner output** with no exploitation path. We will gladly take the
  analysis of why it is reachable in our case.

## What we do on our side

- **Secrets never reach the repository.** It is public; passwords and tokens come
  from `docker-compose.yml` via `.env`, which is never committed. Secret scanning is
  on, with push protection.
- **Credentials are stored as digests.** Agent tokens and invitations: `sha256` in
  the database, the plain value shown **once**, when it is issued.
- **Three independent code scans** — CodeQL in default setup, AI findings (because
  CodeQL does not support PHP) and PHPStan at level 8 (D-018). PHPStan and the tests
  are the gate that blocks a merge.
- **Negative tests on every commit.** The permission rules have tests asserting what
  the system does **not** do — because a permission bug is silent.
- **`main` is protected** by a ruleset; changes go through a pull request with green
  CI.

## Risks accepted deliberately

Named plainly, because an unspoken risk looks like an oversight:

- **The rate-limit window is fixed, not sliding** (D-022). Across a window boundary
  a token can make twice the limit. The limit protects the palace from a runaway
  loop; it does not meter quotas.
- **An audit entry written inside a transaction that rolls back is rolled back with
  it** (D-024). We chose that over a second connection; for a failing MCP call the
  trace survives anyway, because the decorator writes it after the rollback.
- **A global administrator can grant themselves a role in any space.** They do not
  read other people's content silently, but granting is within their reach — which
  is exactly why it stays in the audit log (D-016).
- **Mined source text reaches the server** as drawers, even though mining itself is
  local (D-012). The server has no access to repositories or git keys, but the
  contents of files can end up in it.
