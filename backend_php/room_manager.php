<?php

require_once __DIR__ . '/game_logic.php';

class Room
{
    public const READY_COUNTDOWN = 15;
    public const PICK_COUNTDOWN = 60;

    public string $code;
    public string $host_id;
    public bool $single_player;
    public int $bot_count;
    /** @var array<string,string> pid -> name */
    public array $players = [];
    /** @var array<string, mixed> pid -> connection */
    public array $player_ws = [];
    /** @var array<int, array{id:string,name:string}> */
    public array $bots = [];
    public ?BeautyContestLogic $game = null;
    public string $state = "lobby"; // lobby | playing | result
    /** @var array<string,float> */
    public array $submissions = [];
    public $current_results = null;
    /** @var array<string,int> */
    public array $pid_to_gameid = [];
    public int $round_number = 0;
    public array $history = [];
    /** @var array<string,bool> */
    public array $ready_ids_set = [];
    public $auto_next_timer = null;
    public $pick_timer = null;
    public ?float $pick_deadline = null;

    public function __construct(string $code, string $host_id, bool $single_player = false, int $bot_count = 0)
    {
        $this->code = $code;
        $this->host_id = $host_id;
        $this->single_player = $single_player;
        $this->bot_count = $bot_count;
    }

    public function add_player(string $pid, string $name, $ws): bool
    {
        if (count($this->players) + count($this->bots) >= 10) {
            return false;
        }
        $this->players[$pid] = $name;
        $this->player_ws[$pid] = $ws;
        return true;
    }

    public function setup_bots(): void
    {
        $this->bots = [];
        for ($i = 0; $i < $this->bot_count; $i++) {
            $this->bots[] = ["id" => "bot-" . ($i + 1), "name" => "Bot " . ($i + 1)];
        }
    }

    public function remove_player(string $pid): void
    {
        unset($this->players[$pid]);
        unset($this->player_ws[$pid]);
        unset($this->submissions[$pid]);
        unset($this->ready_ids_set[$pid]);
        if ($pid === $this->host_id && count($this->players) > 0) {
            $keys = array_keys($this->players);
            $this->host_id = $keys[0];
        }
    }

    public function detach_player(string $pid): void
    {
        unset($this->player_ws[$pid]);
    }

    public function attach_player(string $pid, $ws): bool
    {
        if (array_key_exists($pid, $this->players)) {
            $this->player_ws[$pid] = $ws;
            return true;
        }
        return false;
    }

    public function all_participant_ids(): array
    {
        $ids = array_keys($this->players);
        foreach ($this->bots as $b) {
            $ids[] = $b["id"];
        }
        return $ids;
    }

    public function participant_name(string $pid): string
    {
        if (isset($this->players[$pid])) {
            return $this->players[$pid];
        }
        foreach ($this->bots as $b) {
            if ($b["id"] === $pid) {
                return $b["name"];
            }
        }
        return "?";
    }

    public function is_bot(string $pid): bool
    {
        return strpos($pid, "bot-") === 0;
    }

    public function player_list(): array
    {
        $out = [];
        foreach ($this->players as $pid => $name) {
            $out[] = ["id" => $pid, "name" => $name, "isHost" => $pid === $this->host_id, "isBot" => false];
        }
        foreach ($this->bots as $b) {
            $out[] = ["id" => $b["id"], "name" => $b["name"], "isHost" => false, "isBot" => true];
        }
        return $out;
    }

    public function broadcast(array $msg): void
    {
        $payload = json_encode($msg);
        $dead = [];
        foreach ($this->player_ws as $pid => $ws) {
            try {
                $ws->send($payload);
            } catch (\Throwable $e) {
                $dead[] = $pid;
            }
        }
        foreach ($dead as $pid) {
            $this->detach_player($pid);
        }
    }

    public function _is_eliminated(string $pid): bool
    {
        $gid = $this->pid_to_gameid[$pid] ?? null;
        if ($gid === null || $this->game === null) {
            return false;
        }
        foreach ($this->game->players as $p) {
            if ($p->id === $gid) {
                return $p->is_eliminated;
            }
        }
        return false;
    }

    public function active_ids(): array
    {
        $ids = [];
        foreach ($this->players as $pid => $_) {
            if (!$this->_is_eliminated($pid)) {
                $ids[] = $pid;
            }
        }
        foreach ($this->bots as $b) {
            if (!$this->_is_eliminated($b["id"])) {
                $ids[] = $b["id"];
            }
        }
        return $ids;
    }

