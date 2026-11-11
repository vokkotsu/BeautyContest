import asyncio
import json
import os
import uuid
from fastapi import FastAPI, WebSocket, WebSocketDisconnect
from fastapi.middleware.cors import CORSMiddleware
from room_manager import manager

app = FastAPI(title="Beauty Contest Multiplayer")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


async def broadcast_round_start(room):
    await room.broadcast({
        "type": "round_start",
        "submitted": list(room.submissions.keys()),
        "round": room.round_number,
        "history": room.history,
    })


def schedule_auto_next_round(room):
    room.cancel_auto_next()

    async def _auto_next():
        try:
            await asyncio.sleep(room.READY_COUNTDOWN)
            async with room.lock:
                if room.state != "result":
                    return
                mw = room.game.get_match_winner() if room.game else None
                if mw is not None:
                    return
                room.next_round()
                await broadcast_round_start(room)
        except asyncio.CancelledError:
            return
        finally:
            if room.auto_next_task is asyncio.current_task():
                room.auto_next_task = None

    room.auto_next_task = asyncio.create_task(_auto_next())


@app.get("/")
def root():
    return {"status": "ok", "service": "beauty-contest"}


@app.websocket("/ws")
async def websocket_endpoint(ws: WebSocket):
    await ws.accept()
    player_id = str(uuid.uuid4())
    room = None
    code = None
    rejoined = False
    explicit_leave = False
    try:
        raw = await ws.receive_text()
        data = json.loads(raw)
        if data.get("type") == "create_room":
            name = (data.get("name") or "Player").strip() or "Player"
            mode = data.get("mode", "multi")
            single = (mode == "single")
            bot_count = int(data.get("bots", 0) or 0)
            bot_count = max(0, min(9, bot_count))
            if single:
                bot_count = max(1, bot_count) if bot_count == 0 else bot_count
            code = manager.create_room(player_id, single_player=single, bot_count=bot_count)
            room = manager.get_room(code)
            room.add_player(player_id, name, ws)
            if single:
                room.setup_bots()
        elif data.get("type") == "join_room":
            name = (data.get("name") or "Player").strip() or "Player"
            code = (data.get("code") or "").strip().upper()
            room = manager.get_room(code)
            if room is None:
                await ws.send_json({"type": "error", "message": "Room not found"})
                await ws.close()
                return
            if room.single_player:
                await ws.send_json({"type": "error", "message": "Single-player room"})
                await ws.close()
                return
            if len(room.players) + len(room.bots) >= 10:
                await ws.send_json({"type": "error", "message": "Room is full"})
                await ws.close()
                return
            room.add_player(player_id, name, ws)
        elif data.get("type") == "rejoin_room":
            player_id = (data.get("playerId") or "").strip()
            code = (data.get("code") or "").strip().upper()
            room = manager.get_room(code)
            if room is None or not player_id or player_id not in room.players:
                await ws.send_json({"type": "session_invalid", "message": "Session not found"})
                await ws.close()
                return
            room.attach_player(player_id, ws)
            rejoined = True
            await ws.send_json(room.state_snapshot(player_id))
            await room.broadcast({"type": "lobby_update", "players": (room.player_list() if room.state == "lobby" or room.game is None else room.game_players_payload()), "hostId": room.host_id})
        else:
            await ws.send_json({"type": "error", "message": "First message must create, join, or rejoin a room"})
            await ws.close()
            return

        if not rejoined:
            await ws.send_json({
                "type": "joined",
                "playerId": player_id,
                "code": code,
                "name": room.players.get(player_id),
                "isHost": player_id == room.host_id,
                "players": room.player_list(),
                "singlePlayer": room.single_player,
            })
            await room.broadcast({"type": "lobby_update", "players": (room.player_list() if room.state == "lobby" or room.game is None else room.game_players_payload()), "hostId": room.host_id})

        while True:
            raw = await ws.receive_text()
            data = json.loads(raw)
            t = data.get("type")

            async with room.lock:
                if t == "start_game":
                    if player_id != room.host_id:
                        await ws.send_json({"type": "error", "message": "Only host can start"})
                        continue
                    if room.single_player and not room.bots:
                        room.setup_bots()
                    if len(room.players) + len(room.bots) < 2:
                        await ws.send_json({"type": "error", "message": "Need at least 2 players"})
                        continue
                    if room.state != "lobby":
                        continue
                    room.start_game()
                    await room.broadcast({"type": "game_started", "round": room.round_number, "history": room.history})
                    # bots already submitted inside start_game; broadcast state
                    await room.broadcast({"type": "round_start", "submitted": list(room.submissions.keys()), "round": room.round_number, "history": room.history})

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
                        await ws.send_json({"type": "error", "message": "You are eliminated and cannot submit"})
                        continue
                    room.submit(player_id, val)
                    submitted_ids = list(room.submissions.keys())
                    await room.broadcast({"type": "submission_update", "submitted": submitted_ids})
                    if room.all_submitted():
                        payload = room.calculate()
                        await room.broadcast(payload)
                        if not payload.get("gameOver"):
                            schedule_auto_next_round(room)

                elif t == "ready_next":
                    if room.state != "result":
                        continue
                    mw = room.game.get_match_winner() if room.game else None
                    if mw is not None:
                        continue
                    changed = room.mark_ready(player_id)
                    if changed and room.all_humans_ready():
                        room.next_round()
                        await broadcast_round_start(room)
                    elif changed:
                        await room.broadcast(room.ready_payload())

                elif t == "next_round":
                    # Backwards-compat: allow host to force-start, but prefer ready flow.
                    if player_id != room.host_id:
                        continue
                    if room.state != "result":
                        continue
                    mw = room.game.get_match_winner() if room.game else None
                    if mw is not None:
                        continue
                    room.next_round()
                    await broadcast_round_start(room)

                elif t == "leave":
                    explicit_leave = True
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
            if explicit_leave:
                room.remove_player(player_id)
                if not room.players and not room.single_player:
                    room.cancel_auto_next()
                    manager.remove_empty(code)
                elif room.state == "result":
                    mw = room.game.get_match_winner() if room.game else None
                    if mw is None and room.all_humans_ready():
                        room.next_round()
                        await broadcast_round_start(room)
                    else:
                        await room.broadcast(room.ready_payload())
                        await room.broadcast({"type": "lobby_update", "players": (room.player_list() if room.state == "lobby" or room.game is None else room.game_players_payload()), "hostId": room.host_id})
                else:
                    await room.broadcast({"type": "lobby_update", "players": (room.player_list() if room.state == "lobby" or room.game is None else room.game_players_payload()), "hostId": room.host_id})
            else:
                room.detach_player(player_id)


if __name__ == "__main__":
    import uvicorn
    port = int(os.environ.get("PORT", 8000))
    uvicorn.run("main:app", host="0.0.0.0", port=port)
