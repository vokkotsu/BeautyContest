<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/game_logic.php';

function api_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['type' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_out($data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function now_micro(): float
{
    return microtime(true);
}

function dt_from_micro(float $t): string
{
    $s = floor($t);
    $ms = (int)round(($t - $s) * 1000);
    if ($ms >= 1000) { $s += 1; $ms -= 1000; }
    return gmdate('Y-m-d H:i:s', (int)$s) . '.' . str_pad((string)$ms, 3, '0', STR_PAD_LEFT);
}

function micro_from_dt($dt): ?float
{
    if ($dt === null) return null;
    $ts = strtotime($dt . ' UTC');
    if ($ts === false) return null;
    $frac = 0.0;
    if (preg_match('/\.(\d+)/', (string)$dt, $m)) {
        $frac = ((int)str_pad(substr($m[1], 0, 3), 3, '0')) / 1000.0;
    }
    return (float)$ts + $frac;
}

function gen_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function gen_room_code(PDO $pdo): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    for ($tries = 0; $tries < 50; $tries++) {
        $code = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $stmt = $pdo->prepare('SELECT 1 FROM rooms WHERE code = ?');
        $stmt->execute([$code]);
        if (!$stmt->fetchColumn()) {
            return $code;
        }
    }
    throw new RuntimeException('Could not generate room code');
}

function fetch_room_locked(PDO $pdo, string $code): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE code = ? FOR UPDATE');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fetch_participants(PDO $pdo, string $code): array
{
    $stmt = $pdo->prepare('SELECT * FROM room_participants WHERE room_code = ? ORDER BY joined_at ASC, participant_id ASC');
    $stmt->execute([$code]);
    return $stmt->fetchAll();
}

function fetch_participants_in_game_order(PDO $pdo, string $code): array
{
    $stmt = $pdo->prepare('SELECT * FROM room_participants WHERE room_code = ? AND game_player_id IS NOT NULL ORDER BY game_player_id ASC');
    $stmt->execute([$code]);
    return $stmt->fetchAll();
}

function participant_name_map(array $parts): array
{
    $m = [];
    foreach ($parts as $p) { $m[$p['participant_id']] = $p['name']; }
    return $m;
}

function active_participants(array $parts): array
{
    $out = [];
    foreach ($parts as $p) {
        if ((int)$p['is_eliminated'] === 0) $out[] = $p;
    }
    return $out;
}

function active_human_participants(array $parts): array
{
    $out = [];
    foreach ($parts as $p) {
        if ((int)$p['is_eliminated'] === 0 && (int)$p['is_bot'] === 0) $out[] = $p;
    }
    return $out;
}

function get_submissions_map(PDO $pdo, string $code, int $round): array
{
    $stmt = $pdo->prepare('SELECT participant_id, value FROM submissions WHERE room_code = ? AND round_number = ?');
    $stmt->execute([$code, $round]);
    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $map[$r['participant_id']] = (float)$r['value'];
    }
    return $map;
}

function get_ready_ids(PDO $pdo, string $code, int $round): array
{
    $stmt = $pdo->prepare('SELECT participant_id FROM round_readies WHERE room_code = ? AND round_number = ?');
    $stmt->execute([$code, $round]);
    $ids = [];
    foreach ($stmt->fetchAll() as $r) { $ids[] = $r['participant_id']; }
    return $ids;
}

function player_list_payload(array $parts, string $hostId): array
{
    $out = [];
    foreach ($parts as $p) {
        $out[] = [
            'id' => $p['participant_id'],
            'name' => $p['name'],
            'isHost' => $p['participant_id'] === $hostId,
            'isBot' => (int)$p['is_bot'] === 1,
        ];
    }
    return $out;
}

function game_players_payload(array $parts): array
{
    $out = [];
    foreach ($parts as $p) {
        if ($p['game_player_id'] === null) continue;
        $choice = $p['last_choice'];
        $out[] = [
            'id' => $p['participant_id'],
            'name' => $p['name'],
            'choice' => $choice === null ? null : (float)$choice,
            'score' => (int)$p['score'],
            'isEliminated' => (int)$p['is_eliminated'] === 1,
            'isBot' => (int)$p['is_bot'] === 1,
        ];
    }
    return $out;
}