    public function active_human_ids(): array
    {
        $ids = [];
        foreach ($this->players as $pid => $_) {
            if (!$this->_is_eliminated($pid)) {
                $ids[] = $pid;
            }
        }
        return $ids;
    }

    public function cancel_auto_next($loop): void
    {
        if ($this->auto_next_timer !== null) {
            $loop->cancelTimer($this->auto_next_timer);
            $this->auto_next_timer = null;
        }
    }

    public function cancel_pick_timer($loop): void
    {
        if ($this->pick_timer !== null) {
            $loop->cancelTimer($this->pick_timer);
            $this->pick_timer = null;
        }
        $this->pick_deadline = null;
    }

    public function reset_ready_state($loop): void
    {
        $this->cancel_auto_next($loop);
        $this->ready_ids_set = [];
    }

    public function pick_seconds_left(): ?int
    {
        if ($this->pick_deadline === null) {
            return null;
        }
        $left = (int)round($this->pick_deadline - microtime(true));
        return max(0, $left);
    }

    public function start_pick_timer(): void
    {
        $this->pick_deadline = microtime(true) + self::PICK_COUNTDOWN;
    }

    public function previous_choice_for(string $pid): ?float
    {
        $gid = $this->pid_to_gameid[$pid] ?? null;
        if ($gid === null || $this->game === null) {
            return null;
        }
        foreach ($this->game->players as $p) {
            if ($p->id === $gid) {
                $val = $p->last_choice;
                if ($val === null) {
                    return null;
                }
                if (is_numeric($val)) {
                    return (float)$val;
                }
                return null;
            }
        }
        return null;
    }

    public function auto_pick_missing(): bool
    {
        $changed = false;
        foreach ($this->active_human_ids() as $pid) {
            if (array_key_exists($pid, $this->submissions)) {
                continue;
            }
            $prev = $this->previous_choice_for($pid);
            if ($prev === null) {
                $prev = (float)random_int(0, 100);
            }
            $this->submissions[$pid] = max(0.0, min(100.0, $prev));
            $changed = true;
        }
        return $changed;
    }

    public function ready_payload(): array
    {
        $required = $this->active_human_ids();
        $ready = [];
        foreach ($this->ready_ids_set as $pid => $_) {
            if (in_array($pid, $required, true)) {
                $ready[] = $pid;
            }
        }
        $this->ready_ids_set = [];
        foreach ($ready as $pid) {
            $this->ready_ids_set[$pid] = true;
        }
        return [
            "type" => "ready_update",
            "ready" => array_values($ready),
            "readyCount" => count($ready),
            "requiredCount" => count($required),
            "countdown" => self::READY_COUNTDOWN,
        ];
    }

    public function mark_ready(string $pid): bool
    {
        if ($this->state !== "result") {
            return false;
        }
        if (!isset($this->players[$pid]) || $this->_is_eliminated($pid)) {
            return false;
        }
        $this->ready_ids_set[$pid] = true;
        return true;
    }

    public function all_humans_ready(): bool
    {
        $required = $this->active_human_ids();
        if (count($required) === 0) {
            return false;
        }
        foreach ($required as $pid) {
            if (!isset($this->ready_ids_set[$pid])) {
                return false;
            }
        }
        return true;
    }

    public function start_game($loop): void
    {
        if ($this->single_player && count($this->bots) === 0) {
            $this->setup_bots();
        }
        $names = [];
        $pids = [];
        foreach ($this->players as $pid => $name) {
            $pids[] = $pid;
            $names[] = $name;
        }
        foreach ($this->bots as $b) {
            $pids[] = $b["id"];
            $names[] = $b["name"];
        }
        $this->game = new BeautyContestLogic(count($names), false, $names);
        $this->pid_to_gameid = [];
        foreach ($pids as $i => $pid) {
            $this->pid_to_gameid[$pid] = $i + 1;
        }
        $this->state = "playing";
        $this->submissions = [];
        $this->current_results = null;
        $this->round_number = 1;
        $this->history = [];
        $this->reset_ready_state($loop);
        $this->cancel_pick_timer($loop);
        $this->start_pick_timer();
        $this->_submit_bots();
    }

