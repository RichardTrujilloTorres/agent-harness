<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Agent;

use Desemax\AgentHarness\Config\HarnessConfig;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to an agent over HTTP: POST JSON, read the reply with the configured dot-paths.
 */
final class HttpAgentAdapter implements AgentAdapter
{
    private readonly string $url;

    private readonly HttpClientInterface $client;

    /**
     * @throws InvalidArgumentException when the config has no agent URL
     */
    public function __construct(
        private readonly HarnessConfig $config,
        ?HttpClientInterface $client = null,
    ) {
        if ($config->url === null) {
            throw new InvalidArgumentException('No agent URL configured: set agent.url in the config or pass --url.');
        }
        $this->url = $config->url;
        $this->client = $client ?? HttpClient::create();
    }

    public function send(string $sessionId, string $message): AgentReply
    {
        $payload = DotPath::set([], $this->config->sessionField, $sessionId);
        $payload = DotPath::set($payload, $this->config->messageField, $message);
        $seconds = $this->config->timeoutMs / 1000;

        $start = hrtime(true);
        try {
            $response = $this->client->request('POST', $this->url, [
                'json' => $payload,
                'headers' => ['Accept' => 'application/json'] + $this->config->headers,
                'timeout' => $seconds,
                'max_duration' => $seconds,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            if (stripos($e->getMessage(), 'timeout') !== false || stripos($e->getMessage(), 'duration') !== false) {
                throw new AgentException(sprintf('Agent at %s timed out after %d ms', $this->url, $this->config->timeoutMs), 0, $e);
            }

            throw new AgentException(sprintf('Agent at %s is unreachable: %s', $this->url, $e->getMessage()), 0, $e);
        }
        $latencyMs = (int) round((hrtime(true) - $start) / 1_000_000);

        if ($status < 200 || $status >= 300) {
            throw new AgentException(sprintf('Agent at %s answered HTTP %d: %s', $this->url, $status, self::excerpt($body)));
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new AgentException(sprintf('Agent at %s answered invalid JSON (%s): %s', $this->url, $e->getMessage(), self::excerpt($body)), 0, $e);
        }
        if (!is_array($decoded)) {
            throw new AgentException(sprintf('Agent at %s answered JSON that is not an object: %s', $this->url, self::excerpt($body)));
        }

        return new AgentReply(
            reply: $this->reply($decoded),
            toolCalls: $this->toolCalls($decoded),
            handoff: $this->handoff($decoded),
            latencyMs: $latencyMs,
            raw: $decoded,
        );
    }

    /**
     * @param array<mixed> $body
     */
    private function reply(array $body): string
    {
        [$found, $value] = DotPath::get($body, $this->config->replyPath);
        if (!$found) {
            throw new AgentException(sprintf('Agent response has no "%s" field (response.reply): %s', $this->config->replyPath, self::excerpt(self::encode($body))));
        }
        if (!is_string($value) && !is_int($value) && !is_float($value) && $value !== null) {
            throw new AgentException(sprintf('Agent response field "%s" must be a string, got %s', $this->config->replyPath, get_debug_type($value)));
        }

        return (string) $value;
    }

    /**
     * @param array<mixed> $body
     * @return list<ToolCall>
     */
    private function toolCalls(array $body): array
    {
        [$found, $value] = DotPath::get($body, $this->config->toolCallsPath);
        if (!$found || $value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new AgentException(sprintf('Agent response field "%s" must be a list of tool calls', $this->config->toolCallsPath));
        }

        $calls = [];
        foreach ($value as $i => $item) {
            $where = sprintf('%s[%d]', $this->config->toolCallsPath, $i);
            if (!is_array($item)) {
                throw new AgentException(sprintf('Agent response field "%s" must be an object', $where));
            }
            [$hasName, $name] = DotPath::get($item, $this->config->toolNamePath);
            if (!$hasName || !is_string($name) || $name === '') {
                throw new AgentException(sprintf('Agent response field "%s.%s" must be a non-empty string', $where, $this->config->toolNamePath));
            }
            [, $arguments] = DotPath::get($item, $this->config->toolArgumentsPath);
            $calls[] = new ToolCall($name, $this->arguments($arguments, $where));
        }

        return $calls;
    }

    /**
     * Arguments may be an object or a JSON-encoded object string.
     *
     * @return array<string, mixed>
     */
    private function arguments(mixed $value, string $where): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            throw new AgentException(sprintf('Agent response field "%s.%s" must be an object', $where, $this->config->toolArgumentsPath));
        }

        $args = [];
        foreach ($value as $k => $v) {
            $args[(string) $k] = $v;
        }

        return $args;
    }

    /**
     * @param array<mixed> $body
     */
    private function handoff(array $body): bool
    {
        [$found, $value] = DotPath::get($body, $this->config->handoffPath);
        if (!$found || $value === null) {
            return false;
        }
        if (!is_bool($value)) {
            throw new AgentException(sprintf('Agent response field "%s" must be a boolean, got %s', $this->config->handoffPath, get_debug_type($value)));
        }

        return $value;
    }

    private static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function excerpt(string $text, int $max = 200): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if ($text === '') {
            return '(empty body)';
        }

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '...' : $text;
    }
}
