<?php

declare(strict_types=1);

use Desemax\AgentHarness\Agent\AgentException;
use Desemax\AgentHarness\Agent\AgentReply;
use Desemax\AgentHarness\Agent\HttpAgentAdapter;
use Desemax\AgentHarness\Agent\ToolCall;
use Desemax\AgentHarness\Config\ConfigLoader;
use Desemax\AgentHarness\Config\HarnessConfig;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @param array<string, mixed> $config
 */
function adapterFor(MockResponse|Closure $response, array $config = []): HttpAgentAdapter
{
    $config = (new ConfigLoader())->fromArray($config + ['agent' => ['url' => 'http://agent.test/chat']]);

    return new HttpAgentAdapter($config, new MockHttpClient($response));
}

it('maps the default contract', function (): void {
    $response = new MockResponse((string) json_encode([
        'reply' => 'Booked.',
        'tool_calls' => [['name' => 'create_booking', 'arguments' => ['party_size' => 2]]],
        'handoff' => false,
    ]));

    $reply = adapterFor($response)->send('s-1', 'Book for 2');

    expect($reply->reply)->toBe('Booked.')
        ->and($reply->toolCalls)->toEqual([new ToolCall('create_booking', ['party_size' => 2])])
        ->and($reply->handoff)->toBeFalse()
        ->and($reply->latencyMs)->toBeGreaterThanOrEqual(0)
        ->and($reply->raw['reply'])->toBe('Booked.')
        ->and($response->getRequestMethod())->toBe('POST')
        ->and($response->getRequestUrl())->toBe('http://agent.test/chat')
        ->and(requestJson($response))->toBe(['session_id' => 's-1', 'message' => 'Book for 2']);
});

it('treats missing tool_calls and handoff as none and false', function (): void {
    $reply = adapterFor(new MockResponse('{"reply": "Hi"}'))->send('s', 'hello');

    expect($reply->toolCalls)->toBe([])->and($reply->handoff)->toBeFalse();
});

it('maps remapped nested fields and sends configured headers', function (): void {
    $response = new MockResponse((string) json_encode([
        'data' => [
            'output' => ['text' => 'Passing you on.'],
            'actions' => [['tool' => 'lookup', 'params' => '{"id": 7}']],
            'escalate' => true,
        ],
    ]));
    $adapter = adapterFor($response, [
        'agent' => ['url' => 'http://agent.test/v2', 'headers' => ['X-Api-Key' => 'k']],
        'request' => ['session_field' => 'conversation.id', 'message_field' => 'input.text'],
        'response' => [
            'reply' => 'data.output.text',
            'tool_calls' => 'data.actions',
            'tool_name' => 'tool',
            'tool_arguments' => 'params',
            'handoff' => 'data.escalate',
        ],
    ]);

    $reply = $adapter->send('abc', 'help');

    expect($reply->reply)->toBe('Passing you on.')
        ->and($reply->toolCalls)->toEqual([new ToolCall('lookup', ['id' => 7])])
        ->and($reply->handoff)->toBeTrue()
        ->and(requestJson($response))->toBe(['conversation' => ['id' => 'abc'], 'input' => ['text' => 'help']])
        ->and($response->getRequestOptions()['headers'])->toContain('X-Api-Key: k');
});

it('raises a readable AgentException', function (MockResponse $response, string $message): void {
    expect(fn(): AgentReply => adapterFor($response)->send('s', 'hi'))->toThrow(AgentException::class, $message);
})->with([
    'non-2xx' => [new MockResponse('{"error": "boom"}', ['http_code' => 500]), 'Agent at http://agent.test/chat answered HTTP 500: {"error": "boom"}'],
    'invalid JSON' => [new MockResponse('<html>oops</html>'), 'answered invalid JSON (Syntax error): <html>oops</html>'],
    'JSON not an object' => [new MockResponse('"just a string"'), 'answered JSON that is not an object'],
    'missing reply' => [new MockResponse('{"text": "hi"}'), 'Agent response has no "reply" field (response.reply): {"text":"hi"}'],
    'reply not a string' => [new MockResponse('{"reply": {"a": 1}}'), 'Agent response field "reply" must be a string, got array'],
    'tool_calls not a list' => [new MockResponse('{"reply": "x", "tool_calls": {"name": "a"}}'), 'Agent response field "tool_calls" must be a list of tool calls'],
    'tool call without name' => [new MockResponse('{"reply": "x", "tool_calls": [{"arguments": {}}]}'), 'Agent response field "tool_calls[0].name" must be a non-empty string'],
    'handoff not a bool' => [new MockResponse('{"reply": "x", "handoff": "yes"}'), 'Agent response field "handoff" must be a boolean, got string'],
]);

it('raises an AgentException on timeout', function (): void {
    // An empty chunk makes MockResponse simulate a timeout.
    $response = new MockResponse((static function (): Generator {
        yield '';
    })());
    $adapter = adapterFor($response, ['agent' => ['url' => 'http://agent.test/chat', 'timeout_ms' => 50]]);

    expect(fn(): AgentReply => $adapter->send('s', 'hi'))->toThrow(AgentException::class, 'Agent at http://agent.test/chat timed out after 50 ms');
});

it('raises an AgentException when the agent is unreachable', function (): void {
    $response = new MockResponse('', ['error' => 'Could not connect to server']);

    expect(fn(): AgentReply => adapterFor($response)->send('s', 'hi'))->toThrow(AgentException::class, 'Agent at http://agent.test/chat is unreachable: Could not connect to server');
});

it('requires a URL', function (): void {
    expect(fn(): HttpAgentAdapter => new HttpAgentAdapter(new HarnessConfig()))->toThrow(InvalidArgumentException::class, 'No agent URL configured');
});
