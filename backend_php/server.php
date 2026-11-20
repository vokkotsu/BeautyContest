<?php

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

require __DIR__ . '/vendor/autoload.php';

class BeautyContestServer implements MessageComponentInterface
{
    /** @var \SplObjectStorage */
    protected $clients;
    /** @var RoomManager */
    protected $manager;
    /** @var \React\EventLoop\LoopInterface */
    protected $loop;

    public function __construct($loop)
    {
        $this->clients = new \SplObjectStorage();
        $this->loop = $loop;
        global $manager;
        $this->manager = $manager;
    }

    private function genUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        $conn->appState = [
            'player_id' => $this->genUuid(),
            'room' => null,
            'code' => null,
            'rejoined' => false,
            'explicit_leave' => false,
            'initialized' => false,
        ];
    }

    private function sendJson(ConnectionInterface $conn, array $msg): void
    {
        try {
            $conn->send(json_encode($msg));
        } catch (\Throwable $e) {
        }
    }

    private function broadcastRoundStart(Room $room): void
    {
        $room->broadcast([
            'type' => 'round_start',
            'submitted' => array_values(array_keys($room->submissions)),
            'round' => $room->round_number,
            'history' => $room->history,
            'pickCountdown' => Room::PICK_COUNTDOWN,
        ]);
    }

    private function finishRoundIfReady(Room $room): bool
    {
        if ($room->state !== 'playing' || !$room->all_submitted()) {
            return false;
        }
        $payload = $room->calculate($this->loop);
        $room->broadcast($payload);
        if (empty($payload['gameOver'])) {
            $this->scheduleAutoNextRound($room);
        }
        return true;
    }

    private function schedulePickTimeout(Room $room): void
    {
        $room->cancel_pick_timer($this->loop);
        $room->pick_timer = $this->loop->addTimer(Room::PICK_COUNTDOWN, function () use ($room) {
            $room->pick_timer = null;
            if ($room->state !== 'playing') {
                return;
            }
            $changed = $room->auto_pick_missing();
            if ($changed) {
                $room->broadcast(['type' => 'submission_update', 'submitted' => array_values(array_keys($room->submissions))]);
            }
            $this->finishRoundIfReady($room);
        });
    }

    private function scheduleAutoNextRound(Room $room): void
    {
        $room->cancel_auto_next($this->loop);
        $room->auto_next_timer = $this->loop->addTimer(Room::READY_COUNTDOWN, function () use ($room) {
            $room->auto_next_timer = null;
            if ($room->state !== 'result') {
                return;
            }
            $mw = $room->game ? $room->game->get_match_winner() : null;
            if ($mw !== null) {
                return;
            }
            $room->next_round($this->loop);
            $this->broadcastRoundStart($room);
            // Note: intentionally not scheduling pick timeout here to mirror original behavior
        });
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg, true);
        if (!is_array($data)) {
            return;
        }
        $state = &$from->appState;

        if (!$state['initialized']) {
            $this->handleInitial($from, $data);
            return;
        }

        /** @var Room|null $room */
        $room = $state['room'];
        $player_id = $state['player_id'];
        $code = $state['code'];
        if ($room === null) {
            return;
        }

        $t = $data['type'] ?? null;

        if ($t === 'start_game') {
            if ($player_id !== $room->host_id) {
                $this->sendJson($from, ['type' => 'error', 'message' => 'Only host can start']);
                return;
            }
            if ($room->single_player && count($room->bots) === 0) {
                $room->setup_bots();
            }
            if (count($room->players) + count($room->bots) < 2) {
                $this->sendJson($from, ['type' => 'error', 'message' => 'Need at least 2 players']);
                return;
            }
            if ($room->state !== 'lobby') {
                return;
            }
            $room->start_game($this->loop);
            $room->broadcast(['type' => 'game_started', 'round' => $room->round_number, 'history' => $room->history]);
            $this->broadcastRoundStart($room);
            $this->schedulePickTimeout($room);
            return;
        }

        if ($t === 'submit') {
            if ($room->state !== 'playing') {
                return;
            }
            if (array_key_exists($player_id, $room->submissions)) {
                return;
            }
            if (!isset($data['value']) || !is_numeric($data['value'])) {
                $this->sendJson($from, ['type' => 'error', 'message' => 'Invalid number']);
                return;
            }
            $val = (float)$data['value'];
            $val = max(0.0, min(100.0, $val));
            if ($room->_is_eliminated($player_id)) {
                $this->sendJson($from, ['type' => 'error', 'message' => 'You are eliminated and cannot submit']);
                return;
            }
            $room->submit($player_id, $val);
            $submitted_ids = array_values(array_keys($room->submissions));
            $room->broadcast(['type' => 'submission_update', 'submitted' => $submitted_ids]);
            $this->finishRoundIfReady($room);
            return;
        }

        if ($t === 'ready_next') {
            if ($room->state !== 'result') {
                return;
            }
            $mw = $room->game ? $room->game->get_match_winner() : null;
            if ($mw !== null) {
                return;
            }
            $changed = $room->mark_ready($player_id);
            if ($changed && $room->all_humans_ready()) {
                $room->next_round($this->loop);
                $this->broadcastRoundStart($room);
                $this->schedulePickTimeout($room);
            } elseif ($changed) {
                $room->broadcast($room->ready_payload());
            }
            return;
        }

        if ($t === 'next_round') {
            if ($player_id !== $room->host_id) {
                return;
            }
            if ($room->state !== 'result') {
                return;
            }
            $mw = $room->game ? $room->game->get_match_winner() : null;
            if ($mw !== null) {
                return;
            }
            $room->next_round($this->loop);
            $this->broadcastRoundStart($room);
            $this->schedulePickTimeout($room);
            return;
        }

        if ($t === 'leave') {
            $state['explicit_leave'] = true;
            $from->close();
            return;
        }
    }

    private function handleInitial(ConnectionInterface $from, array $data): void
    {
        $state = &$from->appState;
        $type = $data['type'] ?? null;

        if ($type === 'create_room') {
            $name = trim((string)($data['name'] ?? 'Player'));
            if ($name === '') $name = 'Player';
            $mode = $data['mode'] ?? 'multi';
            $single = ($mode === 'single');
            $bot_count = (int)($data['bots'] ?? 0);
            $bot_count = max(0, min(9, $bot_count));
            if ($single) {
                $bot_count = ($bot_count === 0) ? 1 : $bot_count;
            }
            $player_id = $state['player_id'];
            $code = $this->manager->create_room($player_id, $single, $bot_count);
            $room = $this->manager->get_room($code);
            $room->add_player($player_id, $name, $from);
            if ($single) {
                $room->setup_bots();
            }
            $state['room'] = $room;
            $state['code'] = $code;
            $state['initialized'] = true;
            $state['rejoined'] = false;
            $this->sendJson($from, [
                'type' => 'joined',
                'playerId' => $player_id,
                'code' => $code,
                'name' => $room->players[$player_id] ?? $name,
                'isHost' => $player_id === $room->host_id,
                'players' => $room->player_list(),
                'singlePlayer' => $room->single_player,
            ]);
            $room->broadcast(['type' => 'lobby_update', 'players' => ($room->state === 'lobby' || $room->game === null ? $room->player_list() : $room->game_players_payload()), 'hostId' => $room->host_id]);
            return;
        }

        if ($type === 'join_room') {
            $name = trim((string)($data['name'] ?? 'Player'));
            if ($name === '') $name = 'Player';
            $code = strtoupper(trim((string)($data['code'] ?? '')));
            $room = $this->manager->get_room($code);
            if ($room === null) {
                $this->sendJson($from, ['type' => 'error', 'message' => 'Room not found']);
                $from->close();
                return;
            }
            if ($room->single_player) {
                $this->sendJson($from, ['type' => 'error', 'message' => 'Single-player room']);
                $from->close();
                return;
            }
            if (count($room->players) + count($room->bots) >= 10) {
                $this->sendJson($from, ['type' => 'error', 'message' => 'Room is full']);
                $from->close();
                return;
            }
            $player_id = $state['player_id'];
            $room->add_player($player_id, $name, $from);
            $state['room'] = $room;
            $state['code'] = $code;
            $state['initialized'] = true;
            $state['rejoined'] = false;
            $this->sendJson($from, [
                'type' => 'joined',
                'playerId' => $player_id,
                'code' => $code,
                'name' => $room->players[$player_id] ?? $name,
                'isHost' => $player_id === $room->host_id,
                'players' => $room->player_list(),
                'singlePlayer' => $room->single_player,
            ]);
            $room->broadcast(['type' => 'lobby_update', 'players' => ($room->state === 'lobby' || $room->game === null ? $room->player_list() : $room->game_players_payload()), 'hostId' => $room->host_id]);
            return;
        }

        if ($type === 'rejoin_room') {
            $player_id = trim((string)($data['playerId'] ?? ''));
            $code = strtoupper(trim((string)($data['code'] ?? '')));
            $room = $this->manager->get_room($code);
            if ($room === null || $player_id === '' || !isset($room->players[$player_id])) {
                $this->sendJson($from, ['type' => 'session_invalid', 'message' => 'Session not found']);
                $from->close();
                return;
            }
            $room->attach_player($player_id, $from);
            $state['player_id'] = $player_id;
            $state['room'] = $room;
            $state['code'] = $code;
            $state['initialized'] = true;
            $state['rejoined'] = true;
            $this->sendJson($from, $room->state_snapshot($player_id));
            $room->broadcast(['type' => 'lobby_update', 'players' => ($room->state === 'lobby' || $room->game === null ? $room->player_list() : $room->game_players_payload()), 'hostId' => $room->host_id]);
            return;
        }

        $this->sendJson($from, ['type' => 'error', 'message' => 'First message must create, join, or rejoin a room']);
        $from->close();
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        $state = $conn->appState ?? null;
        if (!$state) return;
        /** @var Room|null $room */
        $room = $state['room'] ?? null;
        $player_id = $state['player_id'] ?? null;
        $code = $state['code'] ?? null;
        $explicit_leave = $state['explicit_leave'] ?? false;

        if ($room === null || $player_id === null) return;
        if (!isset($room->players[$player_id])) return;

        if ($explicit_leave) {
            $room->remove_player($player_id);
            if (count($room->players) === 0 && !$room->single_player) {
                $room->cancel_auto_next($this->loop);
                $this->manager->remove_empty($code);
            } elseif ($room->state === 'result') {
                $mw = $room->game ? $room->game->get_match_winner() : null;
                if ($mw === null && $room->all_humans_ready()) {
                    $room->next_round($this->loop);
                    $this->broadcastRoundStart($room);
                } else {
                    $room->broadcast($room->ready_payload());
                    $room->broadcast(['type' => 'lobby_update', 'players' => ($room->state === 'lobby' || $room->game === null ? $room->player_list() : $room->game_players_payload()), 'hostId' => $room->host_id]);
                }
            } else {
                $room->broadcast(['type' => 'lobby_update', 'players' => ($room->state === 'lobby' || $room->game === null ? $room->player_list() : $room->game_players_payload()), 'hostId' => $room->host_id]);
            }
        } else {
            $room->detach_player($player_id);
            if ($room->state === 'playing') {
                $this->finishRoundIfReady($room);
            }
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        try {
            $conn->close();
        } catch (\Throwable $ex) {
        }
    }
}

$port = (int)(getenv('PORT') ?: 8000);
$loop = \React\EventLoop\Loop::get();
$wsServer = new WsServer(new BeautyContestServer($loop));
$httpServer = new HttpServer($wsServer);
$server = IoServer::factory($httpServer, $port, '0.0.0.0', $loop);

echo "PHP WebSocket server running on ws://0.0.0.0:{$port}\n";
$server->run();
