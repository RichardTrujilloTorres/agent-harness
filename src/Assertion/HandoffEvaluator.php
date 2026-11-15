<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Assertion;

use Desemax\AgentHarness\Runner\ExpectationResult;
use Desemax\AgentHarness\Runner\Transcript;
use Desemax\AgentHarness\Scenario\Expectation;
use Override;

/**
 * handoff: whether any turn handed off equals the expected bool.
 */
final class HandoffEvaluator implements Evaluator
{
    use PayloadReader;

    #[Override]
    public function evaluate(Expectation $expectation, Transcript $transcript): ExpectationResult
    {
        $expected = $this->boolPayload($expectation);
        $turn = $transcript->handoffTurn();
        $label = 'handoff ' . ($expected ? 'true' : 'false');
        $actual = $turn === null
            ? sprintf('no handoff in %d turn(s)', $transcript->count())
            : sprintf('handed off in turn %d', $turn);

        return ($turn !== null) === $expected
            ? ExpectationResult::passed($expectation, sprintf('%s: %s', $label, $actual))
            : ExpectationResult::failed($expectation, sprintf('%s: %s', $label, $actual));
    }
}
