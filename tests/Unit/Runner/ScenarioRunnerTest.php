<?php

declare(strict_types=1);

use Desemax\AgentHarness\Agent\AgentException;
use Desemax\AgentHarness\Agent\AgentReply;
use Desemax\AgentHarness\Agent\ToolCall;
use Desemax\AgentHarness\Assertion\Evaluator;
use Desemax\AgentHarness\Runner\ExpectationResult;
use Desemax\AgentHarness\Runner\ScenarioRunner;
use Desemax\AgentHarness\Runner\ScenarioStatus;
use Desemax\AgentHarness\Runner\Transcript;
use Desemax\AgentHarness\Scenario\Expectation;
use Desemax\AgentHarness\Scenario\ExpectationType;
use Desemax\AgentHarness\Scenario\Scenario;
use Desemax\AgentHarness\Scenario\UserMode;
use Desemax\AgentHarness\Scenario\UserScript;
use Desemax\AgentHarness\Tests\Support\FakeAgentAdapter;

/**
 * Evaluator stub: passes, fails or skips according to the expectation payload.
 */
function stubEvaluator(): Evaluator
{
    return new class () implements Evaluator {
        public function evaluate(Expectation $expectation, Transcript $transcript): ExpectationResult
        {
            return match ($expectation->payload) {
                'fail' => ExpectationResult::failed($expectation, 'nope'),
                'skip' => ExpectationResult::skipped($expectation, 'later'),
                default => ExpectationResult::passed($expectation, 'ok'),
            };
        }
    };
}

/**
 * @param list<string> $turns
 * @param list<string> $expect said payloads interpreted by the stub evaluator
 */
function scriptedScenario(array $turns, array $expect = ['pass'], int $maxTurns = 10): Scenario
{
    return new Scenario(
        name: 'test',
        user: new UserScript(UserMode::Scripted, $turns),
        expect: array_map(static fn (string $p): Expectation => new Expectation(ExpectationType::Said, $p), $expect),
        maxTurns: $maxTurns,
    );
}

it('sends every turn in order with one session id and records the transcript', function (): void {
    $agent = new FakeAgentAdapter([
        new AgentReply('one', latencyMs: 5),
        new AgentReply('two', [new ToolCall('create_booking', ['party_size' => 2])], latencyMs: 7),
    ]);

    $result = (new ScenarioRunner(stubEvaluator(), static fn (): string => 'fixed'))->run(scriptedScenario(['a', 'b']), $agent);

    expect($result->status)->toBe(ScenarioStatus::Passed)
        ->and($agent->sent)->toBe([['session' => 'fixed', 'message' => 'a'], ['session' => 'fixed', 'message' => 'b']])
        ->and($result->transcript->count())->toBe(2)
        ->and($result->transcript->turns[1]->number)->toBe(2)
        ->and($result->transcript->turns[1]->userMessage)->toBe('b')
        ->and($result->transcript->last()?->reply->reply)->toBe('two')
        ->and($result->transcript->toolCalls())->toHaveCount(1)
        ->and($result->transcript->toolCalls()[0]['turn'])->toBe(2)
        ->and($result->transcript->totalLatencyMs())->toBe(12)
        ->and($result->transcript->handedOff())->toBeFalse()
        ->and($result->expectations)->toHaveCount(1)
        ->and($result->durationMs)->toBeGreaterThanOrEqual(0);
});

it('uses a new random session id per scenario', function (): void {
    $runner = new ScenarioRunner(stubEvaluator());
    $a = new FakeAgentAdapter([new AgentReply('x')]);
    $b = new FakeAgentAdapter([new AgentReply('x')]);
    $runner->run(scriptedScenario(['hi']), $a);
    $runner->run(scriptedScenario(['hi']), $b);

    expect($a->sent[0]['session'])->toStartWith('harness-')
        ->and($a->sent[0]['session'])->not->toBe($b->sent[0]['session']);
});

it('stops early on handoff', function (): void {
    $agent = new FakeAgentAdapter([new AgentReply('passing you on', handoff: true)]);

    $result = (new ScenarioRunner(stubEvaluator()))->run(scriptedScenario(['a', 'b', 'c']), $agent);

    expect($agent->sent)->toHaveCount(1)
        ->and($result->transcript->count())->toBe(1)
        ->and($result->transcript->handoffTurn())->toBe(1);
});

it('stops when the scenario max_turns is reached', function (): void {
    $agent = new FakeAgentAdapter([new AgentReply('1'), new AgentReply('2'), new AgentReply('3')]);

    $result = (new ScenarioRunner(stubEvaluator()))->run(scriptedScenario(['a', 'b', 'c'], maxTurns: 2), $agent);

    expect($agent->sent)->toHaveCount(2)->and($result->transcript->count())->toBe(2);
});

it('derives the scenario status from the expectation results', function (array $expect, ScenarioStatus $status): void {
    $result = (new ScenarioRunner(stubEvaluator()))->run(scriptedScenario(['a'], $expect), new FakeAgentAdapter([new AgentReply('x')]));

    expect($result->status)->toBe($status)
        ->and($result->failures())->toHaveCount(count(array_filter($expect, static fn (string $e): bool => $e === 'fail')));
})->with([
    'all pass' => [['pass', 'pass'], ScenarioStatus::Passed],
    'a skip never fails' => [['pass', 'skip'], ScenarioStatus::Passed],
    'one failure' => [['pass', 'fail', 'skip'], ScenarioStatus::Failed],
    'nothing checkable' => [['skip'], ScenarioStatus::Skipped],
]);

it('skips scenarios played by an LLM user', function (): void {
    $scenario = new Scenario('llm', new UserScript(UserMode::Llm), [new Expectation(ExpectationType::Said, 'pass')]);
    $agent = new FakeAgentAdapter([]);

    $result = (new ScenarioRunner(stubEvaluator()))->run($scenario, $agent);

    expect($result->status)->toBe(ScenarioStatus::Skipped)
        ->and($result->message)->toBe('LLM-played user not available yet')
        ->and($agent->sent)->toBe([]);
});

it('turns an AgentException into an error and keeps the turns so far', function (): void {
    $agent = new FakeAgentAdapter([new AgentReply('fine'), new AgentException('Agent at x answered HTTP 500: boom')]);

    $result = (new ScenarioRunner(stubEvaluator()))->run(scriptedScenario(['a', 'b', 'c']), $agent);

    expect($result->status)->toBe(ScenarioStatus::Error)
        ->and($result->message)->toBe('Agent at x answered HTTP 500: boom')
        ->and($result->transcript->count())->toBe(1)
        ->and($result->expectations)->toBe([]);
});