function pick_seconds_left(array $room): ?int
{
    $dl = micro_from_dt($room['pick_deadline_at']);
    if ($dl === null) return null;
    return max(0, (int)round($dl - now_micro()));
}

function ready_countdown_left(array $room): int
{
    $dl = micro_from_dt($room['ready_deadline_at']);
    if ($dl === null) return READY_COUNTDOWN;
    return max(0, (int)round($dl - now_micro()));
}

function auto_submit_bots(PDO $pdo, string $code, int $round, array $parts): void
{
    foreach ($parts as $p) {
        if ((int)$p['is_bot'] !== 1 || (int)$p['is_eliminated'] === 1) continue;
        $val = (float)random_int(0, 100);
        $stmt = $pdo->prepare('INSERT IGNORE INTO submissions (room_code, round_number, participant_id, value, submitted_at) VALUES (?,?,?,?,?)');
        $stmt->execute([$code, $round, $p['participant_id'], $val, dt_from_micro(now_micro())]);
    }
}

function auto_pick_missing_humans(PDO $pdo, string $code, int $round, array $parts, array $subs): bool
{
    $changed = false;
    foreach ($parts as $p) {
        if ((int)$p['is_bot'] === 1 || (int)$p['is_eliminated'] === 1) continue;
        if (array_key_exists($p['participant_id'], $subs)) continue;
        $prev = $p['last_choice'];
        $val = $prev !== null ? (float)$prev : (float)random_int(0, 100);
        $val = max(0.0, min(100.0, $val));
        $stmt = $pdo->prepare('INSERT INTO submissions (room_code, round_number, participant_id, value, submitted_at) VALUES (?,?,?,?,?)');
        $stmt->execute([$code, $round, $p['participant_id'], $val, dt_from_micro(now_micro())]);
        $changed = true;
    }
    return $changed;
}

function all_active_submitted(array $parts, array $subs): bool
{
    $active = active_participants($parts);
    if (count($active) === 0) return false;
    foreach ($active as $p) {
        if (!array_key_exists($p['participant_id'], $subs)) return false;
    }
    return true;
}

function build_logic_from_parts(array $partsOrdered): BeautyContestLogic
{
    $names = [];
    foreach ($partsOrdered as $p) { $names[] = $p['name']; }
    $logic = new BeautyContestLogic(count($names), false, $names);
    // align state by game_player_id order (1-based -> index)
    foreach ($partsOrdered as $p) {
        $idx = ((int)$p['game_player_id']) - 1;
        if (!isset($logic->players[$idx])) continue;
        $gp = $logic->players[$idx];
        $gp->score = (int)$p['score'];
        $gp->is_eliminated = (int)$p['is_eliminated'] === 1;
        $gp->last_choice = $p['last_choice'] === null ? null : (float)$p['last_choice'];
    }
    return $logic;
}

function compute_match_winner_id_from_parts(array $partsOrdered): ?string
{
    $active = [];
    foreach ($partsOrdered as $p) {
        if ((int)$p['is_eliminated'] === 0) $active[] = $p;
    }
    if (count($active) === 1) return $active[0]['participant_id'];
    // 0 or >1 => no match winner id (No One handled via history game_over flag)
    return null;
}

function recompute_game_over_flag(array $partsOrdered): array
{
    $active = [];
    foreach ($partsOrdered as $p) { if ((int)$p['is_eliminated'] === 0) $active[] = $p; }
    if (count($active) === 1) return [$active[0]['participant_id'], true];
    if (count($active) === 0) return [null, true];
    return [null, false];
}

