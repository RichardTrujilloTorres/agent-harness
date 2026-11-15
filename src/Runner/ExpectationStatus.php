<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Runner;

enum ExpectationStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Error = 'error';
}
