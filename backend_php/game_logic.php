<?php

require_once __DIR__ . '/config.php';

class Player
{
    public int $id;
    public string $name;
    public int $score;
    public bool $is_eliminated;
    public $last_choice; // float|null
    public bool $is_bot;

    public function __init_or_ctor() {}

    public function __construct(int $p_id, string $name, bool $is_bot = false)
    {
        $this->id = $p_id;
        $this->name = $name;
        $this->score = STARTING_SCORE;
        $this->is_eliminated = false;
        $this->last_choice = null;
        $this->is_bot = $is_bot;
    }
}

class BeautyContestLogic
{
    public int $num_players;
    /** @var Player[] */
    public array $players;
    public int $current_player_idx;

    public function __construct(int $num_players, bool $single_player_mode = false, ?array $player_names = null)
    {
        $this->num_players = $num_players;
        $this->players = [];

        for ($i = 0; $i < $num_players; $i++) {
            $p_id = $i + 1;
            $is_bot = false;
            $name = "Player {$p_id}";

            if ($single_player_mode) {
                if ($i === 0) {
                    if ($player_names !== null && count($player_names) > 0) {
                        $name = $player_names[0];
                    }
                } else {
                    $is_bot = true;
                    $name = "Bot {$p_id}";
                }
            } else {
                if ($player_names !== null && $i < count($player_names)) {
                    $name = $player_names[$i];
                }
            }

            $this->players[] = new Player($p_id, $name, $is_bot);
        }

        $this->current_player_idx = 0;
        $this->find_next_active_player();
    }

    public function find_next_active_player(): bool
    {
        if ($this->current_player_idx >= $this->num_players) {
            return false;
        }
        while ($this->current_player_idx < $this->num_players && $this->players[$this->current_player_idx]->is_eliminated) {
            $this->current_player_idx++;
        }
        if ($this->current_player_idx >= $this->num_players) {
            return false;
        }
        return true;
    }

    public function set_choice($value): bool
    {
        $current_p = $this->players[$this->current_player_idx];
        $current_p->last_choice = (float)$value;
        $this->current_player_idx++;
        return $this->find_next_active_player();
    }

    public function get_current_player(): ?Player
    {
        if ($this->current_player_idx < $this->num_players) {
            return $this->players[$this->current_player_idx];
        }
        return null;
    }

    public function get_current_player_id(): int
    {
        $p = $this->get_current_player();
        return $p ? $p->id : -1;
    }

    public function get_active_player_count(): int
    {
        $count = 0;
        foreach ($this->players as $p) {
            if (!$p->is_eliminated) {
                $count++;
            }
        }
        return $count;
    }

