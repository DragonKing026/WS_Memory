#!/usr/bin/env python3
"""Porównuje modele embeddingów na polskich parach zdań.

Po co: domyślny wybór (BAAI/bge-m3) okazał się zbyt ciężki — przy domyślnych
ustawieniach TEI zajął ~21 GB i zdławił maszynę. Zamiast zgadywać następcę,
mierzymy: zużycie pamięci, czas ładowania, czas odpowiedzi i — najważniejsze —
czy model odróżnia zdania powiązane znaczeniowo od niepowiązanych.

Liczy się MARGINES: różnica między podobieństwem pary trafnej a kontrolnej.
Model, który wszystkiemu daje 0.9, jest bezużyteczny w wyszukiwaniu.

Każdy model dostaje twardy limit pamięci i jest sprzątany po pomiarze.
"""
import json
import subprocess
import sys
import time
import urllib.error
import urllib.request

OBRAZ = "ghcr.io/huggingface/text-embeddings-inference:cpu-1.8"
KONTENER = "ws-bench-embeddings"
PORT = 18080
WOLUMEN = "ws-memory_embeddings-cache"
LIMIT_PAMIECI = "3g"

# (model, domyślny prompt, limit pamięci). Prompt jest konieczny dla rodziny
# E5, która była trenowana z prefiksami; MemPalace ich nie doda, więc wymuszamy
# je po stronie serwera embeddingów (TEI --default-prompt).
KANDYDACI = [
    ("intfloat/multilingual-e5-small", "query: ", "3g"),
    ("intfloat/multilingual-e5-base", "query: ", "3g"),
    ("sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2", None, "3g"),
    ("BAAI/bge-m3", None, "4g"),
]

# Pary trafne muszą wyjść wysoko, kontrolna nisko. Treści z realnych projektów.
PARY = [
    ("najem",
     "Umowa najmu lokalu wymaga aneksu przy zmianie stawki czynszu",
     "zmiana opłaty za wynajem — jakie dokumenty", True),
    ("pdf",
     "Eksport PDF ma wyłączone pobieranie zdalnych zasobów, więc logo trzeba osadzić jako data:",
     "dlaczego w wygenerowanym dokumencie nie widać grafiki firmowej", True),
    ("kontrolna",
     "Umowa najmu lokalu wymaga aneksu przy zmianie stawki czynszu",
     "konfiguracja serwera pocztowego i limity wysyłki wiadomości", False),
]


def uruchom(polecenie, **kw):
    return subprocess.run(polecenie, capture_output=True, text=True, **kw)


def sprzataj():
    uruchom(["docker", "rm", "-f", KONTENER])


def wystartuj(model, prompt, limit):
    sprzataj()
    polecenie = [
        "docker", "run", "-d", "--name", KONTENER,
        "-p", f"127.0.0.1:{PORT}:80",
        "-v", f"{WOLUMEN}:/data",
        "--memory", limit, "--cpus", "4",
        OBRAZ,
        "--model-id", model, "--port", "80",
        "--max-batch-tokens", "2048",
        "--max-client-batch-size", "8",
        "--tokenization-workers", "2",
        "--auto-truncate",
    ]
    if prompt:
        polecenie += ["--default-prompt", prompt]
    wynik = uruchom(polecenie)
    if wynik.returncode != 0:
        raise RuntimeError(f"nie udało się uruchomić: {wynik.stderr.strip()}")


def czekaj_na_gotowosc(limit_s=900):
    start = time.time()
    while time.time() - start < limit_s:
        try:
            with urllib.request.urlopen(f"http://127.0.0.1:{PORT}/health", timeout=3) as o:
                if o.status == 200:
                    return time.time() - start
        except (urllib.error.URLError, OSError):
            pass
        stan = uruchom(["docker", "inspect", "-f", "{{.State.Status}}", KONTENER]).stdout.strip()
        if stan not in ("running", "created", ""):
            dziennik = uruchom(["docker", "logs", "--tail", "5", KONTENER]).stderr
            raise RuntimeError(f"kontener zatrzymał się ({stan}): {dziennik.strip()[:300]}")
        time.sleep(2)
    raise TimeoutError(f"model nie wstał w {limit_s}s")


