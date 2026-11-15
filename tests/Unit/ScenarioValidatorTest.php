<?php

declare(strict_types=1);

use Desemax\AgentHarness\Scenario\ScenarioLoader;
use Desemax\AgentHarness\Scenario\ValidationError;

/**
 * @return list<array{string, string}>
 */
function errorsOf(string $fixture): array
{
    $file = fixture('invalid/' . $fixture . '.yaml');
    $errors = (new ScenarioLoader())->check($file);

    foreach ($errors as $error) {
        expect($error->file)->toBe($file);
    }

    return array_map(fn (ValidationError $e): array => [$e->path, $e->reason], $errors);
}

it('accepts the valid fixtures', function (string $file): void {
    expect((new ScenarioLoader())->check(fixture($file)))->toBe([]);
})->with(['valid/full.yaml', 'valid/nested/llm-mode.yml']);

it('reports a rule violation with field path and reason', function (string $fixture, string $path, string $reason): void {
    expect(errorsOf($fixture))->toContain([$path, $reason]);
})->with([
    'name required' => ['missing-name', 'name', 'required field is missing'],
    'name type' => ['name-not-string', 'name', 'must be a non-empty string'],
    'persona type' => ['persona-not-string', 'persona', 'must be a string'],
    'user required' => ['missing-user', 'user', 'required field is missing'],
    'user.mode required' => ['missing-mode', 'user.mode', 'required field is missing'],
    'user.mode enum' => ['invalid-mode', 'user.mode', 'must be one of: scripted, llm'],
    'turns required when scripted' => ['scripted-without-turns', 'user.turns', 'required when mode is scripted'],
    'turns non-empty when scripted' => ['scripted-empty-turns', 'user.turns', 'must not be empty when mode is scripted'],
    'turns are strings' => ['turn-not-string', 'user.turns[1]', 'must be a non-empty string'],
    'expect required' => ['missing-expect', 'expect', 'required field is missing'],
    'expect non-empty' => ['empty-expect', 'expect', 'must not be empty'],
    'expect item one key' => ['expect-two-keys', 'expect[0]', 'must have exactly one key, found 2: said, never_said'],
    'expect item known key' => ['expect-unknown-key', 'expect[1].sentiment', 'unknown expectation; allowed: tool_called, said, never_said, handoff, max_turns, latency_ms_under, judge'],
    'unknown top-level key' => ['unknown-top-level-key', 'timeout', 'unknown field'],
    'max_turns >= 1' => ['max-turns-zero', 'max_turns', 'must be >= 1'],
    'max_turns type' => ['max-turns-not-int', 'max_turns', 'must be an integer'],
    'latency_ms_under >= 1' => ['latency-zero', 'expect[1].latency_ms_under', 'must be >= 1'],
    'tool_called.name required' => ['tool-called-missing-name', 'expect[2].tool_called.name', 'required field is missing'],
    'tool_called.args map' => ['tool-called-args-not-map', 'expect[0].tool_called.args', 'must be a map'],
    'handoff bool' => ['handoff-not-bool', 'expect[0].handoff', 'must be a boolean'],
    'said non-empty' => ['said-empty', 'expect[0].said', 'must be a non-empty string'],
]);

it('reports exactly one error for a single-rule fixture', function (): void {
    expect(errorsOf('missing-name'))->toHaveCount(1);
});

it('reports invalid YAML as a root error', function (): void {
    $errors = errorsOf('invalid-yaml');

    expect($errors)->toHaveCount(1)
        ->and($errors[0][0])->toBe('(root)')
        ->and($errors[0][1])->toStartWith('invalid YAML:');
});

it('collects all errors in a file, not just the first', function (): void {
    expect(errorsOf('multiple-errors'))->toEqualCanonicalizing([
        ['colour', 'unknown field'],
        ['name', 'required field is missing'],
        ['user.turns', 'required when mode is scripted'],
        ['max_turns', 'must be >= 1'],
        ['expect[1].tool_called', 'must be a map with at least "name"'],
    ]);
});

it('rejects a root that is not a map', function (): void {
    $errors = (new Desemax\AgentHarness\Scenario\ScenarioValidator())->validate(['a', 'b'], 'x.yaml');

    expect($errors)->toHaveCount(1)
        ->and($errors[0]->path)->toBe('(root)');
});
