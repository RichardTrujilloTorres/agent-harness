<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Runner;

use Closure;
use Desemax\AgentHarness\Agent\AgentAdapter;
use Desemax\AgentHarness\Scenario\Scenario;

/**
 * Runs a list of scenarios in order, re-running failed or errored ones up to $retries times.
 */
final readonly class SuiteRunner
{
    public function __construct(
        private ScenarioRunner $runner = new ScenarioRunner(),
        private int $retries = 0,
    ) {}

    /**
     * @param list<Scenario> $scenarios
     * @param ?Closure(ScenarioResult): void $onResult called after each scenario's final result
     * @param bool $failFast stop after the first scenario that fails or errors (after its retries)
     */
    public function run(array $scenarios, AgentAdapter $agent, ?Closure $onResult = null, bool $failFast = false): SuiteResult
    {
        $start = hrtime(true);
        $results = [];
        foreach ($scenarios as $scenario) {
            $result = $this->runOne($scenario, $agent);
            $results[] = $result;
            if ($onResult instanceof Closure) {
                $onResult($result);
            }
            if ($failFast && $result->status->isFailure()) {
                break;
            }
        }

        return new SuiteResult($results, (int) round((hrtime(true) - $start) / 1_000_000));
    }

    public function runOne(Scenario $scenario, AgentAdapter $agent): ScenarioResult
    {
        $result = $this->runner->run($scenario, $agent);
        $attempt = 1;
        while ($result->status->isFailure() && $attempt <= $this->retries) {
            $attempt++;
            $result = $this->runner->run($scenario, $agent);
            if ($result->status === ScenarioStatus::Passed) {
                return $result->flaky($attempt, $this->retries + 1);
            }
        }

        return $result->withAttempts($attempt);
    }
}
