<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Pest;

use Closure;
use Desemax\AgentHarness\Assertion\Evaluator;
use Desemax\AgentHarness\Assertion\HandoffEvaluator;
use Desemax\AgentHarness\Assertion\SaidEvaluator;
use Desemax\AgentHarness\Assertion\ToolCalledEvaluator;
use Desemax\AgentHarness\Runner\ExpectationStatus;
use Desemax\AgentHarness\Runner\ScenarioResult;
use Desemax\AgentHarness\Runner\ScenarioStatus;
use Desemax\AgentHarness\Scenario\Expectation;
use Desemax\AgentHarness\Scenario\ExpectationType;
use InvalidArgumentException;
use Pest\Expectation as PestExpectation;
use PHPUnit\Framework\Assert;

/**
 * Pest expectations on a ScenarioResult. Each check returns null on success or the failure message.
 */
final class ScenarioAssertions
{
    /**
     * Registers toPassScenario, toHaveCalledTool, toHaveSaid and toHaveHandedOff with Pest.
     */
    public static function register(): void
    {
        self::extend('toPassScenario', function (): PestExpectation {
            $message = ScenarioAssertions::passes(ScenarioAssertions::result($this->value));
            Assert::assertNull($message, (string) $message);

            return $this;
        });
        /** @param ?array<mixed> $args */
        self::extend('toHaveCalledTool', function (string $name, ?array $args = null): PestExpectation {
            $message = ScenarioAssertions::calledTool(ScenarioAssertions::result($this->value), $name, $args);
            Assert::assertNull($message, (string) $message);

            return $this;
        });
        self::extend('toHaveSaid', function (string $text): PestExpectation {
            $message = ScenarioAssertions::said(ScenarioAssertions::result($this->value), $text);
            Assert::assertNull($message, (string) $message);

            return $this;
        });
        self::extend('toHaveHandedOff', function (): PestExpectation {
            $message = ScenarioAssertions::handedOff(ScenarioAssertions::result($this->value));
            Assert::assertNull($message, (string) $message);

            return $this;
        });
    }

    /**
     * Null when the scenario passed; otherwise the status, failed or errored expectations and the transcript.
     */
    public static function passes(ScenarioResult $result): ?string
    {
        if ($result->passed()) {
            return null;
        }

        $lines = [sprintf('Scenario "%s" did not pass: %s', $result->scenario->name, $result->status->label())];
        if ($result->message !== null) {
            $lines[] = '  ' . $result->message;
        }
        $problems = array_merge($result->failures(), $result->errors());
        if ($problems !== []) {
            $lines[] = $result->status === ScenarioStatus::Error && $result->failures() === [] ? 'Errored expectations:' : 'Failed expectations:';
            foreach ($problems as $problem) {
                $lines[] = sprintf('  %s %s', $problem->status === ExpectationStatus::Error ? '!' : '✗', $problem->reason);
            }
        }

        return implode("\n", [...$lines, ...self::transcript($result)]);
    }

    /**
     * @param ?array<mixed> $args expected arguments, matched as a subset like tool_called.args
     */
    public static function calledTool(ScenarioResult $result, string $name, ?array $args = null): ?string
    {
        $payload = ['name' => $name];
        if ($args !== null) {
            $payload['args'] = $args;
        }

        return self::check(new ToolCalledEvaluator(), new Expectation(ExpectationType::ToolCalled, $payload), $result);
    }

    public static function said(ScenarioResult $result, string $text): ?string
    {
        return self::check(new SaidEvaluator(), new Expectation(ExpectationType::Said, $text), $result);
    }

    public static function handedOff(ScenarioResult $result): ?string
    {
        return self::check(new HandoffEvaluator(), new Expectation(ExpectationType::Handoff, true), $result);
    }

    /**
     * @throws InvalidArgumentException when the value is not a ScenarioResult
     */
    public static function result(mixed $value): ScenarioResult
    {
        if (!$value instanceof ScenarioResult) {
            throw new InvalidArgumentException(sprintf(
                'Expected the result of conversation(...)->run() (%s), got %s.',
                ScenarioResult::class,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * @param-closure-this PestExpectation<mixed> $closure
     */
    private static function extend(string $name, Closure $closure): void
    {
        (new PestExpectation(null))->extend($name, $closure);
    }

    private static function check(Evaluator $evaluator, Expectation $expectation, ScenarioResult $result): ?string
    {
        $outcome = $evaluator->evaluate($expectation, $result->transcript);
        if ($outcome->status === ExpectationStatus::Passed) {
            return null;
        }

        return implode("\n", [$outcome->reason, ...self::transcript($result)]);
    }

    /**
     * @return list<string>
     */
    private static function transcript(ScenarioResult $result): array
    {
        if ($result->transcript->count() === 0) {
            return ['Transcript: no turns'];
        }

        return ['Transcript:', ...array_map(static fn (string $line): string => '  ' . $line, $result->transcript->lines())];
    }
}
