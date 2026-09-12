---
noteId: "50cd7590aeb211f1997d030a3cd38ca7"
tags: []

---

# 004 — Backend: gateway MCP i tokeny agentów

**Utworzono:** 2026-09-12 16:05 · **Stan:** do zrobienia · **Zależności:** 003

## Powód

To powierzchnia, przez którą agenci AI korzystają z bazy wiedzy — i
jednocześnie granica uprawnień (D-007). MemPalace wystawia 36 narzędzi
przyjmujących dowolne `wing`; przepuszczenie ich na wylot oznaczałoby brak
jakichkolwiek uprawnień.

## Analiza

Protokół MCP po HTTP to JSON-RPC 2.0 z trzema metodami, które musimy obsłużyć:
`initialize`, `tools/list`, `tools/call`. Klient (Claude Code) łączy się przez
`claude mcp add --transport http`.

Rozróżnienie, które trzeba zaimplementować świadomie: **brak uprawnień zwraca
pusty wynik, nie błąd** (reguła nr 7). Komunikat „nie masz dostępu do
przestrzeni Kadry" sam ujawnia, że taka przestrzeń istnieje.

Tokeny agentów: własny mechanizm, nie JWT. Powody — muszą być długowieczne,
unieważnialne natychmiast, z zakresem zawężającym uprawnienia właściciela i z
widocznym „ostatnio użyty" (żeby dało się poznać martwe tokeny). Hash w bazie,
wartość pokazywana **raz**, przy wystawieniu.

## Rozwiązanie

1. `POST /mcp` — kontroler JSON-RPC: `initialize` (deklaracja możliwości),
   `tools/list` (schematy narzędzi), `tools/call` (wykonanie).
2. Uwierzytelnianie tokenem agenta: rozwiązanie na właściciela, sprawdzenie
   `revoked_at` i `expires_at`, aktualizacja `last_used_at` i `last_used_ip`.
3. Zakres tokena jako **przecięcie**: `żądane ∩ uprawnienia_właściciela ∩
   space_scope`. Nigdy suma.
4. Narzędzia czytające: `ws_status`, `ws_search`, `ws_get`, `ws_kg_query`
   (`ws_doc_*` dochodzą w zadaniu 005).
5. Narzędzia piszące: `ws_remember`, `ws_kg_add`, `ws_diary_write`.
6. Schematy narzędzi **bez parametru `author`** — tożsamość wyłącznie z tokena.
7. Endpointy zarządzania tokenami w `/api` (wystawienie, lista, unieważnienie)
   — dla frontendu, zadanie 008.
8. Audyt każdego wywołania MCP: narzędzie, przestrzeń, wynik (liczba trafień),
   IP.
9. Ograniczenie tempa per token, żeby pętla w agencie nie zajechała pałaca.

## Kryteria ukończenia

- `claude mcp add --transport http ws_memory <url>/mcp --header "Authorization: Bearer <token>"`
  działa, `tools/list` zwraca zestaw narzędzi.
- Wywołanie `ws_search` ze wskazaniem obcej przestrzeni zwraca **pusty wynik**,
  nie błąd i nie treść.
- Unieważniony token daje `401` przy pierwszym kolejnym wywołaniu.
- Token z zawężonym zakresem nie widzi przestrzeni spoza zakresu, choć
  właściciel je widzi (test).
- Żaden schemat narzędzia nie zawiera pola autora (test przeglądający
  `tools/list`).
- Każde wywołanie ma wpis w `audit_log`.
