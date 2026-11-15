<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Llm;

use Desemax\AgentHarness\Security\BodyTooLargeException;
use Desemax\AgentHarness\Security\BoundedHttp;
use JsonException;
use Override;
use SensitiveParameter;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Any server that speaks the OpenAI chat completions API: POST {base_url}/chat/completions.
 */
final readonly class OpenAiCompatibleClient implements LlmClient
{
    private HttpClientInterface $client;

    public function __construct(
        private LlmConfig $config,
        #[SensitiveParameter]
        private string $apiKey,
        ?HttpClientInterface $client = null,
    ) {
        $this->client = $client ?? HttpClient::create();
    }

    /**
     * Reads the API key from the environment variable named in the config.
     *
     * @throws LlmException when the variable is not set or empty
     */
    public static function fromConfig(LlmConfig $config, ?HttpClientInterface $client = null): self
    {
        $key = getenv($config->apiKeyEnv);
        if (!is_string($key) || trim($key) === '') {
            throw new LlmException(sprintf(
                'Environment variable %s is not set. Export your LLM API key in it, or name another variable in llm.api_key_env.',
                $config->apiKeyEnv,
            ));
        }

        return new self($config, trim($key), $client);
    }

    #[Override]
    public function complete(string $system, array $messages, LlmOptions $options): string
    {
        $url = rtrim($this->config->baseUrl, '/') . '/chat/completions';
        $payload = [
            'model' => $this->config->model,
            'messages' => [['role' => 'system', 'content' => $system], ...$messages],
            'temperature' => $options->temperature,
        ];
        $maxTokens = $options->maxTokens ?? $this->config->maxTokens;
        if ($maxTokens !== null) {
            $payload['max_tokens'] = $maxTokens;
        }
        if ($options->seed !== null) {
            $payload['seed'] = $options->seed;
        }
        if ($options->jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        try {
            ['status' => $status, 'body' => $body] = (new BoundedHttp($this->client))->postJson(
                $url,
                $payload,
                ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $this->apiKey],
                $this->config->timeoutMs,
                $this->config->maxResponseBytes,
            );
        } catch (BodyTooLargeException $e) {
            throw new LlmException(sprintf('LLM at %s answered a %s; raise llm.max_response_bytes if this is expected', $url, $e->getMessage()), 0, $e);
        } catch (TransportExceptionInterface $e) {
            throw new LlmException($this->redact(sprintf('LLM at %s is unreachable: %s', $url, $e->getMessage())), 0, $e);
        }
        $body = $this->redact($body);

        if ($status >= 300 && $status < 400) {
            throw new LlmException(sprintf('LLM at %s answered a redirect (HTTP %d); redirects are never followed for the LLM, set llm.base_url to the final address', $url, $status));
        }
        if ($status < 200 || $status >= 300) {
            throw new LlmException(sprintf('LLM at %s answered HTTP %d: %s', $url, $status, self::excerpt($body)));
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LlmException(sprintf('LLM at %s answered invalid JSON (%s): %s', $url, $e->getMessage(), self::excerpt($body)), 0, $e);
        }

        $content = is_array($decoded) ? $this->content($decoded) : null;
        if ($content === null) {
            throw new LlmException(sprintf('LLM at %s answered without choices[0].message.content: %s', $url, self::excerpt($body)));
        }

        return $content;
    }

    /**
     * @param array<mixed> $body
     */
    private function content(array $body): ?string
    {
        $choices = $body['choices'] ?? null;
        if (!is_array($choices) || !isset($choices[0]) || !is_array($choices[0])) {
            return null;
        }
        $message = $choices[0]['message'] ?? null;
        if (!is_array($message) || !is_string($message['content'] ?? null)) {
            return null;
        }

        return $message['content'];
    }

    /**
     * The API key never leaves this class, not even when a server echoes it back in an error.
     */
    private function redact(string $text): string
    {
        return str_replace($this->apiKey, '[REDACTED]', $text);
    }

    public static function excerpt(string $text, int $max = 200): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if ($text === '') {
            return '(empty body)';
        }

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '...' : $text;
    }
}
