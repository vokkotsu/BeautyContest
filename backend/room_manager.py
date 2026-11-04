import asyncio
import random
import string
import uuid
from game_logic import BeautyContestLogic
import config


class Player:
    def __init__(self, pid, name, ws):
        self.id = pid
        self.name = name
        self.ws = ws


class Room:
    def __init__(self, code, host_id):
        self.code = code
        self.host_id = host_id
        self.players = {}  # pid -> Player (insertion order)
        self.game = None
        self.state = "lobby"  # lobby | playing | result
        self.submissions = {}  # pid -> number
        self.current_results = None
        self.pid_to_gameid = {}
        self.round_number = 0
        self.lock = asyncio.Lock()

    def add_player(self, pid, name, ws):
        if len(self.players) >= 10:
            return False
        self.players[pid] = Player(pid, name, ws)
        return True

    def remove_player(self, pid):
        self.players.pop(pid, None)
        if pid == self.host_id and self.players:
            self.host_id = next(iter(self.players))

    def player_list(self):
        return [
            {"id": p.id, "name": p.name, "isHost": p.id == self.host_id}
            for p in self.players.values()
        ]

    async def broadcast(self, msg):
        dead = []
        for pid, p in list(self.players.items()):
            try:
                await p.ws.send_json(msg)
            except Exception:
                dead.append(pid)
        for pid in dead:
            self.remove_player(pid)

    def start_game(self):
        names = [p.name for p in self.players.values()]
        pids = list(self.players.keys())
        self.game = BeautyContestLogic(len(names), single_player_mode=False, player_names=names)
        self.pid_to_gameid = {pid: i + 1 for i, pid in enumerate(pids)}
        self.state = "playing"
        self.submissions = {}
        self.current_results = None
        self.round_number = 1

    def all_submitted(self):
        active = [pid for pid in self.players if not self._is_eliminated(pid)]
        return len(active) > 0 and all(pid in self.submissions for pid in active)

    def _is_eliminated(self, pid):
        gid = self.pid_to_gameid.get(pid)
        if gid is None or self.game is None:
            return False
        for p in self.game.players:
            if p.id == gid:
                return p.is_eliminated
        return False

    def submit(self, pid, value):
        if pid not in self.players:
            return False
        if self._is_eliminated(pid):
            return False
        self.submissions[pid] = float(value)

    def calculate(self):
        game = self.game
        # set last_choice for all active players from submissions
        for pid, val in self.submissions.items():
            gid = self.pid_to_gameid.get(pid)
            for p in game.players:
                if p.id == gid:
                    p.last_choice = val
        # ensure any missing active get a default? They shouldn't be missing.
        res = game.calculate_round_results()
        self.current_results = res
        self.state = "result"
        # build payload
        gid_to_pid = {v: k for k, v in self.pid_to_gameid.items()}
        players_payload = []
        for gp in game.players:
            pid = gid_to_pid.get(gp.id)
            players_payload.append({
                "id": pid,
                "name": gp.name,
                "choice": gp.last_choice,
                "score": gp.score,
                "isEliminated": gp.is_eliminated,
            })
        winners_ids = [gid_to_pid.get(p.id) for p in (res["winners"] or [])]
        losers_ids = [gid_to_pid.get(p.id) for p in (res["losers"] or [])]
        match_winner_pid = None
        mw = game.get_match_winner()
        if mw is not None and mw != "No One":
            match_winner_pid = gid_to_pid.get(mw.id)
        elif mw == "No One":
            match_winner_pid = None
        payload = {
            "type": "round_result",
            "avg": res["avg"],
            "target": res["target"],
            "logs": res["logs"],
            "activeCount": res["active_count"],
            "players": players_payload,
            "winnerIds": winners_ids,
            "loserIds": losers_ids,
            "matchWinnerId": match_winner_pid,
            "gameOver": match_winner_pid is not None or mw == "No One",
        }
        return payload

    def next_round(self):
        if self.game is None:
            return
        self.game.reset_round()
        self.state = "playing"
        self.submissions = {}
        self.current_results = None
        self.round_number += 1


class RoomManager:
    def __init__(self):
        self.rooms = {}

    def _gen_code(self):
        return "".join(random.choices(string.ascii_uppercase + string.digits, k=5))

    def create_room(self, host_id):
        code = self._gen_code()
        while code in self.rooms:
            code = self._gen_code()
        self.rooms[code] = Room(code, host_id)
        return code

    def get_room(self, code):
        return self.rooms.get(code.upper() if code else code)

    def remove_empty(self, code):
        r = self.rooms.get(code)
        if r and not r.players:
            del self.rooms[code]


manager = RoomManager()
