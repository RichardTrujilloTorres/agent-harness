<?php

declare(strict_types=1);

use Desemax\AgentHarness\Agent\AgentException;
use Desemax\AgentHarness\Agent\AgentReply;
use Desemax\AgentHarness\Agent\ToolCall;
use Desemax\AgentHarness\Pest\Conversation;
use Desemax\AgentHarness\Runner\ScenarioResult;
use Desemax\AgentHarness\Scenario\Expectation;
use Desemax\AgentHarness\Scenario\ExpectationType;
use Desemax\AgentHarness\Scenario\Scenario;
use Desemax\AgentHarness\Scenario\UserMode;
use Desemax\AgentHarness\Scenario\UserScript;
use Desemax\AgentHarness\Tests\Support\AgentServer;
use Desemax\AgentHarness\Tests\Support\FakeAgentAdapter;
use Desemax\AgentHarness\Tests\Support\FakeLlmClient;
use Desemax\AgentHarness\Tests\Support\ScenarioExpectation;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * Runs an expectation that should fail and returns its message.
 */
function failureMessage(Closure $assertion): string
{
    try {
        $assertion();
    } catch (ExpectationFailedException $e) {
        return $e->getMessage();
    }

    throw new LogicException('The expectation did not fail.');
}

it('defines conversation() through composer autoload.files', function (): void {
    expect(function_exists('conversation'))->toBeTrue()
        ->and(conversation(projectRoot() . '/examples/scenarios/happy-booking.yaml'))->toBeInstanceOf(Conversation::class);
});

describe('against the demo agent', function (): void {
    beforeEach(function (): void {
        AgentServer::startShared(projectRoot() . '/examples/demo-agent/public');
    });

    afterEach(function (): void {
        AgentServer::stopShared();
    });

    it('books a table', function (): void {
        $result = conversation(projectRoot() . '/examples/scenarios/happy-booking.yaml')
            ->against(AgentServer::shared()->url('/chat'))
            ->run();

        expectScenario($result)->toPassScenario()
            ->toHaveCalledTool('create_booking')
            ->toHaveCalledTool('create_booking', ['party_size' => 4, 'name' => 'anna'])
            ->toHaveSaid('confirmed')
            ->not()->toHaveHandedOff();
    });

    it('fails a failing scenario with the failed expectations and the transcript', function (): void {
        $result = conversation(projectRoot() . '/examples/scenarios/large-party.yaml')
            ->against(AgentServer::shared()->url('/chat'))
            ->run();

        $message = failureMessage(fn(): ScenarioExpectation => expectScenario($result)->toPassScenario());

        expect($message)->toStartWith('Scenario "Large party goes to staff" did not pass: FAIL')
            ->and($message)->toContain("Failed expectations:\n  ✗ handoff true: no handoff in 2 turn(s)\n  ✗ never_said \"confirmed\": said in turn 2:")
            ->and($message)->toContain("Transcript:\n  1. user:  I'd like a table for 12 on Saturday at 20:00\n     agent: ")
            ->and($message)->toContain('     tool:  create_booking {"party_size":12');
    });

    it('takes the agent URL and field mapping from a config file', function (): void {
        $server = AgentServer::start(fixture('remapped-agent/public'));
        try {
            $result = conversation(fixture('remapped-agent/scenarios/order-status.yaml'))
                ->withConfig(fixture('remapped-agent/harness.yaml'))
                ->against($server->url('/'))
                ->run();
        } finally {
            $server->stop();
        }

        expectScenario($result)->toPassScenario()->toHaveHandedOff();
    });
});

