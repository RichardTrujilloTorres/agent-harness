<?php

declare(strict_types=1);

use Desemax\AgentHarness\Application;
use Symfony\Component\Console\Tester\CommandTester;

function runValidate(string $path): CommandTester
{
    $tester = new CommandTester((new Application())->find('validate'));
    $tester->execute(['path' => $path]);

    return $tester;
}

it('exits 0 and prints OK for every valid file', function (): void {
    $tester = runValidate(fixture('valid'));

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('OK  ' . fixture('valid/full.yaml'))
        ->and($tester->getDisplay())->toContain('OK  ' . fixture('valid/nested/llm-mode.yml'));
});

it('exits 1 and lists errors with field paths for invalid files', function (): void {
    $tester = runValidate(fixture('mixed'));
    $display = $tester->getDisplay();

    expect($tester->getStatusCode())->toBe(1)
        ->and($display)->toContain('OK  ' . fixture('mixed/good.yaml'))
        ->and($display)->toContain('FAIL ' . fixture('mixed/bad.yaml'))
        ->and($display)->toContain('- name: required field is missing')
        ->and($display)->toContain('2 file(s), 1 valid, 1 invalid');
});

it('exits 1 for a single invalid file', function (): void {
    expect(runValidate(fixture('invalid/latency-zero.yaml'))->getStatusCode())->toBe(1);
});

it('exits 2 when the path does not exist', function (): void {
    $tester = runValidate(fixture('nope'));

    expect($tester->getStatusCode())->toBe(2)
        ->and($tester->getDisplay())->toContain('Path does not exist');
});

it('exits with the same codes through the real binary', function (string $path, int $code): void {
    exec(sprintf('%s %s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(projectRoot() . '/bin/agent-harness') . ' validate', escapeshellarg($path)), $out, $exit);

    expect($exit)->toBe($code);
})->with([
    'valid' => [fixture('valid'), 0],
    'invalid' => [fixture('invalid'), 1],
    'missing' => [fixture('nope'), 2],
]);
