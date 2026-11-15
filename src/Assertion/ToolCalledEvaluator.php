<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Assertion;

use Desemax\AgentHarness\Runner\ExpectationResult;
use Desemax\AgentHarness\Runner\Transcript;
use Desemax\AgentHarness\Scenario\Expectation;
use LogicException;

/**
 * tool_called: some tool call has that name and, if args are given, matches them (see ArgumentMatcher).
 */
final class ToolCalledEvaluator implements Evaluator
{
    public function __construct(private readonly ArgumentMatcher $matcher = new ArgumentMatcher()) {}

    public function evaluate(Expectation $expectation, Transcript $transcript): ExpectationResult
    {
        $payload = $expectation->payload;
        if (!is_array($payload) || !is_string($payload['name'] ?? null)) {
            throw new LogicException('tool_called expects a map with a name.');
        }
        $name = $payload['name'];
        $args = is_array($payload['args'] ?? null) ? $payload['args'] : null;
        $label = 'tool_called ' . $name;

        $all = $transcript->toolCalls();
        $matching = array_values(array_filter($all, static fn(array $c): bool => strcasecmp($c['call']->name, $name) === 0));

        if ($matching === []) {
            $names = array_values(array_unique(array_map(static fn(array $c): string => $c['call']->name, $all)));

            return ExpectationResult::failed($expectation, sprintf(
                '%s: never called; tools called: %s',
                $label,
                $names === [] ? 'none' : implode(', ', $names),
            ));
        }

        $mismatches = [];
        foreach ($matching as $call) {
            $mismatch = $args === null ? null : $this->matcher->mismatch($args, $call['call']->arguments);
            if ($mismatch === null) {
                return ExpectationResult::passed($expectation, sprintf('%s: called in turn %d', $label, $call['turn']));
            }
            $mismatches[] = ['turn' => $call['turn'], 'mismatch' => $mismatch];
        }

        if (count($mismatches) === 1) {
            return ExpectationResult::failed($expectation, sprintf('%s: called with %s', $label, $mismatches[0]['mismatch']));
        }

        return ExpectationResult::failed($expectation, sprintf(
            '%s: called %d times, none matched: %s',
            $label,
            count($mismatches),
            implode('; ', array_map(static fn(array $m): string => sprintf('turn %d with %s', $m['turn'], $m['mismatch']), $mismatches)),
        ));
    }
}