function finalize_round(PDO $pdo, array &$room): void
{
    $code = $room['code'];
    $round = (int)$room['round_number'];
    $parts = fetch_participants_in_game_order($pdo, $code);
    $subs = get_submissions_map($pdo, $code, $round);

    $logic = build_logic_from_parts($parts);
    // apply submissions to logic players
    $pidToGid = [];
    foreach ($parts as $p) { $pidToGid[$p['participant_id']] = (int)$p['game_player_id']; }
    foreach ($subs as $pid => $val) {
        $gid = $pidToGid[$pid] ?? null;
        if ($gid === null) continue;
        $idx = $gid - 1;
        if (isset($logic->players[$idx])) {
            $logic->players[$idx]->last_choice = (float)$val;
        }
    }

    $res = $logic->calculate_round_results();
    if ($res === null) {
        return;
    }

    // persist participant state
    $upd = $pdo->prepare('UPDATE room_participants SET score=?, is_eliminated=?, last_choice=? WHERE room_code=? AND participant_id=?');
    $gidToPid = [];
    foreach ($parts as $p) { $gidToPid[(int)$p['game_player_id']] = $p['participant_id']; }
    foreach ($logic->players as $gp) {
        $pid = $gidToPid[$gp->id] ?? null;
        if ($pid === null) continue;
        $lc = $gp->last_choice;
        $upd->execute([
            (int)$gp->score,
            $gp->is_eliminated ? 1 : 0,
            $lc === null ? null : (float)$lc,
            $code,
            $pid,
        ]);
    }

    // reload parts for payload/history picks
    $partsAfter = fetch_participants_in_game_order($pdo, $code);
    $playersPayload = game_players_payload($partsAfter);

    $winnersIds = [];
    foreach (($res['winners'] ?? []) as $wp) { $winnersIds[] = $gidToPid[$wp->id] ?? null; }
    $losersIds = [];
    foreach (($res['losers'] ?? []) as $lp) { $losersIds[] = $gidToPid[$lp->id] ?? null; }

    $picks = [];
    foreach ($playersPayload as $gp) {
        if ($gp['choice'] !== null) {
            $picks[] = [
                'id' => $gp['id'],
                'name' => $gp['name'],
                'choice' => $gp['choice'],
                'isEliminated' => $gp['isEliminated'],
            ];
        }
    }

    [$matchWinnerId, $gameOver] = recompute_game_over_flag($partsAfter);

    $activeCount = isset($res['active_count']) ? (int)$res['active_count'] : (isset($res['activeCount']) ? (int)$res['activeCount'] : 0);
    $ins = $pdo->prepare('INSERT INTO round_history (room_code, round_number, avg_value, target_value, active_count, logs_json, picks_json, winner_ids_json, loser_ids_json, match_winner_id, game_over, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $ins->execute([
        $code,
        $round,
        (float)$res['avg'],
        (float)$res['target'],
        $activeCount,
        json_encode(array_values($res['logs'] ?? []), JSON_UNESCAPED_UNICODE),
        json_encode($picks, JSON_UNESCAPED_UNICODE),
        json_encode(array_values($winnersIds), JSON_UNESCAPED_UNICODE),
        json_encode(array_values($losersIds), JSON_UNESCAPED_UNICODE),
        $matchWinnerId,
        $gameOver ? 1 : 0,
        dt_from_micro(now_micro()),
    ]);

    $readyDeadline = $gameOver ? null : dt_from_micro(now_micro() + READY_COUNTDOWN);
    $pdo->prepare('UPDATE rooms SET state=\'result\', pick_deadline_at=NULL, ready_deadline_at=?, updated_at=? WHERE code=?')
        ->execute([$readyDeadline, dt_from_micro(now_micro()), $code]);
    $pdo->prepare('DELETE FROM round_readies WHERE room_code=? AND round_number=?')->execute([$code, $round]);

    $room['state'] = 'result';
    $room['pick_deadline_at'] = null;
    $room['ready_deadline_at'] = $readyDeadline;
}

function next_round(PDO $pdo, array &$room): void
{
    $code = $room['code'];
    $partsOrdered = fetch_participants_in_game_order($pdo, $code);
    [$mwId, $gameOver] = recompute_game_over_flag($partsOrdered);
    if ($gameOver) {
        return;
    }
    $newRound = (int)$room['round_number'] + 1;
    $pickDl = dt_from_micro(now_micro() + PICK_COUNTDOWN);
    $pdo->prepare('UPDATE rooms SET state=\'playing\', round_number=?, pick_deadline_at=?, ready_deadline_at=NULL, updated_at=? WHERE code=?')
        ->execute([$newRound, $pickDl, dt_from_micro(now_micro()), $code]);
    // clear submissions/readies for the new round (submissions are per-round anyway; clear just in case)
    $pdo->prepare('DELETE FROM submissions WHERE room_code=? AND round_number=?')->execute([$code, $newRound]);
    $pdo->prepare('DELETE FROM round_readies WHERE room_code=?')->execute([$code]);

    $room['state'] = 'playing';
    $room['round_number'] = $newRound;
    $room['pick_deadline_at'] = $pickDl;
    $room['ready_deadline_at'] = null;

    $parts = fetch_participants($pdo, $code);
    auto_submit_bots($pdo, $code, $newRound, $parts);
}

