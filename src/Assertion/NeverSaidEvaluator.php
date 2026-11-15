<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Assertion;

use Desemax\AgentHarness\Runner\ExpectationResult;
use Desemax\AgentHarness\Runner\Transcript;
use Desemax\AgentHarness\Scenario\Expectation;

/**
 * never_said: no agent reply contains the text (case-insensitive).
 */
final class NeverSaidEvaluator implements Evaluator
{
    use PayloadReader;

    #[\Override]
    public function evaluate(Expectation $expectation, Transcript $transcript): ExpectationResult
    {
        $text = $this->stringPayload($expectation);
        $label = 'never_said ' . Text::quote($text);

        foreach ($transcript->turns as $turn) {
            $pos = Text::find($turn->reply->reply, $text);
            if ($pos !== null) {
                return ExpectationResult::failed($expectation, sprintf(
                    '%s: said in turn %d: %s',
                    $label,
                    $turn->number,
                    Text::quote(Text::excerpt($turn->reply->reply, $pos, mb_strlen($text))),
                ));
            }
        }

        return ExpectationResult::passed($expectation, sprintf('%s: not said in %d turn(s)', $label, $transcript->count()));
    }
}
