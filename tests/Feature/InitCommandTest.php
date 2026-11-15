<?php

declare(strict_types=1);

use Desemax\AgentHarness\Application;
use Desemax\AgentHarness\Config\ConfigLoader;
use Desemax\AgentHarness\Scenario\ScenarioLoader;
use Symfony\Component\Console\Tester\CommandTester;

it('creates a valid commented config and example scenario with next steps', function (): void {
    $dir = sys_get_temp_dir() . '/agent-harness-init-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $cwd = (string) getcwd();
    chdir($dir);
    try {
        $tester = new CommandTester((new Application())->find('init'));
        $tester->execute([]);
        $config = (new ConfigLoader())->load($dir . '/harness.yaml');
        $scenarios = (new ScenarioLoader())->load($dir . '/scenarios/example.yaml');
    } finally {
        chdir($cwd);
    }

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('Created ')
        ->and($tester->getDisplay())->toContain('harness.yaml')
        ->and($tester->getDisplay())->toContain('scenarios/example.yaml')
        ->and($tester->getDisplay())->toContain('Next steps:')
        ->and($tester->getDisplay())->toContain('agent-harness validate scenarios')
        ->and($config->url)->toBe('http://127.0.0.1:8787/chat')
        ->and($scenarios)->toHaveCount(1)
        ->and($scenarios[0]->name)->toBe('Example conversation');

    exec('rm -rf ' . escapeshellarg($dir));
});

it('never overwrites an existing file or creates the other file', function (): void {
    $dir = sys_get_temp_dir() . '/agent-harness-init-' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir . '/harness.yaml', "agent:\n  url: http://existing.test/chat\n");
    $cwd = (string) getcwd();
    chdir($dir);
    try {
        $tester = new CommandTester((new Application())->find('init'));
        $tester->execute([]);
    } finally {
        chdir($cwd);
    }

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('Refusing to overwrite ')
        ->and($tester->getDisplay())->toContain('harness.yaml')
        ->and((string) file_get_contents($dir . '/harness.yaml'))->toContain('http://existing.test/chat')
        ->and(is_dir($dir . '/scenarios'))->toBeFalse();

    unlink($dir . '/harness.yaml');
    rmdir($dir);
});

it('points the editor schema lines at the installed package schemas', function (): void {
    $dir = sys_get_temp_dir() . '/agent-harness-init-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $cwd = (string) getcwd();
    chdir($dir);
    try {
        (new CommandTester((new Application())->find('init')))->execute([]);
        $files = ['harness.yaml' => $dir . '/harness.yaml', 'scenarios/example.yaml' => $dir . '/scenarios/example.yaml'];
        $targets = [];
        foreach ($files as $name => $file) {
            $firstLine = strtok((string) file_get_contents($file), "\n");
            expect($firstLine)->toStartWith('# yaml-language-server: $schema=');
            $relative = substr((string) $firstLine, strlen('# yaml-language-server: $schema='));
            $targets[$name] = $relative;
        }
    } finally {
        chdir($cwd);
        exec('rm -rf ' . escapeshellarg($dir));
    }

    // Resolved from each file's directory, both paths land in vendor/desemax/agent-harness/schema/.
    expect($targets)->toBe([
        'harness.yaml' => 'vendor/desemax/agent-harness/schema/harness.schema.json',
        'scenarios/example.yaml' => '../vendor/desemax/agent-harness/schema/scenario.schema.json',
    ])
        ->and(is_file(projectRoot() . '/schema/harness.schema.json'))->toBeTrue()
        ->and(is_file(projectRoot() . '/schema/scenario.schema.json'))->toBeTrue();
});
