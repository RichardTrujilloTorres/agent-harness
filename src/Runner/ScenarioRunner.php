<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Runner;

use Closure;
use Desemax\AgentHarness\Agent\AgentAdapter;
use Desemax\AgentHarness\Agent\AgentException;
use Desemax\AgentHarness\Assertion\Evaluator;
use Desemax\AgentHarness\Assertion\EvaluatorRegistry;
use Desemax\AgentHarness\Llm\LlmClient;
use Desemax\AgentHarness\Llm\LlmConfig;
use Desemax\AgentHarness\Llm\LlmException;
use Desemax\AgentHarness\Scenario\Scenario;
use Desemax\AgentHarness\Scenario\UserMode;

/**
 * Plays one scenario against an agent and evaluates its expectations.
 */
final class ScenarioRunner
{
    /** @var Closure(): string */
    private readonly Closure $sessionIds;

    /**
     * @param ?Closure(): string $sessionIds generates a new session id per scenario
     * @param ?LlmClient $llm plays llm-mode users; without it those scenarios are skipped
     */
    public function __construct(
        private readonly Evaluator $evaluator = new EvaluatorRegistry(),
        ?Closure $sessionIds = null,
        private readonly ?LlmClient $llm = null,
        private readonly ?int $seed = null,
    ) {
        $this->sessionIds = $sessionIds ?? static fn(): string => 'harness-' . bin2hex(random_bytes(8));
    }

    public function run(Scenario $scenario, AgentAdapter $agent): ScenarioResult
    {
        $start = hrtime(true);
        $transcript = new Transcript();

        $user = null;
        if ($scenario->user->mode === UserMode::Llm) {
            if ($this->llm === null) {
                return new ScenarioResult($scenario, ScenarioStatus::Skipped, $transcript, [], 0, LlmConfig::NOT_CONFIGURED);
            }
            $user = new LlmUser($this->llm, $this->seed);
        }

        $sessionId = ($this->sessionIds)();
        $scripted = $scenario->user->turns;
        try {
            while ($transcript->count() < $scenario->maxTurns) {
                $message = $user === null ? ($scripted[$transcript->count()] ?? null) : $user->next($scenario, $transcript);
                if ($message === null) {
                    break;
                }
                $transcript = $transcript->with($message, $agent->send($sessionId, $message));
                if ($transcript->last()?->reply->handoff === true) {
                    break;
                }
            }
        } catch (AgentException $e) {
            return new ScenarioResult($scenario, ScenarioStatus::Error, $transcript, [], self::elapsedMs($start), $e->getMessage());
        } catch (LlmException $e) {
            return new ScenarioResult($scenario, ScenarioStatus::Error, $transcript, [], self::elapsedMs($start), 'LLM-played user: ' . $e->getMessage());
        }

        $results = [];
        foreach ($scenario->expect as $expectation) {
            $results[] = $this->evaluator->evaluate($expectation, $transcript);
        }

        return new ScenarioResult($scenario, self::status($results), $transcript, $results, self::elapsedMs($start));
    }

    /**
     * Failed if any expectation failed; error if one could not be evaluated; skipped if nothing could be checked; passed otherwise.
     *
     * @param list<ExpectationResult> $results
     */
    private static function status(array $results): ScenarioStatus
    {
        $statuses = array_map(static fn(ExpectationResult $r): ExpectationStatus => $r->status, $results);
        if (in_array(ExpectationStatus::Failed, $statuses, true)) {
            return ScenarioStatus::Failed;
        }
        if (in_array(ExpectationStatus::Error, $statuses, true)) {
            return ScenarioStatus::Error;
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
