<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Config;

use Desemax\AgentHarness\Llm\LlmConfig;
use Desemax\AgentHarness\Scenario\ValidationError;
use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads harness.yaml and validates it, collecting every error found.
 */
final class ConfigLoader
{
    public const DEFAULT_FILE = 'harness.yaml';

    private const SECTIONS = [
        'agent' => ['url', 'timeout_ms', 'headers'],
        'request' => ['session_field', 'message_field'],
        'response' => ['reply', 'tool_calls', 'tool_name', 'tool_arguments', 'handoff'],
        'llm' => ['base_url', 'model', 'api_key_env', 'timeout_ms', 'max_tokens'],
    ];

    /** @var list<ValidationError> */
    private array $errors = [];

    private string $file = '';

    /**
     * @throws InvalidArgumentException when the file does not exist
     * @throws InvalidConfigException when the file is invalid
     */
    public function load(string $file): HarnessConfig
    {
        if (!is_file($file)) {
            throw new InvalidArgumentException(sprintf('Config file does not exist: %s', $file));
        }

        try {
            $data = Yaml::parseFile($file);
        } catch (ParseException $e) {
            throw new InvalidConfigException([new ValidationError($file, '(root)', 'invalid YAML: ' . $e->getMessage())]);
        }

        return $this->fromArray($data ?? [], $file);
    }

    /**
     * @throws InvalidConfigException
     */
    public function fromArray(mixed $data, string $file = '(config)'): HarnessConfig
    {
        $this->errors = [];
        $this->file = $file;

        if (!$this->isMap($data)) {
            $this->error('(root)', 'config must be a map');

            throw new InvalidConfigException($this->errors);
        }

        foreach (array_keys($data) as $key) {
            if (!array_key_exists((string) $key, self::SECTIONS)) {
                $this->error((string) $key, 'unknown field; allowed: ' . implode(', ', array_keys(self::SECTIONS)));
            }
        }

        $sections = [];
        foreach (self::SECTIONS as $section => $keys) {
            $sections[$section] = $this->section($data, $section, $keys);
        }

        $agent = $sections['agent'];
        $url = $this->optionalString($agent, 'url', 'agent.url');
        if ($url !== null && !preg_match('#^https?://#i', $url)) {
            $this->error('agent.url', 'must start with http:// or https://');
        }

        $timeout = $this->positiveInt($agent, 'timeout_ms', 'agent.timeout_ms') ?? HarnessConfig::DEFAULT_TIMEOUT_MS;

        $headers = [];
        if (array_key_exists('headers', $agent)) {
            if (!$this->isMap($agent['headers'])) {
                $this->error('agent.headers', 'must be a map of header name to string value');
            } else {
                foreach ($agent['headers'] as $name => $value) {
                    if (!is_string($value) && !is_int($value)) {
                        $this->error('agent.headers.' . $name, 'must be a string');
                        continue;
                    }
                    $headers[(string) $name] = (string) $value;
                }
            }
        }

        $request = $sections['request'];
        $response = $sections['response'];

        $config = new HarnessConfig(
            url: $url,
            timeoutMs: $timeout,
            headers: $headers,
            sessionField: $this->path($request, 'session_field', 'request', 'session_id'),
            messageField: $this->path($request, 'message_field', 'request', 'message'),
            replyPath: $this->path($response, 'reply', 'response', 'reply'),
            toolCallsPath: $this->path($response, 'tool_calls', 'response', 'tool_calls'),
            toolNamePath: $this->path($response, 'tool_name', 'response', 'name'),
            toolArgumentsPath: $this->path($response, 'tool_arguments', 'response', 'arguments'),
            handoffPath: $this->path($response, 'handoff', 'response', 'handoff'),
            file: $file,
            llm: $this->llm($data, $sections['llm']),
        );

        if ($config->sessionField === $config->messageField) {
            $this->error('request.message_field', 'must differ from request.session_field');
        }

        if ($this->errors !== []) {
            throw new InvalidConfigException($this->errors);
        }

        return $config;
    }

    /**
     * The optional llm section; null when absent.
     *
     * @param array<mixed> $data
     * @param array<mixed> $llm
     */
    private function llm(array $data, array $llm): ?LlmConfig
    {
        if (!array_key_exists('llm', $data) || !$this->isMap($data['llm'])) {
            return null;
        }

        $baseUrl = $this->optionalString($llm, 'base_url', 'llm.base_url');
        if (!array_key_exists('base_url', $llm)) {
            $this->error('llm.base_url', 'required field is missing');
        } elseif ($baseUrl !== null && preg_match('#^https?://#i', $baseUrl) !== 1) {
            $this->error('llm.base_url', 'must start with http:// or https://');
            $baseUrl = null;
        }

        $model = $this->optionalString($llm, 'model', 'llm.model');
        if (!array_key_exists('model', $llm)) {
            $this->error('llm.model', 'required field is missing');
        }

        $apiKeyEnv = $this->optionalString($llm, 'api_key_env', 'llm.api_key_env') ?? LlmConfig::DEFAULT_API_KEY_ENV;
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $apiKeyEnv) !== 1) {
            $this->error('llm.api_key_env', sprintf('must be an environment variable name like %s, got "%s"', LlmConfig::DEFAULT_API_KEY_ENV, $apiKeyEnv));
        }

        $timeout = $this->positiveInt($llm, 'timeout_ms', 'llm.timeout_ms') ?? LlmConfig::DEFAULT_TIMEOUT_MS;
        $maxTokens = $this->positiveInt($llm, 'max_tokens', 'llm.max_tokens');

        if ($baseUrl === null || $model === null) {
            return null;
        }

        return new LlmConfig($baseUrl, $model, $apiKeyEnv, $timeout, $maxTokens);
    }

    /**
     * @param array<mixed> $data
     */
    private function positiveInt(array $data, string $key, string $path): ?int
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        if (!is_int($data[$key])) {
            $this->error($path, 'must be an integer');

            return null;
        }
        if ($data[$key] < 1) {
            $this->error($path, 'must be >= 1');

            return null;
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $keys
     * @return array<mixed>
     */
    private function section(array $data, string $section, array $keys): array
    {
        if (!array_key_exists($section, $data) || $data[$section] === null) {
            return [];
        }

        $value = $data[$section];
        if (!$this->isMap($value)) {
            $this->error($section, 'must be a map');

            return [];
        }

        foreach (array_keys($value) as $key) {
            if (!in_array($key, $keys, true)) {
                $this->error($section . '.' . $key, 'unknown field; allowed: ' . implode(', ', $keys));
            }
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function optionalString(array $data, string $key, string $path): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        if (!is_string($data[$key]) || trim($data[$key]) === '') {
            $this->error($path, 'must be a non-empty string');

            return null;
        }

        return trim($data[$key]);
    }

    /**
     * A dot-path such as "data.output.text": non-empty segments, no spaces.
     *
     * @param array<mixed> $data
     */
    private function path(array $data, string $key, string $section, string $default): string
    {
        $path = $section . '.' . $key;
        $value = $this->optionalString($data, $key, $path);
        if ($value === null) {
            return $default;
        }
        if (preg_match('/^[^.\s]+(\.[^.\s]+)*$/', $value) !== 1) {
            $this->error($path, sprintf('must be a dot-path like "data.reply", got "%s"', $value));

            return $default;
        }

        return $value;
    }

    /**
     * @phpstan-assert-if-true array<mixed> $value
     */
    private function isMap(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    private function error(string $path, string $reason): void
    {
        $this->errors[] = new ValidationError($this->file, $path, $reason);
    }
}