def pamiec_mb():
    wynik = uruchom(["docker", "stats", "--no-stream", "--format", "{{.MemUsage}}", KONTENER])
    surowe = wynik.stdout.strip().split("/")[0].strip()
    if surowe.endswith("GiB"):
        return float(surowe[:-3]) * 1024
    if surowe.endswith("MiB"):
        return float(surowe[:-3])
    return 0.0


def zanurz(teksty):
    dane = json.dumps({"input": teksty, "model": "x", "encoding_format": "float"}).encode()
    zadanie = urllib.request.Request(
        f"http://127.0.0.1:{PORT}/v1/embeddings", data=dane,
        headers={"Content-Type": "application/json"})
    start = time.time()
    with urllib.request.urlopen(zadanie, timeout=120) as o:
        odpowiedz = json.loads(o.read())
    return [w["embedding"] for w in odpowiedz["data"]], time.time() - start


def cosinus(a, b):
    iloczyn = sum(x * y for x, y in zip(a, b))
    dl_a = sum(x * x for x in a) ** 0.5
    dl_b = sum(y * y for y in b) ** 0.5
    return iloczyn / (dl_a * dl_b) if dl_a and dl_b else 0.0


def zmierz(model, prompt, limit):
    wystartuj(model, prompt, limit)
    czas_ladowania = czekaj_na_gotowosc()

    teksty = []
    for _, a, b, _ in PARY:
        teksty += [a, b]
    wektory, czas_odp = zanurz(teksty)
    pamiec = pamiec_mb()

    podobienstwa = {}
    for i, (nazwa, _, _, _) in enumerate(PARY):
        podobienstwa[nazwa] = cosinus(wektory[2 * i], wektory[2 * i + 1])

    return {
        "model": model,
        "wymiary": len(wektory[0]),
        "pamiec_mb": pamiec,
        "ladowanie_s": czas_ladowania,
        "odpowiedz_ms": czas_odp * 1000,
        "podobienstwa": podobienstwa,
        "margines": min(podobienstwa["najem"], podobienstwa["pdf"]) - podobienstwa["kontrolna"],
    }


def main():
    wyniki = []
    try:
        for model, prompt, limit in KANDYDACI:
            print(f"\n=== {model} ===", flush=True)
            try:
                w = zmierz(model, prompt, limit)
                wyniki.append(w)
                print(f"    pamięć {w['pamiec_mb']:.0f} MB · ładowanie {w['ladowanie_s']:.0f}s "
                      f"· odpowiedź {w['odpowiedz_ms']:.0f}ms · {w['wymiary']} wymiarów", flush=True)
                print(f"    najem {w['podobienstwa']['najem']:.3f} · "
                      f"pdf {w['podobienstwa']['pdf']:.3f} · "
                      f"kontrolna {w['podobienstwa']['kontrolna']:.3f} · "
                      f"MARGINES {w['margines']:.3f}", flush=True)
            except Exception as e:
                print(f"    BŁĄD: {e}", flush=True)
            finally:
                sprzataj()
    finally:
        sprzataj()

    if not wyniki:
        return 1

    print("\n\n| Model | Wymiary | Pamięć | Ładowanie | Odpowiedź | najem | pdf | kontrolna | Margines |")
    print("|---|---|---|---|---|---|---|---|---|")
    for w in sorted(wyniki, key=lambda x: -x["margines"]):
        p = w["podobienstwa"]
        print(f"| `{w['model'].split('/')[-1]}` | {w['wymiary']} | {w['pamiec_mb']:.0f} MB | "
              f"{w['ladowanie_s']:.0f}s | {w['odpowiedz_ms']:.0f}ms | {p['najem']:.3f} | "
              f"{p['pdf']:.3f} | {p['kontrolna']:.3f} | **{w['margines']:.3f}** |")
    return 0


if __name__ == "__main__":
    sys.exit(main())
