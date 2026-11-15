<?php

declare(strict_types=1);

// Answers "wrong" on the first request and "right" afterwards, to exercise --retries.
$port = $_SERVER['SERVER_PORT'] ?? '0';
$counter = sys_get_temp_dir() . '/agent-harness-flaky-' . (is_string($port) || is_int($port) ? (string) $port : '0');
$count = is_file($counter) ? (int) file_get_contents($counter) : 0;
file_put_contents($counter, (string) ($count + 1));

header('Content-Type: application/json');
echo json_encode(['reply' => $count === 0 ? 'wrong' : 'right']);