describe('against an AgentAdapter', function (): void {
    function bookingScenario(): Scenario
    {
        return new Scenario('Book for two', new UserScript(UserMode::Scripted, ['Table for 2', 'Sam']), [
            new Expectation(ExpectationType::ToolCalled, ['name' => 'create_booking', 'args' => ['party_size' => 2]]),
            new Expectation(ExpectationType::Said, 'booked'),
        ]);
    }

    it('accepts a Scenario object and passes', function (): void {
        $agent = new FakeAgentAdapter([new AgentReply('Name?'), new AgentReply('Booked, Sam.', [new ToolCall('create_booking', ['party_size' => 2])])]);

        $result = conversation(bookingScenario())->against($agent)->run();

        expectScenario($result)->toPassScenario()
            ->and($agent->sent[0]['message'])->toBe('Table for 2');
    });

    it('explains inline check failures with the transcript', function (): void {
        $agent = new FakeAgentAdapter([new AgentReply('Name?'), new AgentReply('Booked.', [new ToolCall('create_booking', ['party_size' => 3])])]);
        $result = conversation(bookingScenario())->against($agent)->run();

        expect(failureMessage(fn(): ScenarioExpectation => expectScenario($result)->toHaveCalledTool('create_booking', ['party_size' => 2])))
            ->toStartWith("tool_called create_booking: called with party_size=3, expected 2\nTranscript:\n  1. user:  Table for 2")
            ->and(failureMessage(fn(): ScenarioExpectation => expectScenario($result)->toHaveSaid('see you')))->toStartWith('said "see you": not found in 2 replies')
            ->and(failureMessage(fn(): ScenarioExpectation => expectScenario($result)->toHaveHandedOff()))->toStartWith('handoff true: no handoff in 2 turn(s)')
            ->and(failureMessage(fn(): ScenarioExpectation => expectScenario($result)->toPassScenario()))->toContain('✗ tool_called create_booking: called with party_size=3, expected 2');
    });

    it('reports an agent error in the failure message', function (): void {
        $agent = new FakeAgentAdapter([new AgentException('Agent at x answered HTTP 500: boom')]);
        $result = conversation(bookingScenario())->against($agent)->run();

        expect(failureMessage(fn(): ScenarioExpectation => expectScenario($result)->toPassScenario()))
            ->toStartWith("Scenario \"Book for two\" did not pass: ERROR\n  Agent at x answered HTTP 500: boom\nTranscript: no turns");
    });

    it('plays an LLM user and asks the judge with the given LLM', function (): void {
        $scenario = new Scenario('LLM', new UserScript(UserMode::Llm), [new Expectation(ExpectationType::Judge, 'Polite')], goal: 'Say hi');
        $llm = new FakeLlmClient(['Hi!', '[[DONE]]', '{"pass": false, "reason": "Rude."}']);

        $result = conversation($scenario)->against(new FakeAgentAdapter([new AgentReply('Go away.')]))->withLlm($llm)->withSeed(5)->run();

        expect(failureMessage(fn(): ScenarioExpectation => expectScenario($result)->toPassScenario()))->toContain('✗ judge "Polite": fail: Rude.')
            ->and($llm->requests[0]['options']->seed)->toBe(5);
    });

    it('fails a skipped scenario instead of passing it silently', function (): void {
        $scenario = new Scenario('LLM', new UserScript(UserMode::Llm), [new Expectation(ExpectationType::Said, 'x')]);
        $result = conversation($scenario)->against(new FakeAgentAdapter([]))->run();

        expect(failureMessage(fn(): ScenarioExpectation => expectScenario($result)->toPassScenario()))
            ->toStartWith("Scenario \"LLM\" did not pass: SKIP\n  no llm configured in harness.yaml");
    });
});

it('rejects values that are not a scenario result', function (): void {
    expect(fn(): ScenarioExpectation => expectScenario('nope')->toPassScenario())
        ->toThrow(InvalidArgumentException::class, 'Expected the result of conversation(...)->run() (' . ScenarioResult::class . '), got string.');
});

it('rejects a missing agent, a bad URL and a directory', function (Closure $run, string $message): void {
    expect($run)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no agent' => [fn(): ScenarioResult => conversation(projectRoot() . '/examples/scenarios/happy-booking.yaml')->run(), 'No agent: call against() with a URL or an AgentAdapter'],
    'bad url' => [fn(): ScenarioResult => conversation(projectRoot() . '/examples/scenarios/happy-booking.yaml')->against('localhost:8787')->run(), 'against() needs a URL starting with http:// or https://'],
    'directory' => [fn(): ScenarioResult => conversation(projectRoot() . '/examples/scenarios')->against('http://x')->run(), 'conversation() needs one scenario file; not a file:'],
]);

it('does nothing when Pest is not installed', function (): void {
    $script = <<<'PHP'
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Desemax\\AgentHarness\\';
            if (str_starts_with($class, $prefix)) {
                require getcwd() . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            }
        });
        require getcwd() . '/src/Pest/functions.php';
        echo function_exists('conversation') ? 'defined' : 'not defined';
        PHP;

    $output = [];
    exec(sprintf('cd %s && %s -r %s 2>&1', escapeshellarg(projectRoot()), escapeshellarg(PHP_BINARY), escapeshellarg($script)), $output, $code);

    expect($code)->toBe(0)->and(implode("\n", $output))->toBe('not defined');
});