function apply_timeouts_and_advance(PDO $pdo, array &$room): void
{
    $code = $room['code'];
    $now = now_micro();
    if ($room['state'] === 'playing') {
        $dl = micro_from_dt($room['pick_deadline_at']);
        if ($dl !== null && $now >= $dl) {
            $parts = fetch_participants($pdo, $code);
            $subs = get_submissions_map($pdo, $code, (int)$room['round_number']);
            auto_pick_missing_humans($pdo, $code, (int)$room['round_number'], $parts, $subs);
            // ensure bots too (robustness)
            auto_submit_bots($pdo, $code, (int)$room['round_number'], $parts);
            $subs = get_submissions_map($pdo, $code, (int)$room['round_number']);
            $parts = fetch_participants($pdo, $code);
            if (all_active_submitted($parts, $subs)) {
                finalize_round($pdo, $room);
                // reload room row state for caller
                $stmt = $pdo->prepare('SELECT * FROM rooms WHERE code=?');
                $stmt->execute([$code]);
                $fresh = $stmt->fetch();
                if ($fresh) $room = $fresh;
            }
        }
    }
    if ($room['state'] === 'result') {
        $partsOrdered = fetch_participants_in_game_order($pdo, $code);
        [$mwId, $gameOver] = recompute_game_over_flag($partsOrdered);
        if (!$gameOver) {
            $dl = micro_from_dt($room['ready_deadline_at']);
            if ($dl !== null && $now >= $dl) {
                next_round($pdo, $room);
                $stmt = $pdo->prepare('SELECT * FROM rooms WHERE code=?');
                $stmt->execute([$code]);
                $fresh = $stmt->fetch();
                if ($fresh) $room = $fresh;
            }
        }
    }
}

function fetch_history(PDO $pdo, string $code): array
{
    $stmt = $pdo->prepare('SELECT * FROM round_history WHERE room_code=? ORDER BY round_number ASC');
    $stmt->execute([$code]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = [
            'round' => (int)$r['round_number'],
            'avg' => (float)$r['avg_value'],
            'target' => (float)$r['target_value'],
            'activeCount' => isset($r['active_count']) ? (int)$r['active_count'] : 0,
            'logs' => json_decode($r['logs_json'] ?? '[]', true) ?: [],
            'picks' => json_decode($r['picks_json'] ?? '[]', true) ?: [],
            'winnerIds' => json_decode($r['winner_ids_json'] ?? '[]', true) ?: [],
            'loserIds' => json_decode($r['loser_ids_json'] ?? '[]', true) ?: [],
        ];
    }
    return $out;
}

function build_state_snapshot(PDO $pdo, array $room, string $playerId): array
{
    $code = $room['code'];
    $partsAll = fetch_participants($pdo, $code);
    $partsOrdered = fetch_participants_in_game_order($pdo, $code);
    $history = fetch_history($pdo, $code);
    $subs = get_submissions_map($pdo, $code, (int)$room['round_number']);
    $submitted = array_values(array_keys($subs));

    $snapshot = [
        'type' => 'state_sync',
        'playerId' => $playerId,
        'code' => $code,
        'isHost' => $playerId === $room['host_player_id'],
        'hostId' => $room['host_player_id'],
        'state' => $room['state'],
        'round' => (int)$room['round_number'],
        'history' => $history,
        'submitted' => $submitted,
    ];

    if ($room['state'] === 'lobby' || count($partsOrdered) === 0) {
        $snapshot['players'] = player_list_payload($partsAll, $room['host_player_id']);
    } else {
        $snapshot['players'] = game_players_payload($partsOrdered);
        if ($room['state'] === 'playing') {
            $left = pick_seconds_left($room);
            $snapshot['pickCountdown'] = $left !== null ? $left : PICK_COUNTDOWN;
            $snapshot['pickDeadline'] = PICK_COUNTDOWN;
        }
        if ($room['state'] === 'result') {
            // reconstruct result from latest history entry
            $last = null;
            if (count($history) > 0) { $last = $history[count($history) - 1]; }
            [$mwId, $gameOver] = recompute_game_over_flag($partsOrdered);
            if ($last && (int)$last['round'] === (int)$room['round_number']) {
                $result = [
                    'type' => 'round_result',
                    'round' => (int)$last['round'],
                    'avg' => $last['avg'],
                    'target' => $last['target'],
                    'logs' => $last['logs'],
                    'activeCount' => isset($last['activeCount']) ? (int)$last['activeCount'] : count(active_participants($partsOrdered)),
                    'players' => $snapshot['players'],
                    'winnerIds' => $last['winnerIds'],
                    'loserIds' => $last['loserIds'],
                    'matchWinnerId' => $mwId,
                    'gameOver' => $gameOver,
                    'history' => $history,
                ];
                if (!$gameOver) {
                    $required = active_human_participants($partsOrdered);
                    $readyIds = get_ready_ids($pdo, $code, (int)$room['round_number']);
                    $reqIds = array_map(fn($p) => $p['participant_id'], $required);
                    $readyFiltered = array_values(array_intersect($readyIds, $reqIds));
                    $result['ready'] = $readyFiltered;
                    $result['readyCount'] = count($readyFiltered);
                    $result['requiredCount'] = count($reqIds);
                    $result['countdown'] = ready_countdown_left($room);
                }
                $snapshot['result'] = $result;
            }
        }
    }
    return $snapshot;
}

