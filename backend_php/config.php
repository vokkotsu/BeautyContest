<?php

// config.php - Game configuration constants

const STARTING_SCORE = 0;
const LOSS_PENALTY = 1;
const CRITICAL_PENALTY = 2;
const ELIMINATION_THRESHOLD = -10;

const PICK_COUNTDOWN = 60;
const READY_COUNTDOWN = 15;

// Database configuration (override via environment variables for deployment)
function db_config(): array
{
    return [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'beautycontest',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
        'charset' => 'utf8mb4',
    ];
}
