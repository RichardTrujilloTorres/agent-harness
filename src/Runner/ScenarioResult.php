<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Runner;

use Desemax\AgentHarness\Scenario\Scenario;

final readonly class ScenarioResult
{
    /**
     * @param list<ExpectationResult> $expectations
     * @param ?string $message why the scenario was skipped or errored
     */
    public function __construct(
        public Scenario $scenario,
        public ScenarioStatus $status,
        public Transcript $transcript,
        public array $expectations,
        public int $durationMs,
        public ?string $message = null,
    ) {
    }

    /**
     * @return list<ExpectationResult>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->expectations,
            static fn (ExpectationResult $r): bool => $r->status === ExpectationStatus::Failed,
        ));
    }
}
