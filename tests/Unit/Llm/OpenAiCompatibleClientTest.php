<?php

declare(strict_types=1);

use Desemax\AgentHarness\Llm\LlmConfig;
use Desemax\AgentHarness\Llm\LlmException;
use Desemax\AgentHarness\Llm\LlmOptions;
use Desemax\AgentHarness\Llm\OpenAiCompatibleClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function llmClientFor(MockResponse|Closure $response, ?int $maxTokens = null): OpenAiCompatibleClient
{
    return new OpenAiCompatibleClient(
        new LlmConfig('http://llm.test/v1/', 'test-model', maxTokens: $maxTokens),
        'secret-key',
        new MockHttpClient($response),
    );
}

function completion(string $content): MockResponse
{
    return new MockResponse((string) json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]]));
}

it('posts a chat completion request and returns the content', function (): void {
    $response = completion('Hello there');

    $text = llmClientFor($response)->complete('Be brief.', [['role' => 'user', 'content' => 'Hi']], new LlmOptions());

    $headers = $response->getRequestOptions()['headers'];
    expect($text)->toBe('Hello there')
        ->and($response->getRequestMethod())->toBe('POST')
        ->and($response->getRequestUrl())->toBe('http://llm.test/v1/chat/completions')
        ->and($headers)->toContain('Authorization: Bearer secret-key')
        ->and(json_decode((string) $response->getRequestOptions()['body'], true))->toBe([
            'model' => 'test-model',
            'messages' => [['role' => 'system', 'content' => 'Be brief.'], ['role' => 'user', 'content' => 'Hi']],
            'temperature' => 0.0,
        ]);
});

it('sends max_tokens, seed and json mode when set', function (): void {
    $response = completion('{}');

    llmClientFor($response, 300)->complete('s', [['role' => 'user', 'content' => 'x']], new LlmOptions(temperature: 0.7, seed: 42, jsonMode: true));
    $body = json_decode((string) $response->getRequestOptions()['body'], true);

    expect($body['temperature'])->toBe(0.7)
        ->and($body['max_tokens'])->toBe(300)
        ->and($body['seed'])->toBe(42)
        ->and($body['response_format'])->toBe(['type' => 'json_object']);
});

it('prefers max tokens from the options over the config', function (): void {
    $response = completion('ok');

    llmClientFor($response, 300)->complete('s', [], new LlmOptions(maxTokens: 50));

    expect(json_decode((string) $response->getRequestOptions()['body'], true)['max_tokens'])->toBe(50);
});

it('throws a typed exception for errors', function (MockResponse|Closure $response, string $message): void {
    expect(fn () => llmClientFor($response)->complete('s', [], new LlmOptions()))
        ->toThrow(LlmException::class, $message);
})->with([
    'HTTP error' => [new MockResponse('{"error":"rate limited"}', ['http_code' => 429]), 'answered HTTP 429: {"error":"rate limited"}'],
    'invalid JSON' => [new MockResponse('<html>oops'), 'answered invalid JSON'],
    'no content' => [new MockResponse('{"choices":[]}'), 'answered without choices[0].message.content'],
    'unreachable' => [new MockResponse('', ['error' => 'Connection refused']), 'is unreachable: Connection refused'],
]);

it('reads the API key from the named environment variable', function (): void {
    putenv('HARNESS_TEST_LLM_KEY=from-env');
    $response = completion('ok');

    try {
        OpenAiCompatibleClient::fromConfig(new LlmConfig('http://llm.test/v1', 'm', 'HARNESS_TEST_LLM_KEY'), new MockHttpClient($response))
            ->complete('s', [], new LlmOptions());
    } finally {
        putenv('HARNESS_TEST_LLM_KEY');
    }

    expect($response->getRequestOptions()['headers'])->toContain('Authorization: Bearer from-env');
});

it('explains a missing API key environment variable', function (): void {
    putenv('HARNESS_TEST_MISSING_KEY');

    expect(fn () => OpenAiCompatibleClient::fromConfig(new LlmConfig('http://llm.test/v1', 'm', 'HARNESS_TEST_MISSING_KEY')))
        ->toThrow(LlmException::class, 'Environment variable HARNESS_TEST_MISSING_KEY is not set.');
});
