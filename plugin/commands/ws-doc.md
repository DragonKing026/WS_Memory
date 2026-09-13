---
noteId: "d037a402af8311f18a50cfad3ca0cc8a"
tags: []
description: "Zapisz dokument do firmowej bazy wiedzy — z rewizją, przestrzenią i opisem zmiany"
argument-hint:
  - "co udokumentować"

---

Do udokumentowania: **$ARGUMENTS**

Zanim napiszesz:

1. `ws_status` — sprawdź, do jakich przestrzeni masz prawo.
2. `ws_doc_list` i `ws_search` — sprawdź, **czy dokument na ten temat już
   istnieje**. Nowa rewizja jest prawie zawsze lepsza niż drugi dokument
   o tym samym.

Potem `ws_doc_write` według zasad ze skilla `ws-memory-document`:

- adres nazywa **rzecz**, nie okazję (`wdrozenia/backup-bazy`, nie
  `notatki-ze-spotkania`);
- treść: co to jest → jak działa → dlaczego tak → czego nie obejmuje;
- **`change_note` mówi, co się zmieniło i po co** — to jedyny opis, jaki
  zobaczy człowiek w historii dokumentu;
- **przestrzeń podana jawnie**, jeśli dokument ma być dla zespołu. Zapis bez
  niej ląduje w prywatnej przestrzeni właściciela tokena.

Jeśli przestrzeń wymaga przeglądu, `ws_doc_write` odmówi i odeśle do
`ws_propose`. To inna droga, nie błąd.

Na koniec podaj adres i przestrzeń dokumentu oraz **powiedz wprost, że czeka
na weryfikację człowieka** — sam sobie tej flagi nie postawisz.
