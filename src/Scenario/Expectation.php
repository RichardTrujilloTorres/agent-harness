<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Scenario;

/**
 * One expected outcome of a scenario.
 *
 * Payload shape depends on the type:
 * - tool_called: array{name: string, args?: array<string, mixed>}
 * - said, never_said, judge: string
 * - handoff: bool
 * - max_turns, latency_ms_under: int
 */
final readonly class Expectation
{
    /**
     * @param string|int|bool|array<string, mixed> $payload
     */
    public function __construct(
        public ExpectationType $type,
        public string|int|bool|array $payload,
    ) {
    }
}
