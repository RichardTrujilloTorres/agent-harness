<?php

declare(strict_types=1);

use Desemax\AgentHarness\Scenario\ScenarioLoader;
use Desemax\AgentHarness\Tests\Support\AgentServer;
use Symfony\Component\HttpClient\HttpClient;

beforeEach(function (): void {
    $this->server = AgentServer::start(projectRoot() . '/examples/demo-agent/public');
    $this->baseUrl = $this->server->url();
});

afterEach(function (): void {
    $this->server->stop();
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
