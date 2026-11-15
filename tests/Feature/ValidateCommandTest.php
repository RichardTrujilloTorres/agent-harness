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

it('writes stable JSON for valid and invalid files', function (string $path, int $exit, bool $ok, int $valid, int $invalid): void {
    $tester = new CommandTester((new Application())->find('validate'));
    $tester->execute(['path' => $path, '--format' => 'json']);
    $json = jsonArray($tester->getDisplay());

    expect($tester->getStatusCode())->toBe($exit)
        ->and($json['schema_version'])->toBe(1)
        ->and($json['command'])->toBe('validate')
        ->and($json['ok'])->toBe($ok)
        ->and($json['exit_code'])->toBe($exit)
        ->and(arrayAt($json, 'summary'))->toBe(['files' => $valid + $invalid, 'valid' => $valid, 'invalid' => $invalid])
        ->and(arrayAt($json, 'files'))->toHaveCount($valid + $invalid);
})->with([
    'valid' => [fixture('valid'), 0, true, 3, 0],
    'mixed' => [fixture('mixed'), 1, false, 1, 1],
]);

it('writes JSON errors for a missing path and a directory without scenarios', function (string $path, int $exit, string $message): void {
    $empty = $message === 'No .yaml or .yml files found';
    if ($empty) {
        mkdir($path);
    }
    $tester = new CommandTester((new Application())->find('validate'));
    $tester->execute(['path' => $path, '--format' => 'json']);
    $json = jsonArray($tester->getDisplay());
    if ($empty) {
        rmdir($path);
    }

    expect($tester->getStatusCode())->toBe($exit)
        ->and($json['ok'])->toBeFalse()
        ->and($json['error'])->toContain($message);
})->with([
    'missing' => [fixture('nope'), 2, 'Path does not exist'],
    'empty directory' => [sys_get_temp_dir() . '/agent-harness-empty-' . bin2hex(random_bytes(4)), 1, 'No .yaml or .yml files found'],
]);

it('rejects an unknown output format with a useful text error', function (): void {
    $tester = new CommandTester((new Application())->find('validate'));
    $tester->execute(['path' => fixture('valid'), '--format' => 'xml']);

    expect($tester->getStatusCode())->toBe(2)
        ->and($tester->getDisplay())->toContain('--format must be "text" or "json".');
});
