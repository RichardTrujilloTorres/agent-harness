<?php

declare(strict_types=1);
use Desemax\AgentHarness\Agent\AgentReply;
use Desemax\AgentHarness\Agent\ToolCall;
use Desemax\AgentHarness\Application;
use Desemax\AgentHarness\Config\HarnessConfig;
use Desemax\AgentHarness\Llm\LlmConfig;
use Desemax\AgentHarness\Llm\LlmOptions;
use Desemax\AgentHarness\Runner\ExpectationResult;
use Desemax\AgentHarness\Runner\ScenarioResult;
use Desemax\AgentHarness\Runner\SuiteResult;
use Desemax\AgentHarness\Runner\Transcript;
use Desemax\AgentHarness\Runner\Turn;
use Desemax\AgentHarness\Scenario\Expectation;
use Desemax\AgentHarness\Scenario\Scenario;
use Desemax\AgentHarness\Scenario\UserScript;
use Desemax\AgentHarness\Scenario\ValidationError;

/*
 * Architecture rules. The namespace layout, from the bottom up:
 *
 *   Scenario  (format, loader, validator: depends on nothing else in src)
 *   Agent, Llm, Config, Runner, Assertion, Report, Security
 *   Pest      (the only code that knows about Pest or PHPUnit)
 *   Command   (wires everything together; nothing depends on it)
 *   Application (the console entry point, registers the commands)
 */

const ROOT_NS = 'Desemax\AgentHarness';

/** Readonly value objects: every property is set once in the constructor. */
const VALUE_OBJECTS = [
    AgentReply::class,
    ToolCall::class,
    HarnessConfig::class,
    LlmConfig::class,
    LlmOptions::class,
    ExpectationResult::class,
    ScenarioResult::class,
    SuiteResult::class,
    Transcript::class,
    Turn::class,
    Expectation::class,
    Scenario::class,
    UserScript::class,
    ValidationError::class,
];

arch('every class in src declares strict types', function (): void {
    expect(ROOT_NS)->toUseStrictTypes();
});

arch('every test declares strict types', function (): void {
    expect('Desemax\AgentHarness\Tests')->toUseStrictTypes();
});

arch('src has no debugging or exit calls', function (): void {
    expect(ROOT_NS)->not()->toUse(['dd', 'dump', 'var_dump', 'var_export', 'print_r', 'debug_zval_dump', 'die', 'exit', 'eval', 'phpinfo']);
});

arch('value objects are readonly', function (): void {
    expect(VALUE_OBJECTS)->toBeReadonly()->toBeFinal();
});

arch('the Scenario namespace depends on nothing else in src', function (): void {
    expect('Desemax\AgentHarness\Scenario')->not()->toUse([
        'Desemax\AgentHarness\Agent',
        'Desemax\AgentHarness\Assertion',
        'Desemax\AgentHarness\Command',
        'Desemax\AgentHarness\Config',
        'Desemax\AgentHarness\Llm',
        'Desemax\AgentHarness\Pest',
        'Desemax\AgentHarness\Report',
        'Desemax\AgentHarness\Runner',
        'Desemax\AgentHarness\Security',
        Application::class,
    ]);
});

arch('nothing but the Application depends on Command', function (): void {
    expect([
        'Desemax\AgentHarness\Agent',
        'Desemax\AgentHarness\Assertion',
        'Desemax\AgentHarness\Config',
        'Desemax\AgentHarness\Llm',
        'Desemax\AgentHarness\Pest',
        'Desemax\AgentHarness\Report',
        'Desemax\AgentHarness\Runner',
        'Desemax\AgentHarness\Scenario',
        'Desemax\AgentHarness\Security',
    ])->not()->toUse('Desemax\AgentHarness\Command');
});

arch('only the Pest namespace references Pest and PHPUnit', function (): void {
    expect(ROOT_NS)->not()->toUse(['Pest', 'PHPUnit'])->ignoring('Desemax\AgentHarness\Pest');
});

arch('interfaces carry no Interface suffix', function (): void {
    expect(ROOT_NS)->interfaces()->not()->toHaveSuffix('Interface');
});

arch('classes are final', function (): void {
    expect(ROOT_NS)->classes()->toBeFinal();
});

it('names every exception class *Exception and nothing else', function (): void {
    $root = projectRoot() . '/src';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $checked = 0;
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php' || $file->getFilename() === 'functions.php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1, -4);
        $class = ROOT_NS . '\\' . str_replace('/', '\\', $relative);
        expect(class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class))->toBeTrue($class);

        $isThrowable = is_subclass_of($class, Throwable::class);
        $named = str_ends_with($class, 'Exception');
        expect($isThrowable)->toBe($named, sprintf('%s: throwable=%s, named *Exception=%s', $class, var_export($isThrowable, true), var_export($named, true)));
        $checked++;
    }

    expect($checked)->toBeGreaterThan(40);
});

it('declares strict types in every PHP file of the repository', function (): void {
    $missing = [];
    foreach (['src', 'tests', 'examples', 'bin'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(projectRoot() . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            $path = $file->getPathname();
            $isPhp = $file->getExtension() === 'php' || str_starts_with((string) file_get_contents($path, length: 32), '#!/usr/bin/env php');
            if ($isPhp && preg_match('/declare\(strict_types=1\);/', (string) file_get_contents($path)) !== 1) {
                $missing[] = $path;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('keeps exit in bin only', function (): void {
    $found = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(projectRoot() . '/src', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        foreach (PhpToken::tokenize((string) file_get_contents($file->getPathname())) as $token) {
            if ($token->is(T_EXIT)) {
                $found[] = $file->getPathname() . ':' . $token->line;
            }
        }
    }

    expect($found)->toBe([]);
});