    public function calculate_round_results(): ?array
    {
        $active_players = [];
        foreach ($this->players as $p) {
            if (!$p->is_eliminated) {
                $active_players[] = $p;
            }
        }
        $count = count($active_players);

        if ($count === 0) {
            return null;
        }

        $choices = [];
        foreach ($active_players as $p) {
            $choices[] = $p->last_choice;
        }

        $raw_avg = array_sum($choices) / $count;
        // Match Python 3's round() (round-half-to-even / banker's rounding)
        $avg = round($raw_avg, 2, PHP_ROUND_HALF_EVEN);
        $target = round($avg * 0.8, 2, PHP_ROUND_HALF_EVEN);

        $winners = [];
        $losers = [];
        $invalid_players = [];
        $special_event_log = [];

        $current_penalty = LOSS_PENALTY;

        // --- RULE: 2 PLAYERS (0 vs 100) ---
        $rule_2p_triggered = false;
        if ($count === 2) {
            $vals = [];
            foreach ($active_players as $p) {
                $vals[] = $p->last_choice;
            }
            $has0 = false;
            $has100 = false;
            foreach ($vals as $v) {
                if ((float)$v == 0.0) $has0 = true;
                if ((float)$v == 100.0) $has100 = true;
            }
            if ($has0 && $has100) {
                $rule_2p_triggered = true;
                $special_event_log[] = "DUEL: 0 vs 100! 100 Menang!";
                foreach ($active_players as $p) {
                    if ((float)$p->last_choice == 100.0) {
                        $winners[] = $p;
                    } else {
                        $losers[] = $p;
                    }
                }
            }
        }

        if (!$rule_2p_triggered) {
            // --- RULE: 4 PLAYERS OR LESS (Duplicate Penalty) ---
            if ($count <= 4) {
                $seen = [];
                $duplicates = [];
                foreach ($active_players as $p) {
                    $val = $p->last_choice;
                    $key = (string)$val;
                    if (array_key_exists($key, $seen)) {
                        $duplicates[$key] = $val;
                    }
                    $seen[$key] = true;
                }

                if (count($duplicates) > 0) {
                    $dup_vals = [];
                    foreach ($duplicates as $dval) {
                        $dup_vals[] = (string)(int)$dval;
                    }
                    $dup_str = implode(', ', $dup_vals);
                    $special_event_log[] = "DUPLIKASI: Angka {$dup_str} hangus!";
                    foreach ($active_players as $p) {
                        $key = (string)$p->last_choice;
                        if (array_key_exists($key, $duplicates)) {
                            $invalid_players[] = $p;
                        }
                    }
                }
            }

            $valid_candidates = [];
            foreach ($active_players as $p) {
                $is_invalid = false;
                foreach ($invalid_players as $ip) {
                    if ($ip === $p) { $is_invalid = true; break; }
                }
                if (!$is_invalid) {
                    $valid_candidates[] = $p;
                }
            }

            if (count($valid_candidates) === 0) {
                $special_event_log[] = "SEMUA INVALID! Tidak ada pemenang.";
                $losers = $active_players;
            } else {
                $min_diff = INF;
                foreach ($valid_candidates as $p) {
                    $diff = abs($target - (float)$p->last_choice);
                    if ($diff < $min_diff) {
                        $min_diff = $diff;
                    }
                }

                $round_winners = [];
                foreach ($valid_candidates as $p) {
                    $diff = abs($target - (float)$p->last_choice);
                    if (abs($diff - $min_diff) < 0.000001) {
                        $round_winners[] = $p;
                    }
                }
                foreach ($round_winners as $rw) {
                    $winners[] = $rw;
                }

                foreach ($active_players as $p) {
                    $in_winners = false;
                    foreach ($winners as $w) {
                        if ($w === $p) { $in_winners = true; break; }
                    }
                    if (!$in_winners) {
                        $losers[] = $p;
                    }
                }

                // --- RULE: 3 PLAYERS OR LESS (Exact Match Bonus) ---
                if ($count <= 3) {
                    $rounded_target = (int)round($target);
                    $is_exact = false;
                    foreach ($winners as $w) {
                        if ((float)$w->last_choice == (float)$rounded_target) {
                            $is_exact = true;
                            break;
                        }
                    }

                    if ($is_exact) {
                        $current_penalty = CRITICAL_PENALTY;
                        $special_event_log[] = "CRITICAL HIT! Tepat sasaran {$rounded_target}!";
                        $special_event_log[] = "Penalti yang kalah menjadi -2!";
                    }
                }
            }
        }

        foreach ($losers as $p) {
            $p->score -= $current_penalty;
            if ($p->score <= ELIMINATION_THRESHOLD) {
                $p->is_eliminated = true;
            }
        }

        return [
            "avg" => $avg,
            "target" => $target,
            "winners" => $winners,
            "losers" => $losers,
            "all_players" => $this->players,
            "logs" => $special_event_log,
            "active_count" => $count,
        ];
    }

    public function get_match_winner()
    {
        $active_players = [];
        foreach ($this->players as $p) {
            if (!$p->is_eliminated) {
                $active_players[] = $p;
            }
        }
        if (count($active_players) === 1) {
            return $active_players[0];
        }
        if (count($active_players) === 0) {
            return "No One";
        }
        return null;
    }

    public function reset_round(): void
    {
        $this->current_player_idx = 0;
        $this->find_next_active_player();
    }
}
