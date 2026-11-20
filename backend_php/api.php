<?php

require_once __DIR__ . '/game_service.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = $uri;
if (preg_match('#/api(/.*)?$#', $uri, $m)) {
    $path = $m[1] ?: '/';
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

try {
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    if ($method === 'POST' && $path === '/room/create') {
        $b = read_json_body();
        $name = isset($b['name']) ? (string)$b['name'] : 'Player';
        json_out(service_create_room($name));
    }

    if ($method === 'POST' && $path === '/room/join') {
        $b = read_json_body();
        $name = isset($b['name']) ? (string)$b['name'] : 'Player';
        $code = isset($b['code']) ? (string)$b['code'] : '';
        $res = service_join_room($name, $code);
        json_out($res);
    }

    if ($method === 'POST' && $path === '/room/rejoin') {
        $b = read_json_body();
        $playerId = isset($b['playerId']) ? (string)$b['playerId'] : '';
        $code = isset($b['code']) ? (string)$b['code'] : '';
        $res = service_rejoin_room($playerId, $code);
        json_out($res);
    }

    if ($method === 'GET' && ($path === '/room/state' || $path === '/state')) {
        $playerId = isset($_GET['playerId']) ? (string)$_GET['playerId'] : '';
        $code = isset($_GET['code']) ? (string)$_GET['code'] : '';
        $res = service_get_state($playerId, $code);
        json_out($res);
    }

    if ($method === 'POST' && $path === '/game/start') {
        $b = read_json_body();
        $playerId = isset($b['playerId']) ? (string)$b['playerId'] : '';
        $code = isset($b['code']) ? (string)$b['code'] : '';
        json_out(service_start_game($playerId, $code));
    }

    if ($method === 'POST' && $path === '/game/submit') {
        $b = read_json_body();
        $playerId = isset($b['playerId']) ? (string)$b['playerId'] : '';
        $code = isset($b['code']) ? (string)$b['code'] : '';
        $value = $b['value'] ?? null;
        json_out(service_submit($playerId, $code, $value));
    }

    if ($method === 'POST' && $path === '/game/ready') {
        $b = read_json_body();
        $playerId = isset($b['playerId']) ? (string)$b['playerId'] : '';
        $code = isset($b['code']) ? (string)$b['code'] : '';
        json_out(service_ready($playerId, $code));
    }

    if ($method === 'POST' && ($path === '/game/leave' || $path === '/room/leave')) {
        $b = read_json_body();
        $playerId = isset($b['playerId']) ? (string)$b['playerId'] : '';
        $code = isset($b['code']) ? (string)$b['code'] : '';
        json_out(service_leave($playerId, $code));
    }

    http_response_code(404);
    echo json_encode(['type' => 'error', 'message' => 'Not found'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    $msg = 'Server error';
    // avoid leaking details in production; include basic hint
    if (getenv('APP_DEBUG') === '1') {
        $msg = $e->getMessage();
    }
    echo json_encode(['type' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
}
