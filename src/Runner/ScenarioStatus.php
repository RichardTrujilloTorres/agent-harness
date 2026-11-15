<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Runner;

enum ScenarioStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Passed => 'PASS',
            self::Failed => 'FAIL',
            self::Skipped => 'SKIP',
            self::Error => 'ERROR',
        };
    }
}
