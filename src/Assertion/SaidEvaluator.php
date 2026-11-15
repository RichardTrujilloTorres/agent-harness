<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Assertion;

use Desemax\AgentHarness\Runner\ExpectationResult;
use Desemax\AgentHarness\Runner\Transcript;
use Desemax\AgentHarness\Scenario\Expectation;

/**
 * said: some agent reply contains the text (case-insensitive).
 */
final class SaidEvaluator implements Evaluator
{
    use PayloadReader;

    public function evaluate(Expectation $expectation, Transcript $transcript): ExpectationResult
    {
        $text = $this->stringPayload($expectation);
        $label = 'said ' . Text::quote($text);

        foreach ($transcript->turns as $turn) {
            $pos = Text::find($turn->reply->reply, $text);
            if ($pos !== null) {
                return ExpectationResult::passed($expectation, sprintf('%s: said in turn %d', $label, $turn->number));
            }
        }

        $last = $transcript->last();

        return ExpectationResult::failed($expectation, sprintf(
            '%s: not found in %d %s%s',
            $label,
            $transcript->count(),
            $transcript->count() === 1 ? 'reply' : 'replies',
            $last === null ? '' : sprintf('; last reply: %s', Text::quote(Text::excerpt($last->reply->reply, 0, 0, 80))),
        ));
    }
}
