# Beauty Contest (Web Multiplayer)

Full-stack real-time multiplayer version of the Beauty Contest game.

## Structure

- `backend/` — FastAPI + WebSockets server (Python). Reuses `game_logic.py`.
- `frontend/` — React (Vite) + TailwindCSS client.

## Run

Install dependencies once:

```bash
cd backend && pip install -r requirements.txt
cd ../frontend && npm install --include=dev
```

Then start everything from the project root with a single command:

```bash
python run.py
```

This launches the backend (http://localhost:8000) and frontend (http://localhost:5173) together. Press Ctrl+C to stop both.

Open the frontend URL and create/join a room.