<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Agent;

final readonly class ToolCall
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $name,
        public array $arguments = [],
    ) {
    }
}
