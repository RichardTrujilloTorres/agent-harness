<?php

declare(strict_types=1);

use Desemax\AgentHarness\Application;
use Desemax\AgentHarness\Command\RunCommand;
use Desemax\AgentHarness\Tests\Support\AgentServer;
use Desemax\AgentHarness\Tests\Support\FakeLlmClient;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @param array<string, mixed> $options
 */
function runScenarios(string $path, array $options = [], bool $verbose = false): CommandTester
{
    $tester = new CommandTester((new Application())->find('run'));
    $tester->execute(['path' => $path] + $options, $verbose ? ['verbosity' => OutputInterface::VERBOSITY_VERBOSE] : []);

    return $tester;
}

describe('against the demo agent', function (): void {
    beforeEach(function (): void {
        AgentServer::startShared(projectRoot() . '/examples/demo-agent/public');
    });

    afterEach(function (): void {
        AgentServer::stopShared();
    });

    it('passes 4 and fails the 2 intended scenarios with reasons, exit 1', function (): void {
        $tester = runScenarios(projectRoot() . '/examples/scenarios', ['--url' => AgentServer::shared()->url('/chat')]);
        $display = $tester->getDisplay();

        expect($tester->getStatusCode())->toBe(1)
            ->and(preg_match_all('/^PASS  /m', $display))->toBe(4)
            ->and(preg_match_all('/^FAIL  /m', $display))->toBe(2)
            ->and($display)->toMatch('/^FAIL   Allergen question is not answered with invented claims  \(1 turn, \d+ ms\)$/m')
            ->and($display)->toMatch('/^FAIL   Large party goes to staff  \(2 turns, \d+ ms\)$/m')
            ->and($display)->toMatch('/^PASS   Happy path booking  \(3 turns, \d+ ms\)$/m')
            ->and($display)->toContain('✗ never_said "gluten-free": said in turn 1: "Yes, all our dishes are gluten-free."')
            ->and($display)->toContain('✗ handoff true: no handoff in 2 turn(s)')
            ->and($display)->toContain('✗ never_said "confirmed": said in turn 2:')
            ->and($display)->toMatch('/6 scenario\(s\): 4 passed, 2 failed, 0 skipped, 0 errors in \d+ ms/')
            ->and($display)->not->toContain('Transcript:');
    });

    it('writes the HTML, JUnit and transcript reports', function (): void {
        $dir = sys_get_temp_dir() . '/agent-harness-reports-' . bin2hex(random_bytes(4));
        $tester = runScenarios(projectRoot() . '/examples/scenarios', [
            '--url' => AgentServer::shared()->url('/chat'),
            '--report-html' => $dir . '/report.html',
            '--report-junit' => $dir . '/junit/report.xml',
            '--transcripts' => $dir . '/transcripts',
        ]);

        $junit = new DOMDocument();
        $junit->load($dir . '/junit/report.xml');
        $transcripts = glob($dir . '/transcripts/*.json');
        $transcripts = $transcripts === false ? [] : $transcripts;
        sort($transcripts);

        expect($tester->getStatusCode())->toBe(1)
            ->and($tester->getDisplay())->toContain('HTML report: ' . $dir . '/report.html')
            ->and($tester->getDisplay())->toContain('JUnit report: ' . $dir . '/junit/report.xml')
            ->and($tester->getDisplay())->toContain('Transcripts: 6 file(s) in ' . $dir . '/transcripts')
            ->and((string) file_get_contents($dir . '/report.html'))->toContain('Allergen question is not answered with invented claims')
            ->and($junit->getElementsByTagName('testcase')->length)->toBe(6)
            ->and($junit->getElementsByTagName('failure')->length)->toBe(2)
            ->and(array_map(basename(...), $transcripts))->toBe([
                'allergen-question-is-not-answered-with-invented-claims.json',
                'happy-path-booking.json',
                'large-party-goes-to-staff.json',
                'missing-details-agent-asks-for-the-name.json',
                'opening-hours-question.json',
                'request-for-a-human.json',
            ]);

        exec('rm -rf ' . escapeshellarg($dir));
    });

    it('runs only the filtered scenario and exits 0', function (): void {
        $tester = runScenarios(projectRoot() . '/examples/scenarios', ['--url' => AgentServer::shared()->url('/chat'), '--filter' => 'happy']);

        expect($tester->getStatusCode())->toBe(0)
            ->and($tester->getDisplay())->toContain('PASS   Happy path booking')
            ->and($tester->getDisplay())->toContain('1 scenario(s): 1 passed, 0 failed, 0 skipped, 0 errors');
    });

    it('prints expectation results and the transcript with -v', function (): void {
        $display = runScenarios(projectRoot() . '/examples/scenarios', ['--url' => AgentServer::shared()->url('/chat'), '--filter' => 'allergen'], true)->getDisplay();

        expect($display)->toContain('~ judge: skipped (no llm configured in harness.yaml)')
            ->and($display)->toContain('Transcript:')
            ->and($display)->toContain('1. user:  Are your dishes safe for someone with coeliac disease?')
            ->and($display)->toContain('agent: Yes, all our dishes are gluten-free.');
    });

    it('gives the same result through the real binary', function (): void {
        exec(sprintf(
            '%s %s run %s --url=%s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(projectRoot() . '/bin/agent-harness'),
            escapeshellarg(projectRoot() . '/examples/scenarios'),
            escapeshellarg(AgentServer::shared()->url('/chat')),
        ), $out, $exit);

        expect($exit)->toBe(1)->and(implode("\n", $out))->toContain('4 passed, 2 failed');
    });
});

