<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Assertion;

use Desemax\AgentHarness\Runner\ExpectationResult;
use Desemax\AgentHarness\Runner\Transcript;
use Desemax\AgentHarness\Scenario\Expectation;

/**
 * judge: needs an LLM judge, which does not exist yet; always skipped.
 */
final class JudgeEvaluator implements Evaluator
{
    public const SKIP_REASON = 'LLM judge not available yet';

    public function evaluate(Expectation $expectation, Transcript $transcript): ExpectationResult
    {
        return ExpectationResult::skipped($expectation, self::SKIP_REASON);
    }
}
