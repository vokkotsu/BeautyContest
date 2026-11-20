<?php
// Convenience script to run the app locally (built SPA + HTTP API).
// Usage: php run_php.php
// Serves frontend/dist and /api via root index.php on PHP's built-in server.
// Prereqs: build frontend (cd frontend && npm run build). For multiplayer, configure MySQL
// in backend_php/config.php (or DB_* env vars) and import backend_php/schema.sql.

$root = __DIR__;
$php = PHP_BINARY ?: 'php';

$dist = $root . '/frontend/dist/index.html';
if (!is_file($dist)) {
    fwrite(STDERR, "Warning: frontend/dist not found. Build first: cd frontend && npm install --include=dev && npm run build\n\n");
}

$descriptors = [0 => STDIN, 1 => STDOUT, 2 => STDERR];
$cmd = escapeshellarg($php) . ' -S 0.0.0.0:5173 ' . escapeshellarg($root . '/index.php');
$proc = proc_open($cmd, $descriptors, $pipes, $root, null);
if (!is_resource($proc)) {
    fwrite(STDERR, "Failed to start PHP dev server.\n");
    exit(1);
}

echo "App running at: http://localhost:5173\n";
echo "Single Player works without a database. Multiplayer requires a configured MySQL database.\n";
echo "Press Ctrl+C to stop.\n";

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $handler = function () use ($proc) {
        if (is_resource($proc)) { @proc_terminate($proc); }
        exit(0);
    };
    pcntl_signal(SIGINT, $handler);
    pcntl_signal(SIGTERM, $handler);
}

$code = proc_close($proc);
exit(is_int($code) ? $code : 0);