it('exits 3 with the error when the agent is unreachable', function (): void {
    $url = 'http://127.0.0.1:' . AgentServer::freePort() . '/chat';
    $tester = runScenarios(projectRoot() . '/examples/scenarios', ['--url' => $url, '--filter' => 'happy']);

    expect($tester->getStatusCode())->toBe(3)
        ->and($tester->getDisplay())->toContain('ERROR  Happy path booking  (0 turns,')
        ->and($tester->getDisplay())->toContain("Agent at {$url} is unreachable")
        ->and($tester->getDisplay())->toContain('0 passed, 0 failed, 0 skipped, 1 errors');
});

it('remaps request and response fields end to end with a config file', function (): void {
    $server = AgentServer::start(fixture('remapped-agent/public'));
    try {
        $tester = runScenarios(fixture('remapped-agent/scenarios'), [
            '--config' => fixture('remapped-agent/harness.yaml'),
            '--url' => $server->url('/'),
        ], true);
    } finally {
        $server->stop();
    }
    $display = $tester->getDisplay();

    expect($tester->getStatusCode())->toBe(0)
        ->and($display)->toMatch('/^PASS   Order status through remapped fields  \(2 turns, \d+ ms\)$/m')
        ->and($display)->toContain('✓ tool_called lookup_order: called in turn 1')
        ->and($display)->toContain('✓ handoff true: handed off in turn 2')
        ->and($display)->toContain('tool:  lookup_order {"order_id":42,"customer":{"email":"test@example.com"}}')
        ->and($display)->toMatch('/^SKIP   LLM-played user is skipped  \(0 turns, \d+ ms\)$/m')
        ->and($display)->toContain('no llm configured in harness.yaml')
        ->and($display)->toContain('2 scenario(s): 1 passed, 0 failed, 1 skipped, 0 errors');
});

it('exits 2 with the validate output when a scenario is invalid', function (): void {
    $tester = runScenarios(fixture('mixed'), ['--url' => 'http://127.0.0.1:1/chat']);

    expect($tester->getStatusCode())->toBe(2)
        ->and($tester->getDisplay())->toContain('FAIL ' . fixture('mixed/bad.yaml'))
        ->and($tester->getDisplay())->toContain('- name: required field is missing')
        ->and($tester->getDisplay())->toContain('2 file(s), 1 valid, 1 invalid');
});

it('exits 2 on bad input', function (array $options, string $message, string $path = 'examples/scenarios'): void {
    $tester = runScenarios(projectRoot() . '/' . $path, stringKeyed($options));

    expect($tester->getStatusCode())->toBe(2)->and($tester->getDisplay())->toContain($message);
})->with([
    'missing path' => [['--url' => 'http://x/chat'], 'Path does not exist', 'nope'],
    'no url' => [[], 'No agent URL: pass --url or set agent.url'],
    'bad url' => [['--url' => 'localhost:8787'], '--url must be an http:// or https:// URL with a host, got "localhost:8787".'],
    'missing config' => [['--config' => '/nonexistent/harness.yaml'], 'Config file does not exist: /nonexistent/harness.yaml'],
    'filter matches nothing' => [['--url' => 'http://x/chat', '--filter' => 'zzz'], 'No scenario name contains "zzz". Run "agent-harness list'],
    'empty report path' => [['--url' => 'http://x/chat', '--report-html' => ' '], '--report-html needs a path, for example --report-html=build/report.html.'],
]);

it('exits 2 and lists config errors', function (): void {
    $file = sys_get_temp_dir() . '/harness-bad-' . bin2hex(random_bytes(4)) . '.yaml';
    file_put_contents($file, "agent:\n  timeout_ms: -1\nresponse:\n  replies: x\n");

    $tester = runScenarios(projectRoot() . '/examples/scenarios', ['--config' => $file, '--url' => 'http://x/chat']);

    expect($tester->getStatusCode())->toBe(2)
        ->and($tester->getDisplay())->toContain("Invalid config {$file}")
        ->and($tester->getDisplay())->toContain('- response.replies: unknown field')
        ->and($tester->getDisplay())->toContain('- agent.timeout_ms: must be >= 1');
});

