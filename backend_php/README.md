# Beauty Contest - PHP Backend (HTTP polling + MySQL)

This is the PHP implementation of the Beauty Contest multiplayer backend, designed to run on shared hosting (no long-running WebSocket process). It exposes a stateless JSON HTTP API under `/api` and stores room/game state in MySQL. Game rules and scoring are preserved (see `game_logic.php` / `config.php`, banker's rounding).

## Requirements

- PHP 8.1+
- MySQL/MariaDB
- PHP extensions: `pdo_mysql`, `json`, `mbstring`
- Composer (for autoload/classmap convenience)

## Setup

1. Install autoload (optional but recommended):

```bash
cd backend_php
composer install
```

2. Create a database and import the schema:

```bash
mysql -u <user> -p <database> < schema.sql
```

3. Configure database access in `config.php` or via environment variables:

- `DB_HOST` (default `127.0.0.1`)
- `DB_PORT` (default `3306`)
- `DB_NAME` (default `beautycontest`)
- `DB_USER` (default `root`)
- `DB_PASS` (default empty)

Set `APP_DEBUG=1` to include exception messages in 500 responses (development only).

## API

Base path: `/api`

- `POST /api/room/create` `{name}` → `{type:"joined", playerId, code, name, isHost, players, singlePlayer}` 
- `POST /api/room/join` `{name, code}` → joined or error
- `POST /api/room/rejoin` `{playerId, code}` → `{type:"state_sync", ...}` or `session_invalid`
- `GET  /api/room/state?code=...&playerId=...` → `state_sync` snapshot (used for polling)
- `POST /api/game/start` `{code, playerId}` (host)
- `POST /api/game/submit` `{code, playerId, value}`
- `POST /api/game/ready` `{code, playerId}`
- `POST /api/game/leave` `{code, playerId}`

`state_sync` includes `state` (`lobby|playing|result`), `round`, `players`, `submitted`, `history`, `pickCountdown` (playing), and `result` (result, with ready info/countdown and `gameOver/matchWinnerId`).

Timers are deadline-based in the database (60s pick, 15s ready/auto-next) and are evaluated on each state/command request, so polling drives progression without a background worker.

## Notes

- `server.php` and `room_manager.php` are the legacy WebSocket/Ratchet implementation kept for reference; the HTTP+MySQL flow uses `api.php` + `game_service.php` + `db.php`.
- Single-player mode runs entirely in the browser and does not use this backend.