<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Runner;

use Closure;
use Desemax\AgentHarness\Agent\AgentAdapter;
use Desemax\AgentHarness\Agent\AgentException;
use Desemax\AgentHarness\Assertion\Evaluator;
use Desemax\AgentHarness\Assertion\EvaluatorRegistry;
use Desemax\AgentHarness\Scenario\Scenario;
use Desemax\AgentHarness\Scenario\UserMode;

/**
 * Plays one scenario against an agent and evaluates its expectations.
 */
final class ScenarioRunner
{
    public const LLM_USER_SKIP_REASON = 'LLM-played user not available yet';

    /** @var Closure(): string */
    private readonly Closure $sessionIds;

    /**
     * @param ?Closure(): string $sessionIds generates a new session id per scenario
     */
    public function __construct(
        private readonly Evaluator $evaluator = new EvaluatorRegistry(),
        ?Closure $sessionIds = null,
    ) {
        $this->sessionIds = $sessionIds ?? static fn (): string => 'harness-' . bin2hex(random_bytes(8));
    }

    public function run(Scenario $scenario, AgentAdapter $agent): ScenarioResult
    {
        $start = hrtime(true);
        $transcript = new Transcript();

        if ($scenario->user->mode === UserMode::Llm) {
            return new ScenarioResult($scenario, ScenarioStatus::Skipped, $transcript, [], 0, self::LLM_USER_SKIP_REASON);
        }

        $sessionId = ($this->sessionIds)();
        try {
            foreach ($scenario->user->turns as $message) {
                if ($transcript->count() >= $scenario->maxTurns) {
                    break;
                }
                $transcript = $transcript->with($message, $agent->send($sessionId, $message));
                if ($transcript->last()?->reply->handoff === true) {
                    break;
                }
            }
        } catch (AgentException $e) {
            return new ScenarioResult($scenario, ScenarioStatus::Error, $transcript, [], self::elapsedMs($start), $e->getMessage());
        }

        $results = [];
        foreach ($scenario->expect as $expectation) {
            $results[] = $this->evaluator->evaluate($expectation, $transcript);
        }

        return new ScenarioResult($scenario, self::status($results), $transcript, $results, self::elapsedMs($start));
    }

    /**
     * Failed if any expectation failed; skipped if nothing could be checked; passed otherwise.
     *
     * @param list<ExpectationResult> $results
     */
    private static function status(array $results): ScenarioStatus
    {
        $statuses = array_map(static fn (ExpectationResult $r): ExpectationStatus => $r->status, $results);
        if (in_array(ExpectationStatus::Failed, $statuses, true)) {
            return ScenarioStatus::Failed;
        }
        if (!in_array(ExpectationStatus::Passed, $statuses, true)) {
            return ScenarioStatus::Skipped;
        }

        return ScenarioStatus::Passed;
    }

    private static function elapsedMs(int|float $start): int
    {
        return (int) round((hrtime(true) - $start) / 1_000_000);
    }
}
