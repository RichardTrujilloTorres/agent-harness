<?php

declare(strict_types=1);

use Desemax\AgentHarness\Agent\AgentReply;
use Desemax\AgentHarness\Agent\ToolCall;
use Desemax\AgentHarness\Runner\ExpectationResult;
use Desemax\AgentHarness\Runner\ScenarioResult;
use Desemax\AgentHarness\Runner\ScenarioStatus;
use Desemax\AgentHarness\Runner\SuiteResult;
use Desemax\AgentHarness\Runner\Transcript;
use Desemax\AgentHarness\Scenario\Expectation;
use Desemax\AgentHarness\Scenario\ExpectationType;
use Desemax\AgentHarness\Scenario\Scenario;
use Desemax\AgentHarness\Scenario\UserMode;
use Desemax\AgentHarness\Scenario\UserScript;
use Desemax\AgentHarness\Security\Redactor;

it('replaces every secret, longest first, and ignores values too short to be secrets', function (): void {
    $redactor = new Redactor(['abcd', ' abcdef ', 'abc', '', 'abcd']);

    expect($redactor->text('x abcdef y abcd z abc'))->toBe('x [REDACTED] y [REDACTED] z abc')
        ->and((new Redactor())->text('abcd'))->toBe('abcd')
        ->and(Redactor::MIN_LENGTH)->toBe(4);
});

it('redacts nested values, keys included, and leaves other types alone', function (): void {
    $redactor = new Redactor(['s3cret']);

    expect($redactor->value(['k' => 's3cret!', 's3cret' => [1, true, null, 's3cret']]))->toBe(['k' => '[REDACTED]!', '[REDACTED]' => [1, true, null, '[REDACTED]']])
        ->and($redactor->value(7))->toBe(7);
});

it('redacts every text of a result and keeps everything else', function (): void {
    $expectation = new Expectation(ExpectationType::Said, 'x');
    $transcript = (new Transcript())->with('my key s3cret', new AgentReply('echo s3cret', [new ToolCall('t_s3cret', ['h' => 's3cret', 'n' => 'ok'])], true, 12, ['raw' => 's3cret']));
    $result = new ScenarioResult(new Scenario('n', new UserScript(UserMode::Scripted, ['a']), [$expectation]), ScenarioStatus::Failed, $transcript, [ExpectationResult::failed($expectation, 'saw s3cret')], 34, 'msg s3cret', 2, ['warn s3cret']);

    $suite = (new Redactor(['s3cret']))->suite(new SuiteResult([$result], 56));
    $out = $suite->results[0];
    $turn = $out->transcript->turns[0];

    expect($suite->durationMs)->toBe(56)
        ->and($out->status)->toBe(ScenarioStatus::Failed)
        ->and($out->durationMs)->toBe(34)
        ->and($out->attempts)->toBe(2)
        ->and($out->message)->toBe('msg [REDACTED]')
        ->and($out->warnings)->toBe(['warn [REDACTED]'])
        ->and($out->expectations[0]->reason)->toBe('saw [REDACTED]')
        ->and($out->expectations[0]->status)->toBe($result->expectations[0]->status)
        ->and($out->expectations[0]->expectation)->toBe($expectation)
        ->and($turn->number)->toBe(1)
        ->and($turn->userMessage)->toBe('my key [REDACTED]')
        ->and($turn->reply->reply)->toBe('echo [REDACTED]')
        ->and($turn->reply->toolCalls[0]->name)->toBe('t_[REDACTED]')
        ->and($turn->reply->toolCalls[0]->arguments)->toBe(['h' => '[REDACTED]', 'n' => 'ok'])
        ->and($turn->reply->handoff)->toBeTrue()
        ->and($turn->reply->latencyMs)->toBe(12)
        ->and($turn->reply->raw)->toBe(['raw' => '[REDACTED]'])
        ->and((new Redactor())->result(new ScenarioResult($result->scenario, ScenarioStatus::Skipped, new Transcript(), [], 0))->message)->toBeNull();
});
