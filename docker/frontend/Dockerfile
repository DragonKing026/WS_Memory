# Obraz frontendu — dwa etapy, dwa zupełnie różne cele.
#
# `dev`: Node z pnpm i Vite, źródła montowane wolumenem z hosta, więc zmiana pliku
# jest widoczna w przeglądarce bez przebudowy obrazu.
#
# `prod`: build do statycznego `dist/`, który serwuje nginx. Frontend nie potrzebuje
# w produkcji ŻADNEGO procesu Node — a to znaczy jeden proces mniej do monitorowania,
# restartowania i łatania.

# ---------------------------------------------------------------- baza wspólna
FROM node:22-alpine AS baza

# corepack jest w obrazie Node, więc pnpm nie wymaga instalacji przez npm.
# Wersja przypięta w package.json ("packageManager") — corepack ją respektuje.
RUN corepack enable

WORKDIR /app

# ------------------------------------------------------------------------- dev
FROM baza AS dev

# Zależności instalujemy w obrazie, ale katalog node_modules zostaje w kontenerze
# (w compose przysłonięty anonimowym wolumenem). Inaczej pnpm z hosta i pnpm
# z kontenera walczyłyby o ten sam katalog — a mają różne systemy plików
# i różne architektury binariów.
COPY frontend/package.json frontend/pnpm-lock.yaml ./
# --frozen-lockfile bez awarii do zwykłego install: build, który po cichu rozwiązuje
# zależności inaczej niż plik blokady, przestaje być powtarzalny — a to jedyna rzecz,
# po którą się ten plik trzyma.
RUN pnpm install --frozen-lockfile

COPY frontend/ ./

EXPOSE 5173

# Nakładka dockerowa: host 0.0.0.0, polling dla HMR przez bind mount, port
# websocketu widziany przez przeglądarkę (nginx), bez proxy Vite.
CMD ["pnpm", "vite", "--config", "vite.docker.config.mts"]

# ------------------------------------------------------------------ build prod
FROM baza AS budowa

COPY frontend/package.json frontend/pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile

COPY frontend/ ./

# `pnpm build` zawiera vue-tsc: typ, który się nie zgadza, ma zatrzymać wydanie,
# a nie wyjść na produkcji jako pusty ekran.
RUN pnpm build

# ------------------------------------------------------------------------ prod
FROM nginx:1.29-alpine AS prod

# Tylko wynik budowania. Ani Node, ani źródeł, ani node_modules — obraz produkcyjny
# nie ma czym uruchomić kodu, którego nie powinien uruchamiać.
COPY --from=budowa /app/dist /usr/share/nginx/html
COPY docker/nginx/frontend-prod.conf /etc/nginx/conf.d/default.conf

EXPOSE 5173