function with_room_lock(string $code, callable $fn)
{
    $code = strtoupper(trim($code));
    $pdo = get_pdo();
    $pdo->beginTransaction();
    try {
        $room = fetch_room_locked($pdo, $code);
        if (!$room) {
            $pdo->rollBack();
            return [null, null];
        }
        $res = $fn($pdo, $room);
        $pdo->commit();
        return [$room['code'], $res];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function service_create_room(string $name): array
{
    $pdo = get_pdo();
    $name = trim($name);
    if ($name === '') $name = 'Player';
    $pdo->beginTransaction();
    try {
        $code = gen_room_code($pdo);
        $playerId = gen_uuid();
        $now = dt_from_micro(now_micro());
        $pdo->prepare('INSERT INTO rooms (code, host_player_id, state, round_number, pick_deadline_at, ready_deadline_at, single_player, bot_count, created_at, updated_at) VALUES (?,?,?,?,NULL,NULL,0,0,?,?)')
            ->execute([$code, $playerId, 'lobby', 0, $now, $now]);
        $pdo->prepare('INSERT INTO room_participants (room_code, participant_id, name, is_host, is_bot, game_player_id, score, is_eliminated, last_choice, joined_at) VALUES (?,?,?,?,0,NULL,0,0,NULL,?)')
            ->execute([$code, $playerId, $name, 1, $now]);
        $pdo->commit();
        $parts = [['participant_id'=>$playerId,'name'=>$name,'is_host'=>1,'is_bot'=>0]];
        return [
            'type' => 'joined',
            'playerId' => $playerId,
            'code' => $code,
            'name' => $name,
            'isHost' => true,
            'players' => [['id'=>$playerId,'name'=>$name,'isHost'=>true,'isBot'=>false]],
            'singlePlayer' => false,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function service_join_room(string $name, string $code): array
{
    $name = trim($name);
    if ($name === '') $name = 'Player';
    $code = strtoupper(trim($code));
    [$c, $res] = with_room_lock($code, function (PDO $pdo, array &$room) use ($name) {
        $parts = fetch_participants($pdo, $room['code']);
        if ((int)$room['single_player'] === 1) {
            return ['error' => 'Single-player room'];
        }
        if ($room['state'] !== 'lobby') {
            return ['error' => 'Game already started'];
        }
        if (count($parts) >= 10) {
            return ['error' => 'Room is full'];
        }
        $playerId = gen_uuid();
        $now = dt_from_micro(now_micro());
        $pdo->prepare('INSERT INTO room_participants (room_code, participant_id, name, is_host, is_bot, game_player_id, score, is_eliminated, last_choice, joined_at) VALUES (?,?,?,?,0,NULL,0,0,NULL,?)')
            ->execute([$room['code'], $playerId, $name, 0, $now]);
        $pdo->prepare('UPDATE rooms SET updated_at=? WHERE code=?')->execute([$now, $room['code']]);
        $parts = fetch_participants($pdo, $room['code']);
        return [
            'type' => 'joined',
            'playerId' => $playerId,
            'code' => $room['code'],
            'name' => $name,
            'isHost' => false,
            'players' => player_list_payload($parts, $room['host_player_id']),
            'singlePlayer' => false,
        ];
    });
    if ($c === null) return ['type' => 'error', 'message' => 'Room not found'];
    if (isset($res['error'])) return ['type' => 'error', 'message' => $res['error']];
    return $res;
}

function service_rejoin_room(string $playerId, string $code): array
{
    $code = strtoupper(trim($code));
    $playerId = trim($playerId);
    [$c, $res] = with_room_lock($code, function (PDO $pdo, array &$room) use ($playerId) {
        apply_timeouts_and_advance($pdo, $room);
        $stmt = $pdo->prepare('SELECT 1 FROM room_participants WHERE room_code=? AND participant_id=?');
        $stmt->execute([$room['code'], $playerId]);
        if (!$stmt->fetchColumn()) {
            return ['type' => 'session_invalid', 'message' => 'Session not found'];
        }
        // reload room
        $r = $pdo->prepare('SELECT * FROM rooms WHERE code=?'); $r->execute([$room['code']]);
        $room = $r->fetch() ?: $room;
        return build_state_snapshot($pdo, $room, $playerId);
    });
    if ($c === null) return ['type' => 'session_invalid', 'message' => 'Session not found'];
    return $res;
}

function service_get_state(string $playerId, string $code): array
{
    $code = strtoupper(trim($code));
    $playerId = trim($playerId);
    [$c, $res] = with_room_lock($code, function (PDO $pdo, array &$room) use ($playerId) {
        apply_timeouts_and_advance($pdo, $room);
        $stmt = $pdo->prepare('SELECT 1 FROM room_participants WHERE room_code=? AND participant_id=?');
        $stmt->execute([$room['code'], $playerId]);
        if (!$stmt->fetchColumn()) {
            return ['type' => 'session_invalid', 'message' => 'Session not found'];
        }
        $r = $pdo->prepare('SELECT * FROM rooms WHERE code=?'); $r->execute([$room['code']]);
        $room = $r->fetch() ?: $room;
        return build_state_snapshot($pdo, $room, $playerId);
    });
    if ($c === null) return ['type' => 'session_invalid', 'message' => 'Session not found'];
    return $res;
}

function service_start_game(string $playerId, string $code): array
{
    $code = strtoupper(trim($code));
    [$c, $res] = with_room_lock($code, function (PDO $pdo, array &$room) use ($playerId) {
        apply_timeouts_and_advance($pdo, $room);
        if ($playerId !== $room['host_player_id']) {
            return ['type' => 'error', 'message' => 'Only host can start'];
        }
        if ($room['state'] !== 'lobby') {
            return ['type' => 'error', 'message' => 'Game already started'];
        }
        $parts = fetch_participants($pdo, $room['code']);
        if (count($parts) < 2) {
            return ['type' => 'error', 'message' => 'Need at least 2 players'];
        }
        // assign game_player_id in join order, init state
        $gid = 1;
        $upd = $pdo->prepare('UPDATE room_participants SET game_player_id=?, score=?, is_eliminated=?, last_choice=? WHERE room_code=? AND participant_id=?');
        foreach ($parts as $p) {
            $upd->execute([$gid, STARTING_SCORE, 0, null, $room['code'], $p['participant_id']]);
            $gid++;
        }
        $now = dt_from_micro(now_micro());
        $pickDl = dt_from_micro(now_micro() + PICK_COUNTDOWN);
        $pdo->prepare('UPDATE rooms SET state=\'playing\', round_number=1, pick_deadline_at=?, ready_deadline_at=NULL, updated_at=? WHERE code=?')
            ->execute([$pickDl, $now, $room['code']]);
        $pdo->prepare('DELETE FROM submissions WHERE room_code=?')->execute([$room['code']]);
        $pdo->prepare('DELETE FROM round_readies WHERE room_code=?')->execute([$room['code']]);
        $pdo->prepare('DELETE FROM round_history WHERE room_code=?')->execute([$room['code']]);

        $parts = fetch_participants($pdo, $room['code']);
        auto_submit_bots($pdo, $room['code'], 1, $parts);

        $r = $pdo->prepare('SELECT * FROM rooms WHERE code=?'); $r->execute([$room['code']]);
        $room = $r->fetch() ?: $room;
        return build_state_snapshot($pdo, $room, $playerId);
    });
    if ($c === null) return ['type' => 'error', 'message' => 'Room not found'];
    return $res;
}

function service_submit(string $playerId, string $code, $value): array
{
    $code = strtoupper(trim($code));
    if (!is_numeric($value)) {
        return ['type' => 'error', 'message' => 'Invalid number'];
    }
    $val = (float)$value;
    $val = max(0.0, min(100.0, $val));
    [$c, $res] = with_room_lock($code, function (PDO $pdo, array &$room) use ($playerId, $val) {
        apply_timeouts_and_advance($pdo, $room);
        if ($room['state'] !== 'playing') {
            return ['type' => 'error', 'message' => 'Not accepting submissions'];
        }
        // participant check
        $stmt = $pdo->prepare('SELECT * FROM room_participants WHERE room_code=? AND participant_id=?');
        $stmt->execute([$room['code'], $playerId]);
        $me = $stmt->fetch();
        if (!$me || (int)$me['is_bot'] === 1) {
            return ['type' => 'error', 'message' => 'Player not found'];
        }
        if ((int)$me['is_eliminated'] === 1) {
            return ['type' => 'error', 'message' => 'You are eliminated and cannot submit'];
        }
        // already submitted?
        $chk = $pdo->prepare('SELECT 1 FROM submissions WHERE room_code=? AND round_number=? AND participant_id=?');
        $chk->execute([$room['code'], (int)$room['round_number'], $playerId]);
        if (!$chk->fetchColumn()) {
            $ins = $pdo->prepare('INSERT INTO submissions (room_code, round_number, participant_id, value, submitted_at) VALUES (?,?,?,?,?)');
            $ins->execute([$room['code'], (int)$room['round_number'], $playerId, $val, dt_from_micro(now_micro())]);
        }
        $parts = fetch_participants($pdo, $room['code']);
        $subs = get_submissions_map($pdo, $room['code'], (int)$room['round_number']);
        if (all_active_submitted($parts, $subs)) {
            finalize_round($pdo, $room);
        }
        $r = $pdo->prepare('SELECT * FROM rooms WHERE code=?'); $r->execute([$room['code']]);
        $room = $r->fetch() ?: $room;
        return build_state_snapshot($pdo, $room, $playerId);
    });
    if ($c === null) return ['type' => 'error', 'message' => 'Room not found'];
    return $res;
}

function service_ready(string $playerId, string $code): array
{
    $code = strtoupper(trim($code));
    [$c, $res] = with_room_lock($code, function (PDO $pdo, array &$room) use ($playerId) {
        apply_timeouts_and_advance($pdo, $room);
        if ($room['state'] !== 'result') {
            return ['type' => 'error', 'message' => 'Not in result state'];
        }
        $partsOrdered = fetch_participants_in_game_order($pdo, $room['code']);
        [$mwId, $gameOver] = recompute_game_over_flag($partsOrdered);
        if ($gameOver) {
            $r = $pdo->prepare('SELECT * FROM rooms WHERE code=?'); $r->execute([$room['code']]);
            $room = $r->fetch() ?: $room;
            return build_state_snapshot($pdo, $room, $playerId);
        }
        $stmt = $pdo->prepare('SELECT * FROM room_participants WHERE room_code=? AND participant_id=?');
        $stmt->execute([$room['code'], $playerId]);
        $me = $stmt->fetch();
        if ($me && (int)$me['is_bot'] === 0 && (int)$me['is_eliminated'] === 0) {
            $pdo->prepare('INSERT IGNORE INTO round_readies (room_code, round_number, participant_id) VALUES (?,?,?)')
                ->execute([$room['code'], (int)$room['round_number'], $playerId]);
        }
        // check all humans ready
        $partsOrdered = fetch_participants_in_game_order($pdo, $room['code']);
        $required = active_human_participants($partsOrdered);
        $reqIds = array_map(fn($p) => $p['participant_id'], $required);
        $readyIds = get_ready_ids($pdo, $room['code'], (int)$room['round_number']);
        $allReady = count($reqIds) > 0;
        foreach ($reqIds as $rid) {
            if (!in_array($rid, $readyIds, true)) { $allReady = false; break; }
        }
        if ($allReady) {
            next_round($pdo, $room);
        }
        $r = $pdo->prepare('SELECT * FROM rooms WHERE code=?'); $r->execute([$room['code']]);
        $room = $r->fetch() ?: $room;
        return build_state_snapshot($pdo, $room, $playerId);
    });
    if ($c === null) return ['type' => 'error', 'message' => 'Room not found'];
    return $res;
}

function service_leave(string $playerId, string $code): array
{
    $code = strtoupper(trim($code));
    [$c, $res] = with_room_lock($code, function (PDO $pdo, array &$room) use ($playerId) {
        $stmt = $pdo->prepare('SELECT * FROM room_participants WHERE room_code=? AND participant_id=?');
        $stmt->execute([$room['code'], $playerId]);
        $me = $stmt->fetch();
        if (!$me) {
            return ['ok' => true];
        }
        $pdo->prepare('DELETE FROM room_participants WHERE room_code=? AND participant_id=?')->execute([$room['code'], $playerId]);
        $pdo->prepare('DELETE FROM submissions WHERE room_code=? AND participant_id=?')->execute([$room['code'], $playerId]);
        $pdo->prepare('DELETE FROM round_readies WHERE room_code=? AND participant_id=?')->execute([$room['code'], $playerId]);
        // reassign host
        $parts = fetch_participants($pdo, $room['code']);
        if ($room['host_player_id'] === $playerId && count($parts) > 0) {
            $newHost = $parts[0]['participant_id'];
            $pdo->prepare('UPDATE rooms SET host_player_id=?, updated_at=? WHERE code=?')->execute([$newHost, dt_from_micro(now_micro()), $room['code']]);
            $pdo->prepare('UPDATE room_participants SET is_host = CASE WHEN participant_id=? THEN 1 ELSE 0 END WHERE room_code=?')->execute([$newHost, $room['code']]);
        }
        $parts = fetch_participants($pdo, $room['code']);
        $humansLeft = 0;
        foreach ($parts as $p) { if ((int)$p['is_bot'] === 0) $humansLeft++; }
        if ($humansLeft === 0 && (int)$room['single_player'] === 0) {
            $pdo->prepare('DELETE FROM rooms WHERE code=?')->execute([$room['code']]);
            return ['ok' => true, 'removed' => true];
        }
        // if in result state, leaving may make remaining humans all ready
        $r = $pdo->prepare('SELECT * FROM rooms WHERE code=?'); $r->execute([$room['code']]);
        $room = $r->fetch() ?: $room;
        if ($room && $room['state'] === 'result') {
            $partsOrdered = fetch_participants_in_game_order($pdo, $room['code']);
            [$mwId, $gameOver] = recompute_game_over_flag($partsOrdered);
            if (!$gameOver) {
                $required = active_human_participants($partsOrdered);
                $reqIds = array_map(fn($p) => $p['participant_id'], $required);
                $readyIds = get_ready_ids($pdo, $room['code'], (int)$room['round_number']);
                $allReady = count($reqIds) > 0;
                foreach ($reqIds as $rid) { if (!in_array($rid, $readyIds, true)) { $allReady = false; break; } }
                if ($allReady) {
                    next_round($pdo, $room);
                    $r = $pdo->prepare('SELECT * FROM rooms WHERE code=?'); $r->execute([$room['code']]);
                    $room = $r->fetch() ?: $room;
                }
            }
        }
        if ($room && $room['state'] === 'playing') {
            $partsNow = fetch_participants($pdo, $room['code']);
            $subsNow = get_submissions_map($pdo, $room['code'], (int)$room['round_number']);
            if (all_active_submitted($partsNow, $subsNow)) {
                finalize_round($pdo, $room);
                $r = $pdo->prepare('SELECT * FROM rooms WHERE code=?'); $r->execute([$room['code']]);
                $room = $r->fetch() ?: $room;
            } else {
                apply_timeouts_and_advance($pdo, $room);
                $r = $pdo->prepare('SELECT * FROM rooms WHERE code=?'); $r->execute([$room['code']]);
                $room = $r->fetch() ?: $room;
            }
        }
        return ['ok' => true];
    });
    if ($c === null) return ['ok' => true];
    return $res;
}
