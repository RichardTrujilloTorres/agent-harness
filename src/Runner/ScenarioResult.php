<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Runner;

use Desemax\AgentHarness\Scenario\Scenario;

final readonly class ScenarioResult
{
    /**
     * @param list<ExpectationResult> $expectations
     * @param ?string $message why the scenario was skipped or errored, or on which attempt it passed
     * @param int $attempts how many times the scenario was run (more than 1 with --retries)
     */
    public function __construct(
        public Scenario $scenario,
        public ScenarioStatus $status,
        public Transcript $transcript,
        public array $expectations,
        public int $durationMs,
        public ?string $message = null,
        public int $attempts = 1,
    ) {}

    public function withAttempts(int $attempts): self
    {
        return new self($this->scenario, $this->status, $this->transcript, $this->expectations, $this->durationMs, $this->message, $attempts);
    }

    /**
     * This (passing) result, marked as flaky because earlier attempts failed.
     */
    public function flaky(int $attempt, int $maxAttempts): self
    {
        return new self(
            $this->scenario,
            ScenarioStatus::Flaky,
            $this->transcript,
            $this->expectations,
            $this->durationMs,
            sprintf('passed on attempt %d of %d', $attempt, $maxAttempts),
            $attempt,
        );
    }

    public function passed(): bool
    {
        return $this->status === ScenarioStatus::Passed || $this->status === ScenarioStatus::Flaky;
    }

    /**
     * @return list<ExpectationResult>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->expectations,
            static fn(ExpectationResult $r): bool => $r->status === ExpectationStatus::Failed,
        ));
    }

    /**
     * @return list<ExpectationResult>
     */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->expectations,
            static fn(ExpectationResult $r): bool => $r->status === ExpectationStatus::Error,
        ));
    }
}
