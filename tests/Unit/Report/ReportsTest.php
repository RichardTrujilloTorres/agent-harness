<?php

declare(strict_types=1);

use Desemax\AgentHarness\Agent\AgentReply;
use Desemax\AgentHarness\Agent\ToolCall;
use Desemax\AgentHarness\Report\HtmlReport;
use Desemax\AgentHarness\Report\JunitReport;
use Desemax\AgentHarness\Report\ResultData;
use Desemax\AgentHarness\Report\TranscriptReport;
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

/**
 * A fake run: one result per scenario status, with a hostile reply in the first one.
 */
function fakeSuite(): SuiteResult
{
    $scenario = static fn (string $name): Scenario => new Scenario($name, new UserScript(UserMode::Scripted, ['hi']), [], file: 'scenarios/' . ResultData::slug($name) . '.yaml');
    $said = new Expectation(ExpectationType::Said, 'confirmed');
    $judge = new Expectation(ExpectationType::Judge, 'Polite');

    $transcript = (new Transcript())
        ->with('Book for <b>2</b>', new AgentReply('<script>alert("x")</script> Booked & confirmed.', [new ToolCall('create_booking', ['party_size' => 2, 'note' => '</pre>'])], latencyMs: 12))
        ->with('Thanks', new AgentReply('Passing you to a colleague.', handoff: true, latencyMs: 8));

    return new SuiteResult([
        new ScenarioResult($scenario('Happy path booking'), ScenarioStatus::Passed, $transcript, [
            ExpectationResult::passed($said, 'said "confirmed": said in turn 1'),
            ExpectationResult::skipped($judge, 'no llm configured in harness.yaml'),
        ], 25),
        new ScenarioResult($scenario('Large party'), ScenarioStatus::Failed, $transcript, [
            ExpectationResult::failed($said, 'handoff true: no handoff in 2 turn(s)'),
            ExpectationResult::failed($said, 'never_said "confirmed": said in turn 2: "<i>x</i>"'),
        ], 30),
        new ScenarioResult($scenario('Agent down'), ScenarioStatus::Error, new Transcript(), [], 3, 'Agent at http://x is unreachable: refused'),
        new ScenarioResult($scenario('LLM user'), ScenarioStatus::Skipped, new Transcript(), [], 0, 'no llm configured in harness.yaml'),
        new ScenarioResult($scenario('Happy path booking'), ScenarioStatus::Flaky, $transcript, [ExpectationResult::passed($said, 'ok')], 40, 'passed on attempt 2 of 3', 2),
    ], 98);
}

describe('HTML', function (): void {
    it('is one self-contained, loadable document with summary, expectations and transcript', function (): void {
        $html = (new HtmlReport())->render(fakeSuite(), new DateTimeImmutable('2026-01-02 03:04:05 UTC'));

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML($html);
        // libxml only knows HTML 4: the HTML5 <section> tag is its only complaint.
        $problems = array_filter(libxml_get_errors(), static fn (LibXMLError $e): bool => !str_contains($e->message, 'Tag section invalid'));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($doc);

        expect($loaded)->toBeTrue()->and($problems)->toBe([]);

        expect($xpath->query('//script')->length)->toBe(0)
            ->and($xpath->query('//link | //img | //iframe')->length)->toBe(0)
            ->and($xpath->query('//style')->length)->toBe(1)
            ->and($xpath->query('//section')->length)->toBe(5)
            ->and($xpath->query('//span[contains(@class, "badge")]')->length)->toBe(5)
            ->and($xpath->query('//div[@class="turn"]')->length)->toBe(6)
            ->and($html)->toContain('prefers-color-scheme: dark')
            ->and($html)->toContain('<b>5</b> scenario(s)')
            ->and($html)->toContain('<span class="c-passed"><b>1</b> pass</span>')
            ->and($html)->toContain('<span class="c-failed"><b>1</b> fail</span>')
            ->and($html)->toContain('<span class="c-flaky"><b>1</b> flaky</span>')
            ->and($html)->toContain('98 ms')
            ->and($html)->toContain('Generated 2026-01-02 03:04:05 UTC')
            ->and($html)->toContain('handoff true: no handoff in 2 turn(s)')
            ->and($html)->toContain('judge: skipped (no llm configured in harness.yaml)')
            ->and($html)->toContain('Agent at http://x is unreachable: refused')
            ->and($html)->toContain('passed on attempt 2 of 3')
            ->and($html)->toContain('<code>create_booking</code>')
            ->and($html)->toContain('<strong>handoff</strong>')
            ->and($html)->toContain('Turn 1 · 12 ms')
            ->and($html)->not->toContain('http-equiv')
            ->and($html)->not->toMatch('/(src|href)=/');
    });

    it('escapes everything that came from the agent, the user or the scenario', function (): void {
        $html = (new HtmlReport())->render(fakeSuite());

        expect($html)->not->toContain('<script>')
            ->and($html)->toContain('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; Booked &amp; confirmed.')
            ->and($html)->toContain('Book for &lt;b&gt;2&lt;/b&gt;')
            ->and($html)->toContain('&lt;/pre&gt;')
            ->and($html)->toContain('&lt;i&gt;x&lt;/i&gt;');
    });
});

