<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Runner;

enum ScenarioStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Error = 'error';
    /** Failed or errored at first, passed on a retry. Counts as a pass for the exit code. */
    case Flaky = 'flaky';

    public function label(): string
    {
        return match ($this) {
            self::Passed => 'PASS',
            self::Failed => 'FAIL',
            self::Skipped => 'SKIP',
            self::Error => 'ERROR',
            self::Flaky => 'FLAKY',
        };
    }

    /**
     * Failed or errored: worth a retry and not a pass.
     */
    public function isFailure(): bool
    {
        return $this === self::Failed || $this === self::Error;
    }
}
