<?php

declare(strict_types=1);

use Desemax\AgentHarness\Scenario\ScenarioLoader;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Starts the bundled demo agent with the PHP built-in server on a free port.
 */
beforeEach(function (): void {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        throw new RuntimeException("Cannot find a free port: {$errstr}");
    }
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    $this->server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', projectRoot() . '/examples/demo-agent/public'],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );
    $this->baseUrl = "http://127.0.0.1:{$port}";

    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        set_error_handler(static fn (): bool => true);
        try {
            $conn = fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        } finally {
            restore_error_handler();
        }
        if ($conn !== false) {
            fclose($conn);

            return;
        }
        usleep(50_000);
    }

    throw new RuntimeException('Demo agent did not start within 5 seconds.');
});

afterEach(function (): void {
    if (isset($this->server) && is_resource($this->server)) {
        proc_terminate($this->server);
        proc_close($this->server);
    }
});

/**
 * @return array{status: int, body: array<string, mixed>}
 */
function chat(string $baseUrl, string $sessionId, string $message, string $method = 'POST', string $path = '/chat'): array
{
    $response = HttpClient::create()->request($method, $baseUrl . $path, [
        'json' => ['session_id' => $sessionId, 'message' => $message],
    ]);

    return ['status' => $response->getStatusCode(), 'body' => $response->toArray(false)];
}

it('books a table when given the happy-booking scenario turns', function (): void {
    $scenario = (new ScenarioLoader())->load(projectRoot() . '/examples/scenarios/happy-booking.yaml')[0];
    $session = 'test-' . bin2hex(random_bytes(8));

    $responses = [];
    foreach ($scenario->user->turns as $turn) {
        $responses[] = chat($this->baseUrl, $session, $turn);
    }

    $last = end($responses);
    expect($last['status'])->toBe(200)
        ->and($last['body']['reply'])->toContain('confirmed')
        ->and($last['body']['handoff'])->toBeFalse()
        ->and($last['body']['tool_calls'])->toBe([[
            'name' => 'create_booking',
            'arguments' => ['party_size' => 4, 'day' => 'saturday', 'time' => '20:00', 'name' => 'Anna'],
        ]]);

    foreach (array_slice($responses, 0, -1) as $r) {
        expect($r['body']['tool_calls'])->toBe([]);
    }
});

it('returns 404 JSON for anything other than POST /chat', function (string $method, string $path): void {
    $r = chat($this->baseUrl, 's', 'hi', $method, $path);

    expect($r['status'])->toBe(404)
        ->and($r['body'])->toHaveKey('error');
})->with([
    ['GET', '/chat'],
    ['POST', '/other'],
]);
