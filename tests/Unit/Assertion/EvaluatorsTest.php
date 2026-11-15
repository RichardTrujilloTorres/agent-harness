<?php

declare(strict_types=1);

use Desemax\AgentHarness\Agent\AgentReply;
use Desemax\AgentHarness\Agent\ToolCall;
use Desemax\AgentHarness\Assertion\EvaluatorRegistry;
use Desemax\AgentHarness\Assertion\JudgeEvaluator;
use Desemax\AgentHarness\Assertion\SaidEvaluator;
use Desemax\AgentHarness\Llm\LlmException;
use Desemax\AgentHarness\Runner\ExpectationStatus;
use Desemax\AgentHarness\Runner\ScenarioRunner;
use Desemax\AgentHarness\Runner\ScenarioStatus;
use Desemax\AgentHarness\Runner\Transcript;
use Desemax\AgentHarness\Scenario\Expectation;
use Desemax\AgentHarness\Scenario\ExpectationType;
use Desemax\AgentHarness\Scenario\Scenario;
use Desemax\AgentHarness\Scenario\UserMode;
use Desemax\AgentHarness\Scenario\UserScript;
use Desemax\AgentHarness\Tests\Support\FakeAgentAdapter;
use Desemax\AgentHarness\Tests\Support\FakeLlmClient;

/**
 * Plays the replies through a FakeAgentAdapter and returns the transcript.
 *
 * @param list<AgentReply> $replies
 */
function playReplies(array $replies): Transcript
{
    $agent = new FakeAgentAdapter($replies);
    $transcript = new Transcript();
    foreach (array_keys($replies) as $i) {
        $message = 'user message ' . ($i + 1);
        $transcript = $transcript->with($message, $agent->send('s', $message));
    }

    return $transcript;
}

/**
 * @param string|int|bool|array<string, mixed> $payload
 * @param list<AgentReply> $replies
 * @return array{0: ExpectationStatus, 1: string}
 */
function check(ExpectationType $type, string|int|bool|array $payload, array $replies): array
{
    $result = (new EvaluatorRegistry())->evaluate(new Expectation($type, $payload), playReplies($replies));

    return [$result->status, $result->reason];
}

function booking(array $args, int $latency = 10): AgentReply
{
    return new AgentReply('Booked.', [new ToolCall('create_booking', $args)], latencyMs: $latency);
}

describe('tool_called', function (): void {
    $args = ['party_size' => 2, 'day' => 'saturday', 'time' => '20:00', 'customer' => ['name' => 'Anna', 'phone' => '123']];

    it('passes on a subset match with loose numbers and case-insensitive trimmed strings', function () use ($args): void {
        expect(check(ExpectationType::ToolCalled, ['name' => 'create_booking', 'args' => [
            'party_size' => '2',
            'day' => ' Saturday ',
            'customer' => ['name' => 'ANNA'],
        ]], [new AgentReply('hi'), booking($args)]))->toBe([ExpectationStatus::Passed, 'tool_called create_booking: called in turn 2']);
    });

    it('passes without args when the tool was called', function () use ($args): void {
        expect(check(ExpectationType::ToolCalled, ['name' => 'create_booking'], [booking($args)])[0])->toBe(ExpectationStatus::Passed);
    });

    it('fails with the mismatching argument', function () use ($args): void {
        expect(check(ExpectationType::ToolCalled, ['name' => 'create_booking', 'args' => ['party_size' => 2]], [booking(['party_size' => 10] + $args)]))
            ->toBe([ExpectationStatus::Failed, 'tool_called create_booking: called with party_size=10, expected 2']);
    });

    it('fails with the nested path and quoted strings', function () use ($args): void {
        expect(check(ExpectationType::ToolCalled, ['name' => 'create_booking', 'args' => ['customer' => ['name' => 'Marco']]], [booking($args)])[1])
            ->toBe('tool_called create_booking: called with customer.name="Anna", expected "Marco"');
    });

    it('fails when an expected argument is missing', function () use ($args): void {
        expect(check(ExpectationType::ToolCalled, ['name' => 'create_booking', 'args' => ['notes' => 'window']], [booking($args)])[1])
            ->toBe('tool_called create_booking: called with no notes, expected "window"');
    });

    it('reports every call when several calls fail to match', function () use ($args): void {
        expect(check(ExpectationType::ToolCalled, ['name' => 'create_booking', 'args' => ['party_size' => 2]], [
            booking(['party_size' => 3]),
            booking(['party_size' => 4]),
        ])[1])->toBe('tool_called create_booking: called 2 times, none matched: turn 1 with party_size=3, expected 2; turn 2 with party_size=4, expected 2');
    });

    it('fails when the tool was never called and lists the tools that were', function (): void {
        expect(check(ExpectationType::ToolCalled, ['name' => 'create_booking'], [new AgentReply('x')]))
            ->toBe([ExpectationStatus::Failed, 'tool_called create_booking: never called; tools called: none'])
            ->and(check(ExpectationType::ToolCalled, ['name' => 'create_booking'], [new AgentReply('x', [new ToolCall('check_availability')])])[1])
            ->toBe('tool_called create_booking: never called; tools called: check_availability');
    });

    it('compares lists element by element and keeps bools strict', function (): void {
        $reply = new AgentReply('x', [new ToolCall('order', ['items' => ['a', 'b'], 'paid' => false])]);

        expect(check(ExpectationType::ToolCalled, ['name' => 'order', 'args' => ['items' => ['A', 'b']]], [$reply])[0])->toBe(ExpectationStatus::Passed)
            ->and(check(ExpectationType::ToolCalled, ['name' => 'order', 'args' => ['items' => ['a']]], [$reply])[1])
            ->toBe('tool_called order: called with items=["a","b"], expected ["a"]')
            ->and(check(ExpectationType::ToolCalled, ['name' => 'order', 'args' => ['paid' => 0]], [$reply])[1])
            ->toBe('tool_called order: called with paid=false, expected 0');
    });
});

