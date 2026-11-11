# Beauty Contest (Web)

A real-time, web-based implementation of the **Beauty Contest** game (guess 2/3 of the average). Play solo against bots or multiplayer with friends in a room.

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

- **Single player:** play locally against 1–9 AI opponents.
- **Multiplayer rooms:** create/join rooms via room code over WebSockets, with host-controlled rounds.
- **Session persistence:** multiplayer sessions can rejoin after a refresh.
- **Round history & scoreboard:** per-round picks, averages/targets, logs, and live scores.
- **Light/dark mode** and an in-app "How to Play" guide on the main screen.

## Project Structure

- `backend/` — FastAPI + WebSockets server (game/room logic).
  - `main.py` — WebSocket API (`/ws`) and message handling.
  - `room_manager.py` — rooms, sessions, round flow.
  - `game_logic.py` — core Beauty Contest rules and scoring.
  - `config.py` — game constants.
- `frontend/` — React (Vite) + Tailwind CSS client (`src/App.jsx`, `src/gameLogic.js`).
- `run.py` — convenience script to start backend and frontend together.

## Run Locally

**Prerequisites:** Python 3 and Node.js/npm.

Install dependencies once:

```bash
cd backend && pip install -r requirements.txt
cd ../frontend && npm install --include=dev
```

Start everything from the project root:

```bash
python run.py
```

- Backend: http://localhost:8000
- Frontend: http://localhost:5173

Open the frontend URL, choose Single Player or Multiplayer, and start playing. Press `Ctrl+C` to stop both.

## Deployment

- `backend/` includes `Procfile` and `render.yaml` for hosting the FastAPI/WebSocket service (e.g. Render).
- `frontend/` and the root include `vercel.json` for frontend deployment (e.g. Vercel). For deployed builds, set `VITE_WS_URL` to your backend WebSocket endpoint (e.g. `wss://<your-backend>/ws`).