it('reads ./harness.yaml from the working directory by default', function (): void {
    $server = AgentServer::start(fixture('remapped-agent/public'));
    $cwd = (string) getcwd();
    chdir(fixture('remapped-agent'));
    try {
        $tester = runScenarios('scenarios', ['--url' => $server->url('/'), '--filter' => 'order']);
    } finally {
        chdir($cwd);
        $server->stop();
    }

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('PASS   Order status through remapped fields');
});

describe('with an llm section', function (): void {
    beforeEach(function (): void {
        AgentServer::startShared(projectRoot() . '/examples/demo-agent/public');
    });

    afterEach(function (): void {
        AgentServer::stopShared();
    });

    it('plays the LLM user and asks the judge through the configured client', function (): void {
        $llm = new FakeLlmClient(['When are you open?', '[[DONE]]', '{"pass": true, "reason": "Gives the hours."}']);
        $tester = new CommandTester(new RunCommand(llmFactory: static fn(): FakeLlmClient => $llm));
        $tester->execute([
            'path' => fixture('llm/scenarios'),
            '--config' => fixture('llm/harness.yaml'),
            '--url' => AgentServer::shared()->url('/chat'),
        ], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
        $display = $tester->getDisplay();

        expect($tester->getStatusCode())->toBe(0)
            ->and($display)->toMatch('/^PASS   LLM user asks for the opening hours  \(1 turn, \d+ ms\)$/m')
            ->and($display)->toContain('1. user:  When are you open?')
            ->and($display)->toContain('✓ judge "The agent gives the opening hours without inventing anything.": pass: Gives the hours.')
            ->and($llm->remaining())->toBe(0);
    });

    it('exits 2 with a clear message when the API key variable is not set', function (): void {
        putenv('HARNESS_TEST_UNSET_KEY');
        $tester = runScenarios(fixture('llm/scenarios'), ['--config' => fixture('llm/harness.yaml'), '--url' => AgentServer::shared()->url('/chat')]);

        expect($tester->getStatusCode())->toBe(2)
            ->and($tester->getDisplay())->toContain('Environment variable HARNESS_TEST_UNSET_KEY is not set.')
            ->and($tester->getDisplay())->not->toContain('Exception');
    });

    it('does not need the API key when no scenario uses the LLM', function (): void {
        putenv('HARNESS_TEST_UNSET_KEY');
        $tester = runScenarios(projectRoot() . '/examples/scenarios', [
            '--config' => fixture('llm/harness.yaml'),
            '--url' => AgentServer::shared()->url('/chat'),
            '--filter' => 'happy',
        ]);

        expect($tester->getStatusCode())->toBe(0);
    });
});

it('passes the seed to the LLM', function (): void {
    $server = AgentServer::start(projectRoot() . '/examples/demo-agent/public');
    $llm = new FakeLlmClient(['When are you open?', '[[DONE]]', '{"pass": true, "reason": "ok"}']);
    try {
        $tester = new CommandTester(new RunCommand(llmFactory: static fn(): FakeLlmClient => $llm));
        $tester->execute(['path' => fixture('llm/scenarios'), '--config' => fixture('llm/harness.yaml'), '--url' => $server->url('/chat'), '--seed' => '1234']);
    } finally {
        $server->stop();
    }

    expect($tester->getStatusCode())->toBe(0)
        ->and(array_map(static fn(array $r): ?int => $r['options']->seed, $llm->requests))->toBe([1234, 1234, 1234]);
});

it('reports a flaky scenario, counts it as a pass and exits 0', function (): void {
    $server = AgentServer::start(fixture('flaky-agent'));
    $counter = sys_get_temp_dir() . '/agent-harness-flaky-' . $server->port;
    if (is_file($counter)) {
        unlink($counter);
    }
    try {
        $tester = runScenarios(fixture('flaky-agent/scenarios'), ['--url' => $server->url('/'), '--retries' => '2']);
    } finally {
        $server->stop();
        if (is_file($counter)) {
            unlink($counter);
        }
    }
    $display = $tester->getDisplay();

    expect($tester->getStatusCode())->toBe(0)
        ->and($display)->toMatch('/^FLAKY  Second answer is right  \(1 turn, \d+ ms\)$/m')
        ->and($display)->toContain('passed on attempt 2 of 3')
        ->and($display)->toMatch('/1 scenario\(s\): 0 passed, 1 flaky, 0 failed, 0 skipped, 0 errors in \d+ ms/');
});

it('rejects bad --seed and --retries values', function (array $options, string $message): void {
    $tester = runScenarios(projectRoot() . '/examples/scenarios', ['--url' => 'http://x/chat'] + stringKeyed($options));

    expect($tester->getStatusCode())->toBe(2)->and($tester->getDisplay())->toContain($message);
})->with([
    'seed' => [['--seed' => 'abc'], '--seed must be an integer, got "abc".'],
    'retries negative' => [['--retries' => '-1'], '--retries must be an integer >= 0, got "-1".'],
    'retries text' => [['--retries' => 'two'], '--retries must be an integer >= 0, got "two".'],
]);
