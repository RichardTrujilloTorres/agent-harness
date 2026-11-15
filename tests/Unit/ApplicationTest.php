<?php

declare(strict_types=1);

use Desemax\AgentHarness\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

it('boots with name and version', function (): void {
    $app = new Application();

    expect($app->getName())->toBe('agent-harness')
        ->and($app->getVersion())->toBe('0.1.0');
});

it('prints the command overview when no command is given', function (): void {
    $app = new Application();
    $app->setAutoExit(false);
    $tester = new ApplicationTester($app);

    $exit = $tester->run([]);

    $display = $tester->getDisplay();

    expect($exit)->toBe(0)
        ->and($display)->toContain('Available commands:')
        ->and($display)->toContain('run ')
        ->and($display)->toContain('validate ')
        ->and(str_contains($display, 'Not enough arguments'))->toBeFalse()
        ->and(str_contains($display, 'Cannot add a required argument'))->toBeFalse();
});

it('still prints the version and the help without a command', function (array $input, string $expected, string $absent): void {
    $app = new Application();
    $app->setAutoExit(false);
    $tester = new ApplicationTester($app);

    $exit = $tester->run($input);

    $display = $tester->getDisplay();

    expect($exit)->toBe(0)
        ->and($display)->toContain($expected)
        ->and(str_contains($display, $absent))->toBeFalse();
})->with([
    'version' => [['--version' => true], 'agent-harness 0.1.0', 'Available commands:'],
    'short version' => [['-V' => true], 'agent-harness 0.1.0', 'Available commands:'],
    'help' => [['--help' => true], 'Description:', 'Available commands:'],
    'short help' => [['-h' => true], 'Description:', 'Available commands:'],
]);

it('runs a named command as before', function (): void {
    $app = new Application();
    $app->setAutoExit(false);
    $tester = new ApplicationTester($app);

    $exit = $tester->run(['command' => 'list', 'path' => projectRoot() . '/examples/scenarios']);

    $display = $tester->getDisplay();

    expect($exit)->toBe(0)
        ->and($display)->toContain('Happy path booking')
        ->and(str_contains($display, 'Available commands:'))->toBeFalse();
});
