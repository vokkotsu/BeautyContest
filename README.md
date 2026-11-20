# Beauty Contest (Web) — PHP (shared-hosting friendly)

A web-based implementation of the **Beauty Contest** game (guess 2/3 of the average). Play solo against bots or multiplayer with friends in a room.

This branch ports the original Python/FastAPI backend to **PHP** and uses an **HTTP polling + MySQL** architecture for multiplayer so it can run on typical shared hosting (e.g. Hostinger Premium) without a long-running WebSocket process.

## How to Play

- Pick a number from **0–100** each round.
- The **target** is the average of all active picks × **0.8**. The closest valid pick wins the round.
- Everyone starts at **0 points**. Round losers lose **1 point**; reaching **-10** eliminates a player.
- The **last player standing** wins the match.

**Special rules (by active player count):**
- **≤ 4 players:** duplicate picks are invalid for that round.
- **≤ 3 players:** an exact hit on the rounded target is a *Critical Hit* (losers lose **2** points).
- **2 players:** if the picks are 0 and 100, the player who picked **100** wins the round.

## Features

- **Single player:** play locally in the browser against 1–9 AI opponents (no backend required).
- **Multiplayer rooms:** create/join rooms via room code over HTTP polling (`/api`) with state stored in MySQL.
- **Session persistence:** multiplayer sessions can rejoin after a refresh.
- **Round history & scoreboard:** per-round picks, averages/targets, logs, and live scores.
- **Light/dark mode** and in-app "How to Play" / tips.

## Project Structure

- `backend_php/` — PHP multiplayer backend (stateless HTTP API + MySQL) and game logic.
  - `api.php` — API router (`/api/room/*`, `/api/game/*`, `/api/room/state`).
  - `game_service.php` — room lifecycle, polling state snapshots, and deadline-based timers (pick/ready).
  - `game_logic.php` — core Beauty Contest rules and scoring (banker's rounding, same behavior).
  - `db.php` — PDO connection helper.
  - `config.php` — game constants and DB configuration (`DB_*` env vars).
  - `schema.sql` — MySQL schema (rooms, participants, submissions, readies, history).
  - `composer.json` — PHP dependencies (autoload).
  - `server.php`, `room_manager.php` — legacy WebSocket/Ratchet implementation (kept for reference; not used by the HTTP+MySQL flow).
- `backend/` — original Python backend (kept for reference).
- `frontend/` — React (Vite) + Tailwind CSS client (`src/App.jsx`, `src/gameLogic.js`).
- `index.php` — front controller: serves the built SPA from `frontend/dist`, routes `/api/*` to the backend.
- `.htaccess` — rewrites `/api/*` to `backend_php/api.php` and falls back SPA routes to `index.php` (Apache/LiteSpeed).
- `run_php.php` — convenience script to run the built app + API locally via PHP's built-in server.
- `run.py` — original Python/Node convenience script.

## Run Locally

**Prerequisites:** PHP 8.1+ (with `pdo_mysql` and `json`), Composer, Node.js/npm, and a MySQL/MariaDB database (required for multiplayer).

### 1. Create database and configure

Create a database and import the schema:

```bash
mysql -u <user> -p <database> < backend_php/schema.sql
```

Configure DB credentials in `backend_php/config.php` or via environment variables: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` (defaults: host `127.0.0.1`, port `3306`, db `beautycontest`, user `root`, empty password).

### 2. Build the frontend

```bash
cd frontend
npm install --include=dev
npm run build
```

Multiplayer API calls are same-origin (`/api`) by default, so no extra build-time URL is required for typical deployments. To point at a different API origin, set `VITE_API_BASE` before building.

### 3. Start the app

From the project root:

```bash
php run_php.php
```

- App + API: http://localhost:5173 (serves `frontend/dist` and `/api`)

Open the URL, choose Single Player or Multiplayer, and start playing. Press `Ctrl+C` to stop. (Single Player works without a database; Multiplayer requires the configured database.)

## Deployment (Hostinger Premium / shared hosting)

This architecture is designed for shared hosting: static SPA + PHP endpoints + MySQL (no persistent process, no custom port).

1. **Database:** create a MySQL database/user in hPanel and import `backend_php/schema.sql`.
2. **Configure:** set DB credentials via environment variables (`DB_HOST/DB_NAME/DB_USER/DB_PASS`) or edit `backend_php/config.php`.
3. **Build:** `cd frontend && npm install --include=dev && npm run build` (same-origin `/api`, so `VITE_API_BASE` usually not needed).
4. **Upload** to your domain root (e.g. `public_html`):
   - `index.php`, `.htaccess`
   - `frontend/dist/` (built assets)
   - `backend_php/` (`api.php`, `game_service.php`, `game_logic.php`, `db.php`, `config.php`, `schema.sql`)
   (You do not need `node_modules`, frontend source, or the legacy `server.php`/`room_manager.php` in production.)
5. **hPanel:** set PHP 8.1+ for the domain and ensure `.htaccess` rewrites are enabled (default). Enable SSL.
6. **Test:** open the domain. Single Player runs in-browser; Multiplayer uses `/api` with MySQL state, polling, and DB-stored deadlines for pick/ready timers.

The legacy WebSocket backend (`backend_php/server.php`) remains in the repo for reference but is not required for this deployment model.