describe('JUnit', function (): void {
    it('has one testsuite with one testcase per scenario and the right counts', function (): void {
        $doc = new DOMDocument();
        expect($doc->loadXML((new JunitReport())->render(fakeSuite())))->toBeTrue();
        $xpath = new DOMXPath($doc);

        $suite = $xpath->query('/testsuites/testsuite')->item(0);
        expect($xpath->query('/testsuites/testsuite')->length)->toBe(1)
            ->and($suite)->toBeInstanceOf(DOMElement::class)
            ->and($suite->getAttribute('tests'))->toBe('5')
            ->and($suite->getAttribute('failures'))->toBe('1')
            ->and($suite->getAttribute('errors'))->toBe('1')
            ->and($suite->getAttribute('skipped'))->toBe('1')
            ->and($suite->getAttribute('time'))->toBe('0.098')
            ->and($xpath->query('//testcase')->length)->toBe(5)
            ->and($xpath->query('//testcase[@name="Large party"]/failure')->length)->toBe(1)
            ->and($xpath->query('//testcase[@name="Large party"]/failure')->item(0)?->textContent)->toBe("handoff true: no handoff in 2 turn(s)\nnever_said \"confirmed\": said in turn 2: \"<i>x</i>\"")
            ->and($xpath->query('//testcase[@name="Large party"]/failure/@message')->item(0)?->nodeValue)->toBe('handoff true: no handoff in 2 turn(s)')
            ->and($xpath->query('//testcase[@name="Agent down"]/error/@message')->item(0)?->nodeValue)->toBe('Agent at http://x is unreachable: refused')
            ->and($xpath->query('//testcase[@name="LLM user"]/skipped/@message')->item(0)?->nodeValue)->toBe('no llm configured in harness.yaml')
            ->and($xpath->query('//testcase[@name="Happy path booking"][not(failure) and not(error) and not(skipped)]')->length)->toBe(2)
            ->and($xpath->query('//testcase[@name="Happy path booking"]/@classname')->item(0)?->nodeValue)->toBe('scenarios/happy-path-booking.yaml')
            ->and($xpath->query('//testcase[@name="Happy path booking"]/system-out')->item(0)?->textContent)->toContain('agent: <script>alert("x")</script> Booked & confirmed. (12 ms)');
    });

    it('escapes reply text instead of emitting markup', function (): void {
        $xml = (new JunitReport())->render(fakeSuite());

        expect($xml)->not->toContain('<script>')
            ->and($xml)->toContain('&lt;script&gt;');
    });
});

describe('transcripts', function (): void {
    it('renders one JSON file per scenario with slugified, unique names', function (): void {
        $files = (new TranscriptReport())->render(fakeSuite());

        expect(array_keys($files))->toBe(['happy-path-booking.json', 'large-party.json', 'agent-down.json', 'llm-user.json', 'happy-path-booking-2.json']);

        $data = json_decode($files['happy-path-booking.json'], true);
        expect($data['name'])->toBe('Happy path booking')
            ->and($data['status'])->toBe('passed')
            ->and($data['turns'])->toHaveCount(2)
            ->and($data['turns'][0])->toBe([
                'number' => 1,
                'user' => 'Book for <b>2</b>',
                'agent' => '<script>alert("x")</script> Booked & confirmed.',
                'tool_calls' => [['name' => 'create_booking', 'arguments' => ['party_size' => 2, 'note' => '</pre>']]],
                'handoff' => false,
                'latency_ms' => 12,
            ])
            ->and($data['turns'][1]['handoff'])->toBeTrue()
            ->and($data['expectations'][1])->toBe(['type' => 'judge', 'expected' => 'Polite', 'status' => 'skipped', 'reason' => 'no llm configured in harness.yaml'])
            ->and(json_decode($files['happy-path-booking-2.json'], true)['attempts'])->toBe(2);
    });

    it('writes the files into a new directory', function (): void {
        $dir = sys_get_temp_dir() . '/agent-harness-transcripts-' . bin2hex(random_bytes(4)) . '/nested';

        $paths = (new TranscriptReport())->write(fakeSuite(), $dir);

        expect($paths)->toHaveCount(5)
            ->and(is_file($dir . '/large-party.json'))->toBeTrue();
        array_map('unlink', $paths);
        rmdir($dir);
        rmdir(dirname($dir));
    });

    it('slugifies names', function (string $name, string $slug): void {
        expect(ResultData::slug($name))->toBe($slug);
    })->with([
        ['Happy path booking', 'happy-path-booking'],
        ['  Ask: "hours?" (Sunday)  ', 'ask-hours-sunday'],
        ['Caffè e crème', 'caffe-e-creme'],
        ['!!!', 'scenario'],
    ]);
});
