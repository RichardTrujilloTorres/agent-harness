<?php

declare(strict_types=1);

use Desemax\AgentHarness\Application;
use Symfony\Component\Console\Tester\CommandTester;

it('lists scenario names, modes, expectation counts and files', function (): void {
    $tester = new CommandTester((new Application())->find('list'));
    $tester->execute(['path' => fixture('valid')]);
    $display = $tester->getDisplay();

    expect($tester->getStatusCode())->toBe(0)
        ->and($display)->toContain('NAME')
        ->and($display)->toContain('MODE')
        ->and($display)->toContain('EXPECTATIONS')
        ->and($display)->toMatch('/Full scenario\s+scripted\s+7\s+' . preg_quote(fixture('valid/full.yaml'), '/') . '/')
        ->and($display)->toMatch('/LLM user without turns\s+llm\s+1\s+/')
        ->and($display)->toMatch('/LLM user with an opening line\s+llm\s+1\s+/')
        ->and($display)->toContain('3 scenario(s)');
});

it('reports invalid scenarios instead of listing them', function (): void {
    $tester = new CommandTester((new Application())->find('list'));
    $tester->execute(['path' => fixture('mixed')]);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('FAIL ' . fixture('mixed/bad.yaml'))
        ->and($tester->getDisplay())->toContain('2 file(s), 1 valid, 1 invalid');
});

it('reports missing paths and directories without scenarios', function (string $path, int $exit, string $message): void {
    if (str_contains($path, 'agent-harness-list-empty')) {
        mkdir($path);
    }
    $tester = new CommandTester((new Application())->find('list'));
    $tester->execute(['path' => $path]);

    expect($tester->getStatusCode())->toBe($exit)
        ->and($tester->getDisplay())->toContain($message);
})->with([
    'missing' => [fixture('nope'), 2, 'Path does not exist'],
    'empty' => [sys_get_temp_dir() . '/agent-harness-list-empty-' . bin2hex(random_bytes(4)), 1, 'No .yaml or .yml files found'],
]);
