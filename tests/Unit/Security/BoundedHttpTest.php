<?php

declare(strict_types=1);

use Desemax\AgentHarness\Agent\AgentException;
use Desemax\AgentHarness\Agent\HttpAgentAdapter;
use Desemax\AgentHarness\Config\ConfigLoader;
use Desemax\AgentHarness\Config\HarnessConfig;
use Desemax\AgentHarness\Llm\LlmConfig;
use Desemax\AgentHarness\Llm\LlmException;
use Desemax\AgentHarness\Llm\LlmOptions;
use Desemax\AgentHarness\Llm\OpenAiCompatibleClient;
use Desemax\AgentHarness\Security\BodyTooLargeException;
use Desemax\AgentHarness\Security\BoundedHttp;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @param array<string, mixed> $agent
 */
function boundedAdapter(MockResponse $response, array $agent = []): HttpAgentAdapter
{
    return new HttpAgentAdapter((new ConfigLoader())->fromArray(['agent' => $agent + ['url' => 'https://agent.test/chat']]), new MockHttpClient($response));
}

function boundedLlm(MockResponse $response, int $maxBytes = BoundedHttp::DEFAULT_MAX_RESPONSE_BYTES): OpenAiCompatibleClient
{
    return new OpenAiCompatibleClient(new LlmConfig('https://llm.test/v1', 'm', maxResponseBytes: $maxBytes), 'sk-secret-123', new MockHttpClient($response));
}

it('always verifies TLS and does not follow redirects by default', function (): void {
    $response = new MockResponse('{"reply": "ok"}');
    boundedAdapter($response)->send('s', 'hi');
    $options = $response->getRequestOptions();

    expect($options['verify_peer'])->toBeTrue()
        ->and($options['verify_host'])->toBeTrue()
        ->and($options['max_redirects'])->toBe(0)
        ->and($options['timeout'])->toBe(10.0)
        ->and($options['max_duration'])->toBe(10.0);
});

it('follows up to 5 redirects only when agent.follow_redirects is true', function (): void {
    $response = new MockResponse('{"reply": "ok"}');
    boundedAdapter($response, ['follow_redirects' => true, 'timeout_ms' => 2500])->send('s', 'hi');

    expect($response->getRequestOptions()['max_redirects'])->toBe(BoundedHttp::MAX_REDIRECTS)
        ->and(BoundedHttp::MAX_REDIRECTS)->toBe(5)
        ->and($response->getRequestOptions()['timeout'])->toBe(2.5);
});

it('explains an unfollowed redirect from the agent', function (): void {
    expect(fn(): mixed => boundedAdapter(new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://elsewhere.test/']]))->send('s', 'hi'))
        ->toThrow(AgentException::class, 'Agent at https://agent.test/chat answered a redirect (HTTP 302); redirects are not followed unless agent.follow_redirects is true');
});

it('caps the agent response body by Content-Length', function (): void {
    $response = new MockResponse(str_repeat('a', 11), ['response_headers' => ['Content-Length: 11']]);

    expect(fn(): mixed => boundedAdapter($response, ['max_response_bytes' => 10])->send('s', 'hi'))
        ->toThrow(AgentException::class, 'Agent at https://agent.test/chat answered a response body is larger than 10 bytes; raise agent.max_response_bytes if this is expected');
});

it('caps a streamed agent response body without Content-Length', function (): void {
    $response = new MockResponse(['{"reply": "', str_repeat('a', 6), '"}']);

    expect(fn(): mixed => boundedAdapter($response, ['max_response_bytes' => 15])->send('s', 'hi'))
        ->toThrow(AgentException::class, 'larger than 15 bytes');
});

it('accepts a body of exactly the cap', function (): void {
    $body = '{"reply": "ab"}';
    $reply = boundedAdapter(new MockResponse([$body]), ['max_response_bytes' => strlen($body)])->send('s', 'hi');

    expect($reply->reply)->toBe('ab');
});

it('defaults the body cap to 1 MiB for agent and LLM', function (): void {
    expect(BoundedHttp::DEFAULT_MAX_RESPONSE_BYTES)->toBe(1_048_576)
        ->and((new HarnessConfig())->maxResponseBytes)->toBe(1_048_576)
        ->and((new LlmConfig('https://x.test', 'm'))->maxResponseBytes)->toBe(1_048_576)
        ->and((new ConfigLoader())->fromArray(['llm' => ['base_url' => 'https://x.test', 'model' => 'm', 'max_response_bytes' => 99]])->llm?->maxResponseBytes)->toBe(99);
});

it('applies the same limits to the LLM client and never follows its redirects', function (): void {
    $response = new MockResponse('{"choices": [{"message": {"content": "hi"}}]}');
    boundedLlm($response)->complete('s', [], new LlmOptions());
    $options = $response->getRequestOptions();

    expect($options['verify_peer'])->toBeTrue()
        ->and($options['verify_host'])->toBeTrue()
        ->and($options['max_redirects'])->toBe(0)
        ->and(fn(): string => boundedLlm(new MockResponse('', ['http_code' => 307]))->complete('s', [], new LlmOptions()))
        ->toThrow(LlmException::class, 'answered a redirect (HTTP 307); redirects are never followed for the LLM')
        ->and(fn(): string => boundedLlm(new MockResponse([str_repeat('x', 20)]), 10)->complete('s', [], new LlmOptions()))
        ->toThrow(LlmException::class, 'LLM at https://llm.test/v1/chat/completions answered a response body is larger than 10 bytes; raise llm.max_response_bytes');
});

it('never puts the API key in an LLM error, even when the server echoes it', function (): void {
    $client = boundedLlm(new MockResponse('{"error": "bad key sk-secret-123"}', ['http_code' => 401]));

    expect(fn(): string => $client->complete('s', [], new LlmOptions()))
        ->toThrow(LlmException::class, 'LLM at https://llm.test/v1/chat/completions answered HTTP 401: {"error": "bad key [REDACTED]"}');
});

it('accepts only http and https URLs with a host', function (string $url, bool $ok): void {
    expect(BoundedHttp::isHttpUrl($url))->toBe($ok);
})->with([
    ['http://a.test/chat', true],
    ['HTTPS://a.test', true],
    ['https://127.0.0.1:8080/x', true],
    ['file:///etc/passwd', false],
    ['ftp://a.test/x', false],
    ['gopher://a.test', false],
    ['http://', false],
    ['localhost:8787', false],
    ['//a.test/x', false],
    ['', false],
]);

it('refuses a non-http URL at request time too', function (): void {
    $http = new BoundedHttp(new MockHttpClient());

    expect(fn(): array => $http->postJson('file:///etc/passwd', [], [], 1000, 10))
        ->toThrow(InvalidArgumentException::class, 'Only http:// and https:// URLs are allowed, got "file:///etc/passwd".');
});

it('rejects an agent config URL without an http scheme or host', function (): void {
    expect(fn(): HttpAgentAdapter => new HttpAgentAdapter(new HarnessConfig(url: 'file:///etc/passwd')))
        ->toThrow(InvalidArgumentException::class, 'Agent URL must be an http:// or https:// URL with a host, got "file:///etc/passwd".');
});

it('reports the cap in the exception', function (): void {
    $e = new BodyTooLargeException(42);

    expect($e->maxBytes)->toBe(42)->and($e->getMessage())->toBe('response body is larger than 42 bytes');
});
