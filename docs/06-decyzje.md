---
noteId: "57b9d670aeb011f1997d030a3cd38ca7"
tags: []

---

# Decyzje techniczne

Każda decyzja ma numer, datę, stan i uzasadnienie wraz z **odrzuconymi
alternatywami**. Decyzji nie edytujemy — zastąpioną oznaczamy jako
`Zastąpiona przez D-00x` i dopisujemy nową.

Stan: `Przyjęta` · `Zastąpiona` · `Odrzucona`

---

## D-001 — Symfony 8 jako aplikacja, MemPalace jako sidecar

**Data:** 2026-09-12 · **Stan:** Przyjęta
· **Zmieniona w zakresie warstwy prezentacji przez D-008**

Symfony 8 / PHP 8.4 obsługuje logowanie, wiki, uprawnienia i gateway MCP.
MemPalace stoi w osobnym kontenerze i jest odpytywany po HTTP MCP jako czarna
skrzynka.

> Pierwotnie decyzja obejmowała także interfejs w Twigu. **D-008 to zmienia**:
> Symfony jest czystym API, interfejs jest osobną aplikacją Vue. Pozostała
> część decyzji (Symfony jako backend, MemPalace jako sidecar) obowiązuje.

**Dlaczego:** zespół Web Systems utrzymuje kod w Symfony (główna aplikacja Symfony zespołu: Symfony 8,
PHP 8.4, Doctrine, Twig). Kod w technologii, której zespół nie używa
codziennie, gnije szybciej niż rośnie. Traktowanie MemPalace jako czarnej
skrzynki za granicą HTTP oznacza, że jego aktualizacja do nowej wersji nie
dotyka naszego kodu.

**Odrzucono:**
- *Gateway w Pythonie + web app w Symfony* — dwa języki i dwa repozytoria do
  utrzymania w zamian za dostęp do wnętrza MemPalace, którego nie potrzebujemy.
- *Całość w Pythonie* — najszybsze do pierwszej wersji, ale utrzymanie spada
  na technologię poza kompetencją zespołu.
- *Symfony API + SPA React* — lepsze UX edytora, ale trzeci komponent w
  deploymencie; można dodać później, jeśli edytor wiki tego zażąda.

---

## D-002 — PostgreSQL 18 + pgvector zamiast MariaDB

**Data:** 2026-09-12 · **Stan:** Przyjęta

Jedna baza PostgreSQL 18 z rozszerzeniem pgvector. Dwa schematy: `palace`
(tabele MemPalace) i `ws` (dane aplikacji).

**Dlaczego** — to świadome odejście od firmowego domyślnego MariaDB, z czterech
konkretnych powodów:

1. **Wyszukiwanie pełnotekstowe po polsku.** Postgres ma `tsvector` ze
   słownikami Hunspell, czyli stemming: „umowy", „umowa", „umowie" trafiają w
   to samo hasło. MariaDB `FULLTEXT` nie ma polskiego stemmingu i sprowadza się
   do dopasowań prefiksowych. W bazie wiedzy pisanej po polsku to różnica
   między działającą i niedziałającą wyszukiwarką.
2. **pgvector.** MemPalace wspiera pgvector jako pełny backend, więc pałac i
   dane aplikacji mieszkają w jednej bazie: jeden `pg_dump` to pełny backup
   całego systemu, a nie dwa niezależne mechanizmy odtwarzania.
3. **`JSONB` z indeksami GIN** — metadane szuflad i ACL odpytywane w SQL,
   bez wyciągania wszystkiego do PHP.
4. **Transakcyjny DDL.** Migracja Doctrine, która padnie w połowie, cofa się
   w całości. W MariaDB zostaje pół-zmigrowany schemat.

Dla kodu Symfony to zmiana jednej linii w `DATABASE_URL` — Doctrine obsługuje
oba silniki równorzędnie.

**Koszt:** doświadczenie operacyjne zespołu jest w MariaDB; różni się składnia
backupu i narzędzia diagnostyczne. Uznano za akceptowalne.

**Odrzucono:** *osobny magazyn dla pałaca (Chroma na wolumenie)* — dwa systemy
do backupu i brak możliwości wspólnych zapytań SQL między wiki a pałacem.

---

## D-003 — Centralny serwer embeddingów, model `BAAI/bge-m3`

**Data:** 2026-09-12 · **Stan:** Przyjęta

Osobny kontener wystawia OpenAI-kompatybilny `/v1/embeddings`. MemPalace
korzysta z niego przez `MEMPALACE_EMBEDDING_MODEL=openai-compat` +
`MEMPALACE_EMBEDDING_API_URL`. Model: `BAAI/bge-m3` (1024 wymiary).

**Dlaczego:**

- **Domyślny `minilm` jest trenowany tylko na angielskim.** Dla bazy pisanej
  po polsku dałby ciche pogorszenie trafności — system by działał, tylko
  znajdywał nie to, co trzeba.
