<?php

declare(strict_types=1);

use Desemax\AgentHarness\Command\RunCommand;
use Desemax\AgentHarness\Llm\LlmClient;
use Desemax\AgentHarness\Llm\LlmConfig;
use Desemax\AgentHarness\Report\ResultData;
use Desemax\AgentHarness\Tests\Support\AgentServer;
use Desemax\AgentHarness\Tests\Support\FakeLlmClient;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

const HEADER_CANARY = 'header-value-canary';
const KEY_CANARY = 'llm-api-key-canary';

beforeEach(function (): void {
    AgentServer::startShared(fixture('echo-agent'));
    putenv('AGENT_HARNESS_TEST_KEY=' . KEY_CANARY);
});

afterEach(function (): void {
    AgentServer::stopShared();
    putenv('AGENT_HARNESS_TEST_KEY');
});

/**
 * Runs the echo scenario with a header secret and an LLM key, all reports on, at maximum verbosity.
 *
 * @return array{display: string, files: array<string, string>}
 */
function runWithCanaries(string $format): array
{
    $dir = sys_get_temp_dir() . '/agent-harness-canary-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $config = $dir . '/harness.yaml';
    file_put_contents($config, implode("\n", [
        'agent:',
        '  url: ' . AgentServer::shared()->url('/'),
        '  headers:',
        '    X-Api-Key: ' . HEADER_CANARY,
        'llm:',
        '  base_url: http://llm.test/v1',
        '  model: m',
        '  api_key_env: AGENT_HARNESS_TEST_KEY',
    ]) . "\n");

    // The judge echoes the API key, as a compromised or confused model might.
    $llm = new FakeLlmClient(['{"pass": false, "reason": "Key is ' . KEY_CANARY . ' and header ' . HEADER_CANARY . '"}']);
    $app = new ConsoleApplication();
    $app->addCommand(new RunCommand(llmFactory: static fn(LlmConfig $c): LlmClient => $llm));
    $tester = new CommandTester($app->find('run'));
    $tester->execute([
        'path' => fixture('echo-agent/scenarios'),
        '--config' => $config,
        '--report-html' => $dir . '/report.html',
        '--report-junit' => $dir . '/junit.xml',
        '--transcripts' => $dir . '/transcripts',
        '--format' => $format,
    ], ['verbosity' => OutputInterface::VERBOSITY_DEBUG]);

    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->getFilename() !== 'harness.yaml') {
            $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    return ['display' => $tester->getDisplay(), 'files' => $files];
}

it('never prints or writes a configured header value or the API key', function (string $format): void {
    ['display' => $display, 'files' => $files] = runWithCanaries($format);

    expect($files)->toHaveCount(3)
        ->and($display)->toContain('[REDACTED]');
    foreach (['output' => $display] + $files as $channel => $contents) {
        expect($contents)->not->toContain(HEADER_CANARY, $channel . ' leaks the header value')
            ->and($contents)->not->toContain(KEY_CANARY, $channel . ' leaks the API key');
    }
})->with(['text', 'json']);

it('strips escape sequences and does not interpret console tags from the agent', function (): void {
    ['display' => $display] = runWithCanaries('text');

    expect($display)->not->toContain("\e[2J")
        ->and($display)->not->toContain("\e]0;")
        ->and($display)->not->toContain("\x07")
        ->and($display)->toContain('<error>red</error>')
        ->and($display)->toContain('Echo <b>agent</b>');
});

it('surfaces the suspicious_reply warning in CLI, JSON, HTML and JUnit output', function (): void {
    ['display' => $text, 'files' => $files] = runWithCanaries('text');
    ['display' => $json] = runWithCanaries('json');
    $html = (string) current(array_filter($files, static fn(string $c, string $f): bool => str_ends_with($f, '.html'), ARRAY_FILTER_USE_BOTH));
    $junit = (string) current(array_filter($files, static fn(string $c, string $f): bool => str_ends_with($f, '.xml'), ARRAY_FILTER_USE_BOTH));
    $warning = 'suspicious_reply: turn 1 looks like prompt injection (ignore previous instructions, verdict injection)';

    expect($text)->toContain('⚠ ' . $warning)
        ->and(jsonArray($json)['scenarios'])->toBeArray()
        ->and($json)->toContain('"warnings": [' . "\n" . '                "' . $warning . '"')
        ->and($html)->toContain('<h3>Warnings</h3>')
        ->and($html)->toContain($warning)
        ->and($junit)->toContain('<property name="warning" value="' . $warning . '"/>')
        ->and($junit)->toContain('<system-err>' . $warning . '</system-err>');
});

it('redacts the result returned to Pest tests by conversation()', function (): void {
    $config = sys_get_temp_dir() . '/agent-harness-conv-' . bin2hex(random_bytes(4)) . '.yaml';
    file_put_contents($config, "agent:\n  headers:\n    X-Api-Key: " . HEADER_CANARY . "\nllm:\n  base_url: http://llm.test/v1\n  model: m\n  api_key_env: AGENT_HARNESS_TEST_KEY\n");

    $result = conversation(fixture('echo-agent/scenarios/echo.yaml'))
        ->against(AgentServer::shared()->url('/'))
        ->withConfig($config)
        ->withLlm(new FakeLlmClient(['{"pass": true, "reason": "' . KEY_CANARY . '"}']))
        ->run();
    $dump = json_encode(ResultData::scenario($result), JSON_THROW_ON_ERROR);

    expect($dump)->toContain('[REDACTED]')
        ->and($dump)->not->toContain(HEADER_CANARY)
        ->and($dump)->not->toContain(KEY_CANARY);
});
