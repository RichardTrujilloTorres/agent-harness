<?php

declare(strict_types=1);

use Desemax\AgentHarness\Config\ConfigLoader;
use Desemax\AgentHarness\Config\HarnessConfig;
use Desemax\AgentHarness\Config\InvalidConfigException;
use Desemax\AgentHarness\Scenario\ValidationError;

/**
 * @return list<string>
 */
function configErrors(mixed $data): array
{
    try {
        (new ConfigLoader())->fromArray($data, 'harness.yaml');
    } catch (InvalidConfigException $e) {
        return array_map(static fn (ValidationError $err): string => $err->path . ': ' . $err->reason, $e->errors);
    }

    return [];
}

it('uses the defaults for an empty config', function (): void {
    $config = (new ConfigLoader())->fromArray([]);

    expect($config->url)->toBeNull()
        ->and($config->timeoutMs)->toBe(HarnessConfig::DEFAULT_TIMEOUT_MS)
        ->and($config->headers)->toBe([])
        ->and($config->sessionField)->toBe('session_id')
        ->and($config->messageField)->toBe('message')
        ->and($config->replyPath)->toBe('reply')
        ->and($config->toolCallsPath)->toBe('tool_calls')
        ->and($config->toolNamePath)->toBe('name')
        ->and($config->toolArgumentsPath)->toBe('arguments')
        ->and($config->handoffPath)->toBe('handoff');
});

it('reads every key', function (): void {
    $config = (new ConfigLoader())->fromArray([
        'agent' => ['url' => 'http://localhost:9000/chat', 'timeout_ms' => 2500, 'headers' => ['Authorization' => 'Bearer x']],
        'request' => ['session_field' => 'conversation.id', 'message_field' => 'input'],
        'response' => [
            'reply' => 'data.output.text',
            'tool_calls' => 'data.actions',
            'tool_name' => 'tool',
            'tool_arguments' => 'params',
            'handoff' => 'data.escalate',
        ],
    ]);

    expect($config->url)->toBe('http://localhost:9000/chat')
        ->and($config->timeoutMs)->toBe(2500)
        ->and($config->headers)->toBe(['Authorization' => 'Bearer x'])
        ->and($config->sessionField)->toBe('conversation.id')
        ->and($config->replyPath)->toBe('data.output.text')
        ->and($config->handoffPath)->toBe('data.escalate')
        ->and($config->withUrl('http://other/chat')->url)->toBe('http://other/chat')
        ->and($config->withUrl('http://other/chat')->replyPath)->toBe('data.output.text');
});

it('collects every error with field paths', function (): void {
    expect(configErrors([
        'agent' => ['url' => 'localhost', 'timeout_ms' => 0, 'headers' => ['X' => ['a']], 'retries' => 3],
        'request' => 'nope',
        'response' => ['reply' => 'a..b'],
        'extra' => true,
    ]))->toBe([
        'extra: unknown field; allowed: agent, request, response',
        'agent.retries: unknown field; allowed: url, timeout_ms, headers',
        'request: must be a map',
        'agent.url: must start with http:// or https://',
        'agent.timeout_ms: must be >= 1',
        'agent.headers.X: must be a string',
        'response.reply: must be a dot-path like "data.reply", got "a..b"',
    ]);
});

it('rejects a non-map config and a non-integer timeout', function (): void {
    expect(configErrors(['a', 'b']))->toBe(['(root): config must be a map'])
        ->and(configErrors(['agent' => ['timeout_ms' => '100']]))->toBe(['agent.timeout_ms: must be an integer'])
        ->and(configErrors(['request' => ['session_field' => 'x', 'message_field' => 'x']]))->toBe(['request.message_field: must differ from request.session_field']);
});

it('loads a file and reports missing files and invalid YAML', function (): void {
    $dir = sys_get_temp_dir() . '/harness-config-' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("{$dir}/ok.yaml", "agent:\n  url: http://127.0.0.1:8787/chat\n");
    file_put_contents("{$dir}/bad.yaml", "agent: [unclosed\n");

    $loader = new ConfigLoader();
    expect($loader->load("{$dir}/ok.yaml")->url)->toBe('http://127.0.0.1:8787/chat')
        ->and(fn () => $loader->load("{$dir}/missing.yaml"))->toThrow(InvalidArgumentException::class, 'Config file does not exist')
        ->and(fn () => $loader->load("{$dir}/bad.yaml"))->toThrow(InvalidConfigException::class, 'invalid YAML');
});
