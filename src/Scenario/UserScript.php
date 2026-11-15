<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Scenario;

final readonly class UserScript
{
    /**
     * @param list<string> $turns scripted mode: the user's messages in order
     * @param ?string $opening llm mode: fixed first message, the LLM writes the rest
     */
    public function __construct(
        public UserMode $mode,
        public array $turns = [],
        public ?string $opening = null,
    ) {}
}
