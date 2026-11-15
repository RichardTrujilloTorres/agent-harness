<?php

/**
 * Starts the demo agent on a free local port, runs the example scenarios with --format=json and
 * checks the expected outcome: 4 passed, 2 failed, exit code 1. Exits 0 when that holds.
 *
 * Usage: php scripts/demo-check.php [path/to/agent-harness or .phar]
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$binary = $argv[1] ?? $root . '/bin/agent-harness';

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($socket === false) {
    fwrite(STDERR, "Cannot find a free port: {$errstr}\n");
    exit(2);
}
$port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
fclose($socket);

$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root . '/examples/demo-agent/public'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
);
if (!is_resource($server)) {
    fwrite(STDERR, "Cannot start the demo agent.\n");
    exit(2);
}

$deadline = microtime(true) + 5;
do {
    $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
    if ($conn === false) {
        usleep(50_000);
    }
} while ($conn === false && microtime(true) < $deadline);
if ($conn !== false) {
    fclose($conn);
}

$run = proc_open(
    [PHP_BINARY, $binary, 'run', $root . '/examples/scenarios', '--url', "http://127.0.0.1:{$port}/chat", '--format', 'json'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $runPipes,
);
$json = is_resource($run) ? (string) stream_get_contents($runPipes[1]) : '';
$exit = is_resource($run) ? proc_close($run) : -1;

proc_terminate($server);
proc_close($server);

$data = json_decode($json, true);
$summary = is_array($data) && is_array($data['summary'] ?? null) ? $data['summary'] : [];
$passed = $summary['passed'] ?? null;
$failed = $summary['failed'] ?? null;

printf("Demo run: %s passed, %s failed, exit code %d (expected 4, 2, 1)\n", var_export($passed, true), var_export($failed, true), $exit);
exit($passed === 4 && $failed === 2 && $exit === 1 ? 0 : 1);
