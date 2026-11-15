<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Assertion;

use Desemax\AgentHarness\Runner\ExpectationResult;
use Desemax\AgentHarness\Runner\Transcript;
use Desemax\AgentHarness\Scenario\Expectation;

/**
 * latency_ms_under: every turn answered in less than n ms.
 */
final class LatencyEvaluator implements Evaluator
{
    use PayloadReader;

    public function evaluate(Expectation $expectation, Transcript $transcript): ExpectationResult
    {
        $limit = $this->intPayload($expectation);
        $label = sprintf('latency_ms_under %d', $limit);

        $slow = [];
        $max = 0;
        foreach ($transcript->turns as $turn) {
            $max = max($max, $turn->reply->latencyMs);
            if ($turn->reply->latencyMs >= $limit) {
                $slow[] = sprintf('turn %d took %d ms', $turn->number, $turn->reply->latencyMs);
            }
        }

        return $slow === []
            ? ExpectationResult::passed($expectation, sprintf('%s: slowest turn %d ms', $label, $max))
            : ExpectationResult::failed($expectation, sprintf('%s: %s', $label, implode(', ', $slow)));
    }
}
