<?php

declare(strict_types=1);

use Desemax\AgentHarness\Scenario\ScenarioLoader;
use Desemax\AgentHarness\Scenario\Suggest;

it('suggests the closest key or expectation type', function (string $given, array $allowed, ?string $closest): void {
    expect(Suggest::closest($given, stringList($allowed)))->toBe($closest);
})->with([
    'misspelled field' => ['nmae', ['name', 'persona', 'goal'], 'name'],
    'misspelled expectation' => ['sajd', ['said', 'never_said', 'judge'], 'said'],
    'case and whitespace' => [' NAME ', ['name', 'persona'], 'name'],
    'too distant' => ['timeout', ['name', 'persona', 'goal'], null],
    'no candidates' => ['name', [], null],
]);

it('explains the suggestion and every allowed value', function (): void {
    expect(Suggest::unknown('field', 'nmae', ['name', 'persona']))
        ->toBe('unknown field "nmae"; did you mean "name"? allowed: name, persona');
});

it('includes did-you-mean suggestions in validation errors', function (): void {
    $file = sys_get_temp_dir() . '/agent-harness-suggest-' . bin2hex(random_bytes(4)) . '.yaml';
    file_put_contents($file, "nmae: Example\nuser: {mode: scripted, turns: [Hi]}\nexpect: [{sajd: hello}]\n");

    try {
        $errors = (new ScenarioLoader())->check($file);
    } finally {
        unlink($file);
    }

    expect($errors)->toHaveCount(3)
        ->and($errors[0]->reason)->toContain('did you mean "name"?')
        ->and($errors[1]->path)->toBe('name')
        ->and($errors[2]->reason)->toContain('did you mean "said"?');
});
