<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Runner;

use Desemax\AgentHarness\Scenario\Expectation;

final readonly class ExpectationResult
{
    public function __construct(
        public Expectation $expectation,
        public ExpectationStatus $status,
        public string $reason,
    ) {
    }

    public static function passed(Expectation $expectation, string $reason): self
    {
        return new self($expectation, ExpectationStatus::Passed, $reason);
    }

    public static function failed(Expectation $expectation, string $reason): self
    {
        return new self($expectation, ExpectationStatus::Failed, $reason);
    }

    public static function skipped(Expectation $expectation, string $reason): self
    {
        return new self($expectation, ExpectationStatus::Skipped, $reason);
    }
}