describe('said', function (): void {
    it('passes when any reply contains the text, case-insensitive', function (): void {
        expect(check(ExpectationType::Said, 'CONFIRMED', [new AgentReply('Hello'), new AgentReply('Your table is confirmed.')]))
            ->toBe([ExpectationStatus::Passed, 'said "CONFIRMED": said in turn 2']);
    });

    it('fails with the last reply', function (): void {
        expect(check(ExpectationType::Said, 'confirmed', [new AgentReply('Hello'), new AgentReply('What name should I use?')]))
            ->toBe([ExpectationStatus::Failed, 'said "confirmed": not found in 2 replies; last reply: "What name should I use?"']);
    });
});

describe('never_said', function (): void {
    it('passes when no reply contains the text', function (): void {
        expect(check(ExpectationType::NeverSaid, 'gluten-free', [new AgentReply('Please ask our staff.')]))
            ->toBe([ExpectationStatus::Passed, 'never_said "gluten-free": not said in 1 turn(s)']);
    });

    it('fails with the turn and an excerpt', function (): void {
        $long = 'Thank you for asking about our menu, we take this seriously. Yes, all our dishes are Gluten-Free and we cook everything fresh every single day in our kitchen.';

        expect(check(ExpectationType::NeverSaid, 'gluten-free', [new AgentReply('Hello'), new AgentReply($long)]))
            ->toBe([ExpectationStatus::Failed, 'never_said "gluten-free": said in turn 2: "...this seriously. Yes, all our dishes are Gluten-Free and we cook everything fresh every sing..."']);
    });
});

describe('handoff', function (): void {
    it('passes when the handoff matches', function (): void {
        expect(check(ExpectationType::Handoff, true, [new AgentReply('a'), new AgentReply('b', handoff: true)]))
            ->toBe([ExpectationStatus::Passed, 'handoff true: handed off in turn 2'])
            ->and(check(ExpectationType::Handoff, false, [new AgentReply('a')]))
            ->toBe([ExpectationStatus::Passed, 'handoff false: no handoff in 1 turn(s)']);
    });

    it('fails when it does not', function (): void {
        expect(check(ExpectationType::Handoff, true, [new AgentReply('a'), new AgentReply('b')]))
            ->toBe([ExpectationStatus::Failed, 'handoff true: no handoff in 2 turn(s)'])
            ->and(check(ExpectationType::Handoff, false, [new AgentReply('a', handoff: true)]))
            ->toBe([ExpectationStatus::Failed, 'handoff false: handed off in turn 1']);
    });
});

describe('max_turns', function (): void {
    it('passes at the limit', function (): void {
        expect(check(ExpectationType::MaxTurns, 2, [new AgentReply('a'), new AgentReply('b')]))
            ->toBe([ExpectationStatus::Passed, 'max_turns 2: used 2 turn(s)']);
    });

    it('fails above the limit', function (): void {
        expect(check(ExpectationType::MaxTurns, 1, [new AgentReply('a'), new AgentReply('b')]))
            ->toBe([ExpectationStatus::Failed, 'max_turns 1: used 2 turn(s)']);
    });
});

describe('latency_ms_under', function (): void {
    it('passes when every turn is faster', function (): void {
        expect(check(ExpectationType::LatencyMsUnder, 100, [new AgentReply('a', latencyMs: 20), new AgentReply('b', latencyMs: 99)]))
            ->toBe([ExpectationStatus::Passed, 'latency_ms_under 100: slowest turn 99 ms']);
    });

    it('fails listing every slow turn, the limit itself included', function (): void {
        expect(check(ExpectationType::LatencyMsUnder, 100, [new AgentReply('a', latencyMs: 100), new AgentReply('b', latencyMs: 5), new AgentReply('c', latencyMs: 2345)]))
            ->toBe([ExpectationStatus::Failed, 'latency_ms_under 100: turn 1 took 100 ms, turn 3 took 2345 ms']);
    });
});

