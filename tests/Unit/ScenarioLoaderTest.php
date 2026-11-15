<?php

declare(strict_types=1);

use Desemax\AgentHarness\Scenario\ExpectationType;
use Desemax\AgentHarness\Scenario\InvalidScenarioException;
use Desemax\AgentHarness\Scenario\Scenario;
use Desemax\AgentHarness\Scenario\ScenarioLoader;
use Desemax\AgentHarness\Scenario\UserMode;

it('loads a valid scenario file into value objects', function (): void {
    $scenarios = (new ScenarioLoader())->load(fixture('valid/full.yaml'));

    expect($scenarios)->toHaveCount(1);
    $s = $scenarios[0];

    expect($s)->toBeInstanceOf(Scenario::class)
        ->and($s->name)->toBe('Full scenario')
        ->and($s->persona)->toBe('A regular customer')
        ->and($s->goal)->toBe('Book a table')
        ->and($s->maxTurns)->toBe(6)
        ->and($s->user->mode)->toBe(UserMode::Scripted)
        ->and($s->user->turns)->toBe(['Hi', 'Table for 2'])
        ->and($s->expect)->toHaveCount(7)
        ->and($s->expect[0]->type)->toBe(ExpectationType::ToolCalled)
        ->and($s->expect[0]->payload)->toBe(['name' => 'create_booking', 'args' => ['party_size' => 2]])
        ->and($s->expect[1]->type)->toBe(ExpectationType::Said)
        ->and($s->expect[1]->payload)->toBe('confirmed')
        ->and($s->expect[3]->payload)->toBeFalse()
        ->and($s->expect[5]->payload)->toBe(2000)
        ->and($s->file)->toBe(fixture('valid/full.yaml'));
});

it('loads a directory recursively, .yaml and .yml, sorted', function (): void {
    $scenarios = (new ScenarioLoader())->load(fixture('valid'));

    expect(array_map(fn (Scenario $s): string => $s->name, $scenarios))
        ->toBe(['Full scenario', 'LLM user without turns', 'LLM user with an opening line']);
});

it('applies defaults for optional fields', function (): void {
    $s = (new ScenarioLoader())->load(fixture('valid/nested/llm-mode.yml'))[0];

    expect($s->maxTurns)->toBe(10)
        ->and($s->persona)->toBeNull()
        ->and($s->goal)->toBeNull()
        ->and($s->user->mode)->toBe(UserMode::Llm)
        ->and($s->user->turns)->toBe([])
        ->and($s->user->opening)->toBeNull();
});

it('reads the opening line of an LLM user', function (): void {
    $s = (new ScenarioLoader())->load(fixture('valid/nested/llm-opening.yaml'))[0];

    expect($s->user->opening)->toBe('Hi, are you open on Sundays?')
        ->and($s->goal)->toBe('Find out when the shop opens on Sunday');
});

it('throws with all errors when a file is invalid', function (): void {
    try {
        (new ScenarioLoader())->load(fixture('invalid/multiple-errors.yaml'));
        $this->fail('Expected InvalidScenarioException');
    } catch (InvalidScenarioException $e) {
        expect(count($e->errors))->toBeGreaterThan(1);
    }
});

it('throws on a missing path', function (): void {
    (new ScenarioLoader())->load(fixture('does-not-exist'));
})->throws(InvalidArgumentException::class);
