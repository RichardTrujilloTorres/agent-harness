<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Llm;

/**
 * The llm section of harness.yaml. The API key itself is never stored, only the name of its environment variable.
 */
final readonly class LlmConfig
{
    public const DEFAULT_API_KEY_ENV = 'HARNESS_LLM_API_KEY';
    public const DEFAULT_TIMEOUT_MS = 30000;

    public function __construct(
        public string $baseUrl,
        public string $model,
        public string $apiKeyEnv = self::DEFAULT_API_KEY_ENV,
        public int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        public ?int $maxTokens = null,
    ) {
    }
}
