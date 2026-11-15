<?php

declare(strict_types=1);

use Desemax\AgentHarness\Config\ConfigLoader;
use Desemax\AgentHarness\Config\InvalidConfigException;
use Desemax\AgentHarness\Scenario\ScenarioLoader;
use Desemax\AgentHarness\Scenario\YamlException;
use Desemax\AgentHarness\Scenario\YamlReader;

function yamlFile(string $contents): string
{
    $file = sys_get_temp_dir() . '/agent-harness-yaml-' . bin2hex(random_bytes(4)) . '.yaml';
    file_put_contents($file, $contents);

    return $file;
}

it('rejects PHP object, constant and other PHP tags with a clean validation error', function (string $yaml, int $line): void {
    $errors = (new ScenarioLoader())->check(yamlFile($yaml));

    expect($errors)->toHaveCount(1)
        ->and($errors[0]->path)->toBe('(root)')
        ->and($errors[0]->reason)->toBe(sprintf('PHP tags such as !php/object and !php/const are not allowed (line %d). Write plain values instead.', $line));
})->with([
    'object' => ["name: !php/object 'O:8:\"stdClass\":0:{}'\n", 1],
    'const' => ["name: x\nmax_turns: !php/const PHP_INT_MAX\n", 2],
    'double bang' => ["name: x\nuser:\n  mode: !!php/object:stdClass {}\n", 3],
    'in a list' => ["expect:\n  - said: [!php/const PHP_EOL]\n", 2],
]);

it('rejects PHP tags in harness.yaml too', function (): void {
    expect(fn(): mixed => (new ConfigLoader())->load(yamlFile("agent:\n  url: !php/const PHP_EOL\n")))
        ->toThrow(InvalidConfigException::class, 'PHP tags such as !php/object and !php/const are not allowed (line 2)');
});

it('refuses "!php/" even inside a quoted string, to stay on the safe side', function (): void {
    expect(fn(): mixed => (new YamlReader())->parse("a: 'x !php/const y'\n"))->toThrow(YamlException::class);
});

it('allows an exclamation mark that is not a PHP tag', function (): void {
    expect((new YamlReader())->parse("a: Hello!php\nb: 'Wow!'\n"))->toBe(['a' => 'Hello!php', 'b' => 'Wow!']);
});

it('rejects custom tags', function (): void {
    expect(fn(): mixed => (new YamlReader())->parse("a: !custom x\n"))->toThrow(YamlException::class, 'invalid YAML: Tags support is not enabled');
});

it('caps the file size', function (): void {
    $reader = new YamlReader(maxBytes: 10);

    expect(fn(): mixed => $reader->read(yamlFile("a: 12345678\n")))->toThrow(YamlException::class, 'file is 12 bytes; the limit is 10 bytes')
        ->and(fn(): mixed => $reader->parse("a: 12345678\n"))->toThrow(YamlException::class, 'YAML is 12 bytes; the limit is 10 bytes')
        ->and($reader->parse("a: 1234\n"))->toBe(['a' => 1234])
        ->and(YamlReader::MAX_BYTES)->toBe(1_048_576);
});

it('caps the nesting depth', function (): void {
    $reader = new YamlReader(maxDepth: 3);

    expect($reader->parse("a:\n  b:\n    c: 1\n"))->toBe(['a' => ['b' => ['c' => 1]]])
        ->and(fn(): mixed => $reader->parse("a:\n  b:\n    c:\n      d: 1\n"))->toThrow(YamlException::class, 'nested more than 3 levels deep')
        ->and(YamlReader::MAX_DEPTH)->toBe(32);
});

it('caps the number of values, which bounds alias expansion', function (): void {
    $yaml = "a: &a [1, 2, 3]\nb: [*a, *a, *a]\n";

    expect((new YamlReader(maxNodes: 18))->parse($yaml))->toBeArray()
        ->and(fn(): mixed => (new YamlReader(maxNodes: 17))->parse($yaml))->toThrow(YamlException::class, 'more than 17 values')
        ->and(YamlReader::MAX_NODES)->toBe(100_000);
});

it('reports an unreadable path', function (): void {
    expect(fn(): mixed => (new YamlReader())->read('/nonexistent/x.yaml'))->toThrow(YamlException::class, 'cannot read the file /nonexistent/x.yaml');
});
