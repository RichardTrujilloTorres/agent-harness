<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Assertion;

use Desemax\AgentHarness\Runner\ExpectationResult;
use Desemax\AgentHarness\Runner\Transcript;
use Desemax\AgentHarness\Scenario\Expectation;

/**
 * max_turns: the conversation used at most n turns.
 */
final class MaxTurnsEvaluator implements Evaluator
{
    use PayloadReader;

    #[\Override]
    public function evaluate(Expectation $expectation, Transcript $transcript): ExpectationResult
    {
        $max = $this->intPayload($expectation);
        $reason = sprintf('max_turns %d: used %d turn(s)', $max, $transcript->count());

        return $transcript->count() <= $max
            ? ExpectationResult::passed($expectation, $reason)
            : ExpectationResult::failed($expectation, $reason);
    }
}
