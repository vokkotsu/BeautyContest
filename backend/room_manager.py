import asyncio
import random
import string
import uuid
from game_logic import BeautyContestLogic
import config


class Room:
    READY_COUNTDOWN = 15

    def __init__(self, code, host_id, single_player=False, bot_count=0):
        self.code = code
        self.host_id = host_id
        self.single_player = single_player
        self.bot_count = bot_count
        self.players = {}  # pid -> name (humans) ws stored separately
        self.player_ws = {}  # pid -> websocket
        self.bots = []  # list of {"id":..., "name":...}
        self.game = None
        self.state = "lobby"  # lobby | playing | result
        self.submissions = {}  # pid -> number (humans and bots)
        self.current_results = None
        self.pid_to_gameid = {}
        self.round_number = 0
        self.history = []  # completed round summaries
        self.ready_ids = set()  # human pids ready for next round
        self.auto_next_task = None
        self.lock = asyncio.Lock()

    def add_player(self, pid, name, ws):
        if len(self.players) + len(self.bots) >= 10:
            return False
        self.players[pid] = name
        self.player_ws[pid] = ws
        return True

    def setup_bots(self):
        # call after host added, before game start; creates bot entries
        self.bots = []
        start_idx = len(self.players) + 1  # 1-based game ids; host is 1
        # Actually bots appended after humans in game players order
        for i in range(self.bot_count):
            self.bots.append({"id": f"bot-{i+1}", "name": f"Bot {i+1}"})

    def remove_player(self, pid):
        self.players.pop(pid, None)
        self.player_ws.pop(pid, None)
        self.submissions.pop(pid, None)
        self.ready_ids.discard(pid)
        if pid == self.host_id and self.players:
            self.host_id = next(iter(self.players))

    def detach_player(self, pid):
        self.player_ws.pop(pid, None)

    def attach_player(self, pid, ws):
        if pid in self.players:
            self.player_ws[pid] = ws
            return True
        return False

    def all_participant_ids(self):
        return list(self.players.keys()) + [b["id"] for b in self.bots]

    def participant_name(self, pid):
        if pid in self.players:
            return self.players[pid]
        for b in self.bots:
            if b["id"] == pid:
                return b["name"]
        return "?"

    def is_bot(self, pid):
        return pid.startswith("bot-")

    def player_list(self):
        out = []
        for pid, name in self.players.items():
            out.append({"id": pid, "name": name, "isHost": pid == self.host_id, "isBot": False})
        for b in self.bots:
            out.append({"id": b["id"], "name": b["name"], "isHost": False, "isBot": True})
        return out

    async def broadcast(self, msg):
        dead = []
        for pid, ws in list(self.player_ws.items()):
            try:
                await ws.send_json(msg)
            except Exception:
                dead.append(pid)
        for pid in dead:
            self.detach_player(pid)

    def _is_eliminated(self, pid):
        gid = self.pid_to_gameid.get(pid)
        if gid is None or self.game is None:
            return False
        for p in self.game.players:
            if p.id == gid:
                return p.is_eliminated
        return False

    def active_ids(self):
        ids = []
        for pid in self.players:
            if not self._is_eliminated(pid):
                ids.append(pid)
        for b in self.bots:
            if not self._is_eliminated(b["id"]):
                ids.append(b["id"])
        return ids

    def active_human_ids(self):
        return [pid for pid in self.players if not self._is_eliminated(pid)]

    def cancel_auto_next(self):
        task = self.auto_next_task
        self.auto_next_task = None
        if task and not task.done():
            task.cancel()

    def reset_ready_state(self):
        self.cancel_auto_next()
        self.ready_ids = set()

    def ready_payload(self):
        required = self.active_human_ids()
        ready = [pid for pid in self.ready_ids if pid in required]
        self.ready_ids = set(ready)
        return {
            "type": "ready_update",
            "ready": ready,
            "readyCount": len(ready),
            "requiredCount": len(required),
            "countdown": self.READY_COUNTDOWN,
        }

    def mark_ready(self, pid):
        if self.state != "result":
            return False
        if pid not in self.players or self._is_eliminated(pid):
            return False
        self.ready_ids.add(pid)
        return True

    def all_humans_ready(self):
        required = self.active_human_ids()
        return len(required) > 0 and all(pid in self.ready_ids for pid in required)

    def start_game(self):
        if self.single_player and not self.bots:
            self.setup_bots()
        names = [self.players[pid] for pid in self.players] + [b["name"] for b in self.bots]
        pids = list(self.players.keys()) + [b["id"] for b in self.bots]
        self.game = BeautyContestLogic(len(names), single_player_mode=False, player_names=names)
        self.pid_to_gameid = {pid: i + 1 for i, pid in enumerate(pids)}
        self.state = "playing"
        self.submissions = {}
        self.current_results = None
        self.round_number = 1
        self.history = []
        self.reset_ready_state()
        # auto-submit bots
        self._submit_bots()

    def _submit_bots(self):
        for bid in [b["id"] for b in self.bots]:
            if self._is_eliminated(bid):
                continue
            if bid in self.submissions:
                continue
            self.submissions[bid] = float(random.randint(0, 100))

    def all_submitted(self):
        active = self.active_ids()
        return len(active) > 0 and all(pid in self.submissions for pid in active)

    def submit(self, pid, value):
        if pid not in self.players and not self.is_bot(pid):
            return False
        if self._is_eliminated(pid):
            return False
        self.submissions[pid] = float(value)

    def game_players_payload(self):
        if self.game is None:
            return self.player_list()
        gid_to_pid = {v: k for k, v in self.pid_to_gameid.items()}
        players_payload = []
        for gp in self.game.players:
            pid = gid_to_pid.get(gp.id)
            players_payload.append({
                "id": pid,
                "name": gp.name,
                "choice": gp.last_choice,
                "score": gp.score,
                "isEliminated": gp.is_eliminated,
                "isBot": self.is_bot(pid) if pid else False,
            })
        return players_payload

    def state_snapshot(self, pid):
        snapshot = {
            "type": "state_sync",
            "playerId": pid,
            "code": self.code,
            "isHost": pid == self.host_id,
            "hostId": self.host_id,
            "state": self.state,
            "round": self.round_number,
            "history": self.history,
            "submitted": list(self.submissions.keys()),
        }
        if self.state == "lobby" or self.game is None:
            snapshot["players"] = self.player_list()
        else:
            snapshot["players"] = self.game_players_payload()
            if self.state == "result" and self.current_results:
                res = self.current_results
                gid_to_pid = {v: k for k, v in self.pid_to_gameid.items()}
                winners_ids = [gid_to_pid.get(p.id) for p in (res.get("winners") or [])]
                losers_ids = [gid_to_pid.get(p.id) for p in (res.get("losers") or [])]
                mw = self.game.get_match_winner()
                match_winner_pid = gid_to_pid.get(mw.id) if mw is not None and mw != "No One" else None
                game_over = match_winner_pid is not None or mw == "No One"
                result_snapshot = {
                    "type": "round_result",
                    "round": self.round_number,
                    "avg": res.get("avg"),
                    "target": res.get("target"),
                    "logs": res.get("logs"),
                    "activeCount": res.get("active_count"),
                    "players": snapshot["players"],
                    "winnerIds": winners_ids,
                    "loserIds": losers_ids,
                    "matchWinnerId": match_winner_pid,
                    "gameOver": game_over,
                    "history": self.history,
                }
                if not game_over:
                    required = self.active_human_ids()
                    ready = [r for r in self.ready_ids if r in required]
                    self.ready_ids = set(ready)
                    result_snapshot["ready"] = ready
                    result_snapshot["readyCount"] = len(ready)
                    result_snapshot["requiredCount"] = len(required)
                    result_snapshot["countdown"] = self.READY_COUNTDOWN
                snapshot["result"] = result_snapshot
        return snapshot

    def calculate(self):
        game = self.game
        for pid, val in self.submissions.items():
            gid = self.pid_to_gameid.get(pid)
            for p in game.players:
                if p.id == gid:
                    p.last_choice = val
        res = game.calculate_round_results()
        self.current_results = res
        self.state = "result"
        self.ready_ids = set()
        gid_to_pid = {v: k for k, v in self.pid_to_gameid.items()}
        players_payload = self.game_players_payload()
        winners_ids = [gid_to_pid.get(p.id) for p in (res["winners"] or [])]
        losers_ids = [gid_to_pid.get(p.id) for p in (res["losers"] or [])]
        history_entry = {
            "round": self.round_number,
            "avg": res["avg"],
            "target": res["target"],
            "logs": res["logs"],
            "picks": [
                {"id": gp["id"], "name": gp["name"], "choice": gp["choice"], "isEliminated": gp["isEliminated"]}
                for gp in players_payload
                if gp["choice"] is not None
            ],
            "winnerIds": winners_ids,
            "loserIds": losers_ids,
        }
        self.history.append(history_entry)
        match_winner_pid = None
        mw = game.get_match_winner()
        if mw is not None and mw != "No One":
            match_winner_pid = gid_to_pid.get(mw.id)
        game_over = match_winner_pid is not None or mw == "No One"
        payload = {
            "type": "round_result",
            "round": self.round_number,
            "avg": res["avg"],
            "target": res["target"],
            "logs": res["logs"],
            "activeCount": res["active_count"],
            "players": players_payload,
            "winnerIds": winners_ids,
            "loserIds": losers_ids,
            "matchWinnerId": match_winner_pid,
            "gameOver": game_over,
            "history": self.history,
        }
        if not game_over:
            payload["ready"] = []
            payload["readyCount"] = 0
            payload["requiredCount"] = len(self.active_human_ids())
            payload["countdown"] = self.READY_COUNTDOWN
        return payload

    def next_round(self):
        if self.game is None:
            return
        self.reset_ready_state()
        self.game.reset_round()
        self.state = "playing"
        self.submissions = {}
        self.current_results = None
        self.round_number += 1
        self._submit_bots()


class RoomManager:
    def __init__(self):
        self.rooms = {}

    def _gen_code(self):
        return "".join(random.choices(string.ascii_uppercase + string.digits, k=5))

    def create_room(self, host_id, single_player=False, bot_count=0):
        code = self._gen_code()
        while code in self.rooms:
            code = self._gen_code()
        self.rooms[code] = Room(code, host_id, single_player=single_player, bot_count=bot_count)
        return code

    def get_room(self, code):
        return self.rooms.get(code.upper() if code else code)

    def remove_empty(self, code):
        r = self.rooms.get(code)
        if r and not r.players and not r.single_player:
            del self.rooms[code]


manager = RoomManager()
