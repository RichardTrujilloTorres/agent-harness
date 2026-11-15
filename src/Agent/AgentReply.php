<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Agent;

final readonly class AgentReply
{
    /**
     * @param list<ToolCall> $toolCalls
     * @param array<mixed> $raw decoded response body
     */
    public function __construct(
        public string $reply,
        public array $toolCalls = [],
        public bool $handoff = false,
        public int $latencyMs = 0,
        public array $raw = [],
    ) {}
}