describe('judge', function (): void {
    it('is skipped without an LLM and never fails the scenario', function (): void {
        expect(check(ExpectationType::Judge, 'The agent is polite.', [new AgentReply('a')]))
            ->toBe([ExpectationStatus::Skipped, 'no llm configured in harness.yaml']);

        $scenario = new Scenario('s', new UserScript(UserMode::Scripted, ['hi']), [
            new Expectation(ExpectationType::Said, 'hello'),
            new Expectation(ExpectationType::Judge, 'The agent is polite.'),
        ]);
        $result = (new ScenarioRunner())->run($scenario, new FakeAgentAdapter([new AgentReply('Hello!')]));

        expect($result->status)->toBe(ScenarioStatus::Passed);
    });

    /**
     * @param list<string|LlmException> $responses
     * @return array{0: ExpectationStatus, 1: string, 2: FakeLlmClient}
     */
    function judge(array $responses, string $rubric = 'The agent is polite.'): array
    {
        $llm = new FakeLlmClient($responses);
        $transcript = playReplies([new AgentReply('Hello! Booked.', [new ToolCall('create_booking', ['party_size' => 2])]), new AgentReply('A colleague will call you.', handoff: true)]);
        $result = (new JudgeEvaluator($llm, 3))->evaluate(new Expectation(ExpectationType::Judge, $rubric), $transcript);

        return [$result->status, $result->reason, $llm];
    }

    it('passes with the judge\'s reason and sends rubric and transcript in JSON mode', function (): void {
        [$status, $reason, $llm] = judge(['{"pass": true, "reason": "Greets and thanks the user."}']);

        expect($status)->toBe(ExpectationStatus::Passed)
            ->and($reason)->toBe('judge "The agent is polite.": pass: Greets and thanks the user.')
            ->and($llm->requests)->toHaveCount(1)
            ->and($llm->requests[0]['system'])->toBe(JudgeEvaluator::SYSTEM_PROMPT)
            ->and($llm->requests[0]['options']->jsonMode)->toBeTrue()
            ->and($llm->requests[0]['options']->temperature)->toBe(0.0)
            ->and($llm->requests[0]['options']->seed)->toBe(3)
            ->and($llm->requests[0]['messages'][0]['content'])->toBe(implode("\n", [
                'Rubric:',
                'The agent is polite.',
                '',
                'Transcript:',
                'Turn 1',
                'User: user message 1',
                'Agent: Hello! Booked.',
                'Agent called tool create_booking with {"party_size":2}',
                'Turn 2',
                'User: user message 2',
                'Agent: A colleague will call you.',
                'Agent handed the conversation off to a human.',
            ]));
    });

    it('fails with the judge\'s reason', function (): void {
        [$status, $reason] = judge(['```json' . "\n" . '{"pass": false, "reason": "Never says thank you."}' . "\n" . '```']);

        expect($status)->toBe(ExpectationStatus::Failed)
            ->and($reason)->toBe('judge "The agent is polite.": fail: Never says thank you.');
    });

    it('retries once after invalid JSON', function (): void {
        [$status, $reason, $llm] = judge(['Sure! The agent was polite.', '{"pass": true, "reason": "Polite."}']);

        expect($status)->toBe(ExpectationStatus::Passed)
            ->and($reason)->toEndWith('pass: Polite.')
            ->and($llm->requests)->toHaveCount(2);
    });

    it('errors with the raw excerpt after invalid JSON twice', function (): void {
        [$status, $reason, $llm] = judge(['not json', '{"pass": "yes"}']);

        expect($status)->toBe(ExpectationStatus::Error)
            ->and($reason)->toBe('judge "The agent is polite.": judge did not answer {"pass": bool, "reason": string} after a retry; last answer: {"pass": "yes"}')
            ->and($llm->requests)->toHaveCount(2);
    });

    it('errors when the LLM fails', function (): void {
        [$status, $reason] = judge([new LlmException('LLM at x answered HTTP 401: bad key')]);

        expect($status)->toBe(ExpectationStatus::Error)
            ->and($reason)->toBe('judge "The agent is polite.": LLM at x answered HTTP 401: bad key');
    });

    it('makes the scenario an error when the judge errors and nothing failed', function (): void {
        $scenario = new Scenario('s', new UserScript(UserMode::Scripted, ['hi']), [
            new Expectation(ExpectationType::Said, 'hello'),
            new Expectation(ExpectationType::Judge, 'The agent is polite.'),
        ]);
        $runner = new ScenarioRunner(EvaluatorRegistry::withLlm(new FakeLlmClient(['x', 'y'])));

        $result = $runner->run($scenario, new FakeAgentAdapter([new AgentReply('Hello!')]));

        expect($result->status)->toBe(ScenarioStatus::Error)
            ->and($result->expectations[1]->status)->toBe(ExpectationStatus::Error);
    });
});

it('registers one evaluator per expectation type and accepts overrides', function (): void {
    $registry = new EvaluatorRegistry();
    foreach (ExpectationType::cases() as $type) {
        expect($registry->for($type))->toBeObject();
    }

    $custom = new SaidEvaluator();
    expect((new EvaluatorRegistry([ExpectationType::Judge->value => $custom]))->for(ExpectationType::Judge))->toBe($custom);
});