    public function _submit_bots(): void
    {
        foreach ($this->bots as $b) {
            $bid = $b["id"];
            if ($this->_is_eliminated($bid)) {
                continue;
            }
            if (array_key_exists($bid, $this->submissions)) {
                continue;
            }
            $this->submissions[$bid] = (float)random_int(0, 100);
        }
    }

    public function all_submitted(): bool
    {
        $active = $this->active_ids();
        if (count($active) === 0) {
            return false;
        }
        foreach ($active as $pid) {
            if (!array_key_exists($pid, $this->submissions)) {
                return false;
            }
        }
        return true;
    }

    public function submit(string $pid, float $value): bool
    {
        if (!isset($this->players[$pid]) && !$this->is_bot($pid)) {
            return false;
        }
        if ($this->_is_eliminated($pid)) {
            return false;
        }
        $this->submissions[$pid] = (float)$value;
        return true;
    }

    public function game_players_payload(): array
    {
        if ($this->game === null) {
            return $this->player_list();
        }
        $gid_to_pid = [];
        foreach ($this->pid_to_gameid as $pid => $gid) {
            $gid_to_pid[$gid] = $pid;
        }
        $players_payload = [];
        foreach ($this->game->players as $gp) {
            $pid = $gid_to_pid[$gp->id] ?? null;
            $players_payload[] = [
                "id" => $pid,
                "name" => $gp->name,
                "choice" => $gp->last_choice,
                "score" => $gp->score,
                "isEliminated" => $gp->is_eliminated,
                "isBot" => $pid ? $this->is_bot($pid) : false,
            ];
        }
        return $players_payload;
    }

    public function state_snapshot(string $pid): array
    {
        $snapshot = [
            "type" => "state_sync",
            "playerId" => $pid,
            "code" => $this->code,
            "isHost" => $pid === $this->host_id,
            "hostId" => $this->host_id,
            "state" => $this->state,
            "round" => $this->round_number,
            "history" => $this->history,
            "submitted" => array_values(array_keys($this->submissions)),
        ];
        if ($this->state === "playing") {
            $left = $this->pick_seconds_left();
            $snapshot["pickCountdown"] = $left !== null ? $left : self::PICK_COUNTDOWN;
            $snapshot["pickDeadline"] = self::PICK_COUNTDOWN;
        }
        if ($this->state === "lobby" || $this->game === null) {
            $snapshot["players"] = $this->player_list();
        } else {
            $snapshot["players"] = $this->game_players_payload();
            if ($this->state === "result" && $this->current_results !== null) {
                $res = $this->current_results;
                $gid_to_pid = [];
                foreach ($this->pid_to_gameid as $p2 => $gid) {
                    $gid_to_pid[$gid] = $p2;
                }
                $winners_ids = [];
                foreach (($res["winners"] ?? []) as $p) {
                    $winners_ids[] = $gid_to_pid[$p->id] ?? null;
                }
                $losers_ids = [];
                foreach (($res["losers"] ?? []) as $p) {
                    $losers_ids[] = $gid_to_pid[$p->id] ?? null;
                }
                $mw = $this->game->get_match_winner();
                $match_winner_pid = null;
                $mw_is_no_one = ($mw === "No One");
                if ($mw !== null && !$mw_is_no_one) {
                    $match_winner_pid = $gid_to_pid[$mw->id] ?? null;
                }
                $game_over = ($match_winner_pid !== null) || $mw_is_no_one;
                $result_snapshot = [
                    "type" => "round_result",
                    "round" => $this->round_number,
                    "avg" => $res["avg"] ?? null,
                    "target" => $res["target"] ?? null,
                    "logs" => $res["logs"] ?? [],
                    "activeCount" => $res["active_count"] ?? null,
                    "players" => $snapshot["players"],
                    "winnerIds" => $winners_ids,
                    "loserIds" => $losers_ids,
                    "matchWinnerId" => $match_winner_pid,
                    "gameOver" => $game_over,
                    "history" => $this->history,
                ];
                if (!$game_over) {
                    $required = $this->active_human_ids();
                    $ready = [];
                    foreach ($this->ready_ids_set as $rpid => $_) {
                        if (in_array($rpid, $required, true)) {
                            $ready[] = $rpid;
                        }
                    }
                    $this->ready_ids_set = [];
                    foreach ($ready as $rpid) {
                        $this->ready_ids_set[$rpid] = true;
                    }
                    $result_snapshot["ready"] = array_values($ready);
                    $result_snapshot["readyCount"] = count($ready);
                    $result_snapshot["requiredCount"] = count($required);
                    $result_snapshot["countdown"] = self::READY_COUNTDOWN;
                }
                $snapshot["result"] = $result_snapshot;
            }
        }
        return $snapshot;
    }

