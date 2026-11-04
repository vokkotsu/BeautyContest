import asyncio
import json
import uuid
from fastapi import FastAPI, WebSocket, WebSocketDisconnect
from fastapi.middleware.cors import CORSMiddleware
from room_manager import manager, Room

app = FastAPI(title="Beauty Contest Multiplayer")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


@app.get("/")
def root():
    return {"status": "ok", "service": "beauty-contest"}


@app.websocket("/ws")
async def websocket_endpoint(ws: WebSocket):
    await ws.accept()
    player_id = str(uuid.uuid4())
    room = None
    code = None
    try:
        # first message must create/join
        raw = await ws.receive_text()
        data = json.loads(raw)
        if data.get("type") == "create_room":
            name = (data.get("name") or "Player").strip() or "Player"
            code = manager.create_room(player_id)
            room = manager.get_room(code)
            room.add_player(player_id, name, ws)
        elif data.get("type") == "join_room":
            name = (data.get("name") or "Player").strip() or "Player"
            code = (data.get("code") or "").strip().upper()
            room = manager.get_room(code)
            if room is None:
                await ws.send_json({"type": "error", "message": "Room not found"})
                await ws.close()
                return
            if len(room.players) >= 10:
                await ws.send_json({"type": "error", "message": "Room is full"})
                await ws.close()
                return
            room.add_player(player_id, name, ws)
        else:
            await ws.send_json({"type": "error", "message": "First message must create or join a room"})
            await ws.close()
            return

        # acknowledge
        await ws.send_json({
            "type": "joined",
            "playerId": player_id,
            "code": code,
            "isHost": player_id == room.host_id,
            "players": room.player_list(),
        })
        await room.broadcast({"type": "lobby_update", "players": room.player_list(), "hostId": room.host_id})

        # main loop
        while True:
            raw = await ws.receive_text()
            data = json.loads(raw)
            t = data.get("type")

            async with room.lock:
                if t == "start_game":
                    if player_id != room.host_id:
                        await ws.send_json({"type": "error", "message": "Only host can start"})
                        continue
                    if len(room.players) < 2:
                        await ws.send_json({"type": "error", "message": "Need at least 2 players"})
                        continue
                    if room.state != "lobby":
                        continue
                    room.start_game()
                    await room.broadcast({"type": "game_started", "round": room.round_number})
                    await room.broadcast({"type": "round_start", "submitted": []})

                elif t == "submit":
                    if room.state != "playing":
                        continue
                    if player_id in room.submissions:
                        continue
                    try:
                        val = float(data.get("value"))
                    except (TypeError, ValueError):
                        await ws.send_json({"type": "error", "message": "Invalid number"})
                        continue
                    val = max(0.0, min(100.0, val))
                    if room._is_eliminated(player_id):
                        continue
                    room.submit(player_id, val)
                    submitted_ids = list(room.submissions.keys())
                    await room.broadcast({"type": "submission_update", "submitted": submitted_ids})
                    if room.all_submitted():
                        payload = room.calculate()
                        await room.broadcast(payload)

                elif t == "next_round":
                    if player_id != room.host_id:
                        continue
                    if room.state != "result":
                        continue
                    # check game over
                    mw = room.game.get_match_winner() if room.game else None
                    if mw is not None:
                        # game already over; ignore
                        continue
                    room.next_round()
                    await room.broadcast({"type": "round_start", "submitted": [], "round": room.round_number})

                elif t == "leave":
                    break

    except WebSocketDisconnect:
        pass
    except Exception as e:
        try:
            await ws.send_json({"type": "error", "message": str(e)})
        except Exception:
            pass
    finally:
        if room is not None and player_id in room.players:
            room.remove_player(player_id)
            if room.players:
                await room.broadcast({"type": "lobby_update", "players": room.player_list(), "hostId": room.host_id})
            else:
                manager.remove_empty(code)


if __name__ == "__main__":
    import uvicorn
    uvicorn.run("main:app", host="0.0.0.0", port=8000, reload=True)
