<?php

declare(strict_types=1);

use Desemax\AgentHarness\Scenario\ScenarioLoader;
use Desemax\AgentHarness\Tests\Support\AgentServer;
use Symfony\Component\HttpClient\HttpClient;

beforeEach(function (): void {
    AgentServer::startShared(projectRoot() . '/examples/demo-agent/public');
});

afterEach(function (): void {
    AgentServer::stopShared();
});

/**
 * @return array{status: int, body: array<string, mixed>}
 */
function chat(string $baseUrl, string $sessionId, string $message, string $method = 'POST', string $path = '/chat'): array
{
    $response = HttpClient::create()->request($method, $baseUrl . $path, [
        'json' => ['session_id' => $sessionId, 'message' => $message],
    ]);

    $body = [];
    foreach ($response->toArray(false) as $key => $value) {
        if (!is_string($key)) {
            throw new UnexpectedValueException('The agent answered a JSON list instead of an object.');
        }
        $body[$key] = $value;
    }

    return ['status' => $response->getStatusCode(), 'body' => $body];
}

it('books a table when given the happy-booking scenario turns', function (): void {
    $scenario = (new ScenarioLoader())->load(projectRoot() . '/examples/scenarios/happy-booking.yaml')[0];
    $session = 'test-' . bin2hex(random_bytes(8));

    $responses = [];
    foreach ($scenario->user->turns as $turn) {
        $responses[] = chat(AgentServer::shared()->url(), $session, $turn);
    }

    expect($responses)->not->toBeEmpty();
    $last = $responses[count($responses) - 1];
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

it('returns 404 JSON for a path other than /chat', function (): void {
    $r = chat(AgentServer::shared()->url(), 's', 'hi', 'POST', '/other');

    expect($r['status'])->toBe(404)
        ->and($r['body'])->toHaveKey('error');
});

/**
 * @param array<string, string> $headers
 * @return array{status: int, headers: array<string, list<string>>, body: string}
 */
function rawRequest(string $method, string $body, array $headers = []): array
{
    $response = HttpClient::create()->request($method, AgentServer::shared()->url('/chat'), ['body' => $body, 'headers' => $headers]);

    return ['status' => $response->getStatusCode(), 'headers' => $response->getHeaders(false), 'body' => $response->getContent(false)];
}

it('answers 405 with an Allow header for methods other than POST', function (): void {
    $r = rawRequest('GET', '');

    expect($r['status'])->toBe(405)
        ->and($r['headers']['allow'] ?? [])->toBe(['POST']);
});

it('answers 415 when the body is not declared as JSON', function (string $contentType): void {
    $r = rawRequest('POST', '{"session_id": "s", "message": "hi"}', ['Content-Type' => $contentType]);

    expect($r['status'])->toBe(415);
})->with(['text/plain', 'application/x-www-form-urlencoded', 'application/jsonp']);

it('accepts application/json with a charset parameter', function (): void {
    $r = rawRequest('POST', '{"session_id": "s", "message": "hi"}', ['Content-Type' => 'Application/JSON; charset=utf-8']);

    expect($r['status'])->toBe(200);
});

it('answers 413 for a body larger than 16 KiB', function (): void {
    $big = json_encode(['session_id' => 's', 'message' => str_repeat('a', 16_384)], JSON_THROW_ON_ERROR);
    $r = rawRequest('POST', $big, ['Content-Type' => 'application/json']);

    expect($r['status'])->toBe(413);
});

it('accepts a body of exactly 16 KiB', function (): void {
    $prefix = '{"session_id": "s", "message": "';
    $suffix = '"}';
    $body = $prefix . str_repeat('a', 16_384 - strlen($prefix) - strlen($suffix)) . $suffix;
    expect(strlen($body))->toBe(16_384);

    expect(rawRequest('POST', $body, ['Content-Type' => 'application/json'])['status'])->toBe(200);
});

it('escapes HTML characters in JSON and sends nosniff', function (): void {
    $r = rawRequest('POST', '{"session_id": "s", "message": "<script>alert(1)</script> book under O\'Hara & co"}', ['Content-Type' => 'application/json']);

    expect($r['body'])->not->toContain('<')
        ->and($r['body'])->not->toContain('>')
        ->and($r['headers']['x-content-type-options'] ?? [])->toBe(['nosniff']);
});

it('stores session state under a hash of the session id, never the raw id', function (): void {
    $session = '../../escape-' . bin2hex(random_bytes(4));
    chat(AgentServer::shared()->url(), $session, 'table for 2');

    $expected = sys_get_temp_dir() . '/trattoria-demo-' . hash('sha256', $session) . '.json';
    expect(is_file($expected))->toBeTrue();
    unlink($expected);
});