    public function calculate($loop): array
    {
        $this->cancel_pick_timer($loop);
        $game = $this->game;
        foreach ($this->submissions as $pid => $val) {
            $gid = $this->pid_to_gameid[$pid] ?? null;
            if ($gid === null) continue;
            foreach ($game->players as $p) {
                if ($p->id === $gid) {
                    $p->last_choice = (float)$val;
                }
            }
        }
        $res = $game->calculate_round_results();
        $this->current_results = $res;
        $this->state = "result";
        $this->ready_ids_set = [];
        $gid_to_pid = [];
        foreach ($this->pid_to_gameid as $pid => $gid) {
            $gid_to_pid[$gid] = $pid;
        }
        $players_payload = $this->game_players_payload();
        $winners_ids = [];
        foreach (($res["winners"] ?? []) as $p) {
            $winners_ids[] = $gid_to_pid[$p->id] ?? null;
        }
        $losers_ids = [];
        foreach (($res["losers"] ?? []) as $p) {
            $losers_ids[] = $gid_to_pid[$p->id] ?? null;
        }
        $picks = [];
        foreach ($players_payload as $gp) {
            if ($gp["choice"] !== null) {
                $picks[] = [
                    "id" => $gp["id"],
                    "name" => $gp["name"],
                    "choice" => $gp["choice"],
                    "isEliminated" => $gp["isEliminated"],
                ];
            }
        }
        $history_entry = [
            "round" => $this->round_number,
            "avg" => $res["avg"],
            "target" => $res["target"],
            "logs" => $res["logs"],
            "picks" => $picks,
            "winnerIds" => $winners_ids,
            "loserIds" => $losers_ids,
        ];
        $this->history[] = $history_entry;
        $match_winner_pid = null;
        $mw = $game->get_match_winner();
        $mw_is_no_one = ($mw === "No One");
        if ($mw !== null && !$mw_is_no_one) {
            $match_winner_pid = $gid_to_pid[$mw->id] ?? null;
        }
        $game_over = ($match_winner_pid !== null) || $mw_is_no_one;
        $payload = [
            "type" => "round_result",
            "round" => $this->round_number,
            "avg" => $res["avg"],
            "target" => $res["target"],
            "logs" => $res["logs"],
            "activeCount" => $res["active_count"],
            "players" => $players_payload,
            "winnerIds" => $winners_ids,
            "loserIds" => $losers_ids,
            "matchWinnerId" => $match_winner_pid,
            "gameOver" => $game_over,
            "history" => $this->history,
        ];
        if (!$game_over) {
            $payload["ready"] = [];
            $payload["readyCount"] = 0;
            $payload["requiredCount"] = count($this->active_human_ids());
            $payload["countdown"] = self::READY_COUNTDOWN;
        }
        return $payload;
    }

    public function next_round($loop): void
    {
        if ($this->game === null) {
            return;
        }
        $this->reset_ready_state($loop);
        $this->cancel_pick_timer($loop);
        $this->game->reset_round();
        $this->state = "playing";
        $this->submissions = [];
        $this->current_results = null;
        $this->round_number += 1;
        $this->start_pick_timer();
        $this->_submit_bots();
    }
}

class RoomManager
{
    /** @var array<string,Room> */
    public array $rooms = [];

    private function gen_code(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $code;
    }

    public function create_room(string $host_id, bool $single_player = false, int $bot_count = 0): string
    {
        $code = $this->gen_code();
        while (isset($this->rooms[$code])) {
            $code = $this->gen_code();
        }
        $this->rooms[$code] = new Room($code, $host_id, $single_player, $bot_count);
        return $code;
    }

    public function get_room(?string $code): ?Room
    {
        if ($code === null) return null;
        $key = strtoupper($code);
        return $this->rooms[$key] ?? null;
    }

    public function remove_empty(string $code): void
    {
        $r = $this->rooms[$code] ?? null;
        if ($r && count($r->players) === 0 && !$r->single_player) {
            if (isset($this->rooms[$code])) {
                unset($this->rooms[$code]);
            }
        }
    }
}

$manager = new RoomManager();
