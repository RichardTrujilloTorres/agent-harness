<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Runner;

final readonly class SuiteResult
{
    /**
     * @param list<ScenarioResult> $results in run order
     */
    public function __construct(
        public array $results,
        public int $durationMs,
    ) {
    }

    /**
     * @return array<value-of<ScenarioStatus>, int>
     */
    public function counts(): array
    {
        $counts = [];
        foreach (ScenarioStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }
        foreach ($this->results as $result) {
            $counts[$result->status->value]++;
        }

        return $counts;
    }

    public function count(ScenarioStatus $status): int
    {
        return $this->counts()[$status->value];
    }
}
