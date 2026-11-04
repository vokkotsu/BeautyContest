# Beauty Contest (Web Multiplayer)

Full-stack real-time multiplayer version of the Beauty Contest game.

## Structure

- `backend/` — FastAPI + WebSockets server (Python). Reuses `game_logic.py`.
- `frontend/` — React (Vite) + TailwindCSS client.

## Run

Backend:
```bash
cd backend
pip install -r requirements.txt
python -m uvicorn main:app --reload --port 8000
```

Frontend:
```bash
cd frontend
npm install --include=dev
npm run dev
```

Open the frontend URL (default http://localhost:5173) and create/join a room.