- **Jeden model dla wszystkich.** Gdyby każda maszyna liczyła wektory
  lokalnie, różnica wersji modelu między laptopami zanieczyściłaby przestrzeń
  wektorową bez żadnego komunikatu o błędzie.
- **`bge-m3` zamiast rodziny `e5`**: modele E5 wymagają prefiksów `query:` /
  `passage:` w tekście, żeby osiągać deklarowaną jakość. MemPalace ich nie
  dodaje, więc jakość spadłaby po cichu. `bge-m3` nie ma tego wymogu i jest
  mocny po polsku.
- Żaden fragment wiedzy firmowej nie wychodzi poza infrastrukturę.

**Konsekwencja operacyjna:** zmiana modelu unieważnia **wszystkie** wektory
w bazie i wymaga przeliczenia jej od zera. Dlatego decyzja podjęta przed
pierwszym zapisem, a nie po.

**Odrzucono:**
- *`embeddinggemma` lokalnie na każdej maszynie* — 300 MB modelu na laptop i
  ryzyko rozjazdu wersji.
- *Płatne API (OpenAI / Voyage)* — najwyższa jakość, ale cała wiedza firmowa
  wychodzi do zewnętrznego dostawcy, a mining repozytoriów to duży wolumen
  wywołań.

---

## D-004 — Postgres źródłem prawdy dla wiki, pałac warstwą wyszukiwania

**Data:** 2026-09-12 · **Stan:** Przyjęta

Dokumenty wiki mieszkają w schemacie `ws` z pełnymi rewizjami. Po publikacji
treść jest dodatkowo wypychana do pałaca jako szuflada, żeby agenci znajdowali
ją semantycznie. Pamięci agenckie (transkrypty, dziennik, graf wiedzy) żyją
natywnie w pałacu; `ws.memory_entries` trzyma ich metadane do filtrowania
uprawnień i audytu. Przepływ jest **jednokierunkowy**.

**Dlaczego:** wersjonowanie, diff i rollback to zadanie relacyjnej bazy.
Magazyn wektorowy nie ma pojęcia transakcji ani historii rewizji. Metadane
w `ws` pozwalają egzekwować uprawnienia **w zapytaniu SQL**, zamiast filtrować
wyniki po ich pobraniu z pałaca.

**Odrzucono:**
- *Pałac źródłem prawdy dla wszystkiego* — „wersje" jako szuflady oznaczone
  jako zastąpione zaśmiecają wyszukiwanie semantyczne starymi treściami, a
  uprawnienia stają się filtrem po fakcie, czyli wyciekiem.
- *Dwukierunkowa synchronizacja przez workera* — najbogatsza funkcjonalnie,
  ale wprowadza rozwiązywanie konfliktów edycji, pętle synchronizacji i
  zależność od kolejności zdarzeń. Klasa błędów trudna do zdiagnozowania,
  nieproporcjonalna do korzyści.

---

## D-005 — Agent zapisuje bez bramki; wersjonowanie jako siatka bezpieczeństwa

**Data:** 2026-09-12 · **Stan:** Przyjęta

Agent AI zapisuje swobodnie we wszystkich trzech klasach wiedzy, łącznie z
dokumentacją kanoniczną. Wpis autorstwa AI dostaje status `authored_by_ai`
i osobną flagę `verified_by` — **znacznik zaufania, nie warunek publikacji**.
Kolejka propozycji istnieje jako opcja włączana per przestrzeń.

**Dlaczego:** to AI wytworzy większość zapisów — hooki MemPalace mielą
transkrypty rozmów automatycznie, a miner przerabia repozytoria. Bramka
zatwierdzania przed każdym zapisem zamieniłaby system w wąskie gardło i
wymusiłaby obchodzenie go. Ochroną jest możliwość cofnięcia (pełne rewizje),
a nie zablokowanie zapisu. Wyjątkiem są przestrzenie wrażliwe, gdzie kolejkę
włącza się świadomie.

**Ograniczenie, które zostaje:** agent nie weryfikuje własnych wpisów.
Narzędzia `ws_doc_verify` nie ma w API — weryfikacja jest czynnością człowieka
w interfejsie.

---

## D-006 — Zamknięta sieć; hooki wysyłają transkrypty przez HTTPS

**Data:** 2026-09-12 · **Stan:** Przyjęta

`postgres`, `mempalace` i `embeddings` nie mają portów na hoście. Jedyne
wejście z zewnątrz to `nginx` (TLS): `/` dla ludzi, `/mcp` dla agentów. Hooki
na maszynach deweloperów **nie piszą do bazy** — dosyłają przyrostowo
transkrypt sesji przez HTTPS, a mielenie dzieje się po stronie serwera.

**Dlaczego:** wariant, w którym hooki piszą wprost przez
`MEMPALACE_PGVECTOR_DSN`, wymagałby wystawienia Postgresa do internetu. Baza
z całą wiedzą firmy na publicznym porcie to zła wymiana za wygodę.

