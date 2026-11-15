<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Llm;

final readonly class LlmOptions
{
    /**
     * @param ?int $maxTokens null uses llm.max_tokens from the config, if any
     * @param bool $jsonMode ask the model for a JSON object
     */
    public function __construct(
        public float $temperature = 0.0,
        public ?int $maxTokens = null,
        public ?int $seed = null,
        public bool $jsonMode = false,
    ) {
    }
}
