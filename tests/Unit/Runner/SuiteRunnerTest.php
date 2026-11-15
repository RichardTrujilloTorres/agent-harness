<?php

declare(strict_types=1);

use Desemax\AgentHarness\Agent\AgentException;
use Desemax\AgentHarness\Agent\AgentReply;
use Desemax\AgentHarness\Assertion\EvaluatorRegistry;
use Desemax\AgentHarness\Runner\ScenarioResult;
use Desemax\AgentHarness\Runner\ScenarioRunner;
use Desemax\AgentHarness\Runner\ScenarioStatus;
use Desemax\AgentHarness\Runner\SuiteRunner;
use Desemax\AgentHarness\Scenario\Expectation;
use Desemax\AgentHarness\Scenario\ExpectationType;
use Desemax\AgentHarness\Scenario\Scenario;
use Desemax\AgentHarness\Scenario\UserMode;
use Desemax\AgentHarness\Scenario\UserScript;
use Desemax\AgentHarness\Tests\Support\FakeAgentAdapter;

function saysHello(string $name = 'hello'): Scenario
{
    return new Scenario($name, new UserScript(UserMode::Scripted, ['hi']), [new Expectation(ExpectationType::Said, 'hello')]);
}

it('does not retry by default', function (): void {
    $agent = new FakeAgentAdapter([new AgentReply('bye'), new AgentReply('hello')]);

    $result = (new SuiteRunner())->runOne(saysHello(), $agent);

    expect($result->status)->toBe(ScenarioStatus::Failed)
        ->and($result->attempts)->toBe(1)
        ->and($agent->sent)->toHaveCount(1);
});

it('reports FLAKY when a retry passes', function (): void {
    $agent = new FakeAgentAdapter([new AgentReply('bye'), new AgentException('Agent at x timed out after 10 ms'), new AgentReply('hello')]);

    $result = (new SuiteRunner(new ScenarioRunner(), 3))->runOne(saysHello(), $agent);

    expect($result->status)->toBe(ScenarioStatus::Flaky)
        ->and($result->status->label())->toBe('FLAKY')
        ->and($result->passed())->toBeTrue()
        ->and($result->attempts)->toBe(3)
        ->and($result->message)->toBe('passed on attempt 3 of 4')
        ->and($agent->sent)->toHaveCount(3);
});

it('keeps the last failure when every attempt fails', function (): void {
    $agent = new FakeAgentAdapter([new AgentReply('bye'), new AgentReply('nope')]);

    $result = (new SuiteRunner(new ScenarioRunner(), 1))->runOne(saysHello(), $agent);

    expect($result->status)->toBe(ScenarioStatus::Failed)
        ->and($result->attempts)->toBe(2)
        ->and($result->failures()[0]->reason)->toContain('last reply: "nope"');
});

it('does not retry passed or skipped scenarios', function (): void {
    $agent = new FakeAgentAdapter([new AgentReply('hello')]);
    $skipped = new Scenario('llm', new UserScript(UserMode::Llm), [new Expectation(ExpectationType::Said, 'x')]);

    $suite = (new SuiteRunner(new ScenarioRunner(new EvaluatorRegistry()), 2))->run([saysHello(), $skipped], $agent);

    expect($suite->results)->toHaveCount(2)
        ->and($suite->results[0]->status)->toBe(ScenarioStatus::Passed)
        ->and($suite->results[0]->attempts)->toBe(1)
        ->and($suite->results[1]->status)->toBe(ScenarioStatus::Skipped)
        ->and($suite->counts())->toBe(['passed' => 1, 'failed' => 0, 'skipped' => 1, 'error' => 0, 'flaky' => 0]);
});

it('reports each final result as it goes', function (): void {
    $seen = [];
    (new SuiteRunner())->run([saysHello('a'), saysHello('b')], new FakeAgentAdapter([new AgentReply('hello'), new AgentReply('x')]), function (ScenarioResult $r) use (&$seen): void {
        $seen[] = $r->scenario->name . ':' . $r->status->label();
    });

    expect($seen)->toBe(['a:PASS', 'b:FAIL']);
});