**Korzyść uboczna, znacząca:** deweloper nie musi mieć zainstalowanego
MemPalace, Pythona ani modelu embeddingów. Wystarczy plugin i token.

**Odrzucono:** *dostęp do Postgresa przez VPN/WireGuard* — możliwy do dodania
później, jeśli pojawi się potrzeba lokalnego minowania repozytoriów bez
wysyłania ich na serwer. Nie jest potrzebny do działania systemu.

---

## D-007 — Kurowany zestaw narzędzi MCP

**Data:** 2026-09-12 · **Stan:** Przyjęta

Gateway wystawia agentom kilkanaście narzędzi firmowych (`ws_*`), a nie
36 narzędzi MemPalace na wylot.

**Dlaczego:** granica narzędzi **jest** granicą uprawnień. Narzędzie
MemPalace, które przyjmuje dowolne `wing`, pozwoliłoby agentowi odczytać
przestrzeń, do której jego właściciel nie ma prawa. Dodatkowo żadne z naszych
narzędzi nie ma parametru „autor" — tożsamość wynika z tokena, więc podszycie
się jest niewyrażalne w API, a nie tylko zabronione regulaminem.

---

## D-008 — Rozdzielenie backendu i frontendu

**Data:** 2026-09-12 15:58 · **Stan:** Przyjęta
· **Zmienia warstwę prezentacji z D-001**

Backend to **czyste API** Symfony 8 (API Platform 4.3) pod `/api`, bez
szablonów renderujących interfejs. Frontend to **osobna aplikacja** Vue 3 na
Vite 7, budowana niezależnie. Jedyny kontrakt między nimi to OpenAPI. `nginx`
kieruje `/` na frontend, a `/api` i `/mcp` na backend — ten sam origin, więc
przeglądarka nie dotyka CORS-a.

Wzorzec przeniesiony z **nowszy projekt z frontendem Vue**, gdzie ten rozdział już się
sprawdził: osobne katalogi aplikacji, nginx jako proxy tego samego originu,
Vite z HMR za nginxem w dev.

**Dlaczego:**

- **Backend musi działać niezależnie.** Gateway MCP dla agentów i REST dla
  ludzi to dwie powierzchnie nad tą samą logiką domenową. Jeśli backend
  renderowałby interfejs, ta logika zaczęłaby wyciekać do szablonów, a agenci
  i ludzie dostaliby rozjeżdżające się zachowania.
- **Interfejs bazy wiedzy jest z natury interaktywny** — wyszukiwanie na żywo,
  porównywanie rewizji, edytor z podglądem, drzewo przestrzeni. Renderowanie
  tego po stronie serwera w Twigu oznaczałoby pisanie tego samego dwa razy:
  raz w HTML, raz w JS.
- **Kompetencja zespołu jest po obu stronach** — Symfony (główna aplikacja Symfony zespołu) i Vue 3
  (nowszy projekt z frontendem Vue). Nie wprowadzamy nowej technologii, tylko używamy
  dwóch już używanych.
- **Frontend można podmienić bez dotykania backendu**, a backend testować bez
  frontendu. Kontrakt OpenAPI jest jednocześnie dokumentacją i punktem
  zaczepienia dla testów.

**Koszt:** jedna usługa więcej w Compose, uwierzytelnianie tokenowe (JWT)
zamiast prostszej sesji, i dwa zestawy zależności do aktualizowania.
Uznano za akceptowalny — to dokładnie ten sam koszt, który 2.0 już ponosi.

**Odrzucono:** *Twig w monolicie* — szybsze na start, ale interfejs bazy
wiedzy urósłby do JS-a i tak, tylko bez struktury.

---

## D-009 — Edytor wiki: CodeMirror 6, nie WYSIWYG

**Data:** 2026-09-12 15:58 · **Stan:** Przyjęta

Dokumenty wiki edytujemy w **CodeMirror 6** jako Markdown, z podglądem obok.
Nie używamy edytora WYSIWYG.

**Dlaczego:** to samo pole treści zapisują ludzie i agenci AI. Agent produkuje
Markdown i tylko Markdown. Edytor WYSIWYG musiałby przy każdym otwarciu
przekonwertować Markdown na swój model dokumentu, a przy zapisie z powrotem —
i każdy taki obieg gubi to, czego model nie obsługuje: tabele o nietypowym
wyrównaniu, bloki kodu z nazwą języka, przypisy, komentarze HTML. Przy
dokumencie krążącym między człowiekiem i AI ta utrata kumuluje się cicho.

Markdown jako **jedyna** reprezentacja usuwa tę klasę błędów całkowicie: to,
co agent zapisał, jest dokładnie tym, co człowiek widzi i edytuje.

**Odrzucono:** *TipTap* — używany w nowszy projekt z frontendem Vue i dobry w swojej
roli (treści redakcyjne pisane wyłącznie przez ludzi), ale tutaj jego model
dokumentu stałby się drugą reprezentacją prawdy.
