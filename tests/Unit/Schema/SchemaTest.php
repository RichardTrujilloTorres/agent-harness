<?php

declare(strict_types=1);

use Desemax\AgentHarness\Config\ConfigLoader;
use Desemax\AgentHarness\Config\InvalidConfigException;
use Desemax\AgentHarness\Scenario\ScenarioLoader;
use Desemax\AgentHarness\Scenario\YamlException;
use Desemax\AgentHarness\Scenario\YamlReader;
use JsonSchema\Validator;

function schema(string $name): stdClass
{
    $schema = json_decode((string) file_get_contents(projectRoot() . '/schema/' . $name . '.schema.json'), flags: JSON_THROW_ON_ERROR);
    if (!$schema instanceof stdClass) {
        throw new UnexpectedValueException('Schema must be a JSON object.');
    }

    return $schema;
}

function matchesSchema(mixed $data, stdClass $schema): bool
{
    $value = json_decode(json_encode($data, JSON_THROW_ON_ERROR));
    $validator = new Validator();
    $validator->validate($value, $schema);

    return $validator->isValid();
}

/** @return list<string> */
function scenarioFixtures(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(fixture(''), FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || !in_array($file->getExtension(), ['yaml', 'yml'], true)) {
            continue;
        }
        if (!str_ends_with($file->getPathname(), 'harness.yaml')) {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

it('uses draft 2020-12 for both schemas', function (): void {
    foreach (['scenario', 'harness'] as $name) {
        expect(schema($name)->{'$schema'})->toBe('https://json-schema.org/draft/2020-12/schema');
    }
});

it('agrees with the PHP scenario validator on every YAML fixture', function (): void {
    $loader = new ScenarioLoader();
    $yaml = new YamlReader();
    $schema = schema('scenario');
    $checked = 0;

    foreach (scenarioFixtures() as $file) {
        try {
            $data = $yaml->read($file);
            $schemaValid = matchesSchema($data, $schema);
        } catch (YamlException) {
            $schemaValid = false;
        }
        $phpValid = $loader->check($file) === [];

        expect($schemaValid)->toBe($phpValid, 'Validator/schema disagreement: ' . $file);
        $checked++;
    }

    expect($checked)->toBeGreaterThan(30);
});

it('validates every example scenario against both validators', function (): void {
    $loader = new ScenarioLoader();
    $schema = schema('scenario');
    $examples = glob(projectRoot() . '/examples/scenarios/*.yaml');
    $llmExamples = glob(projectRoot() . '/examples/scenarios-llm/scenarios/*.yaml');
    $examples = $examples === false ? [] : $examples;
    $llmExamples = $llmExamples === false ? [] : $llmExamples;
    $files = [...$examples, ...$llmExamples];

    expect($files)->toHaveCount(8);
    foreach ($files as $file) {
        $data = (new YamlReader())->read($file);
        expect($loader->check($file))->toBe([], $file)
            ->and(matchesSchema($data, $schema))->toBeTrue($file)
            ->and((string) file_get_contents($file))->toContain('# yaml-language-server: $schema=');
    }
});

it('agrees with the PHP config validator on fixtures and invalid values', function (): void {
    $schema = schema('harness');
    $loader = new ConfigLoader();
    $files = [fixture('llm/harness.yaml'), fixture('remapped-agent/harness.yaml'), projectRoot() . '/examples/scenarios-llm/harness.example.yaml'];

    foreach ($files as $file) {
        $data = (new YamlReader())->read($file);
        expect($loader->load($file))->toBeObject()
            ->and(matchesSchema($data, $schema))->toBeTrue($file);
    }

    $invalid = [
        'unknown section' => ['agenta' => []],
        'bad URL' => ['agent' => ['url' => 'localhost']],
        'bad timeout' => ['agent' => ['timeout_ms' => 0]],
        'unknown response field' => ['response' => ['replies' => 'reply']],
        'missing LLM model' => ['llm' => ['base_url' => 'https://llm.test/v1']],
    ];
    foreach ($invalid as $name => $data) {
        try {
            $loader->fromArray($data);
            $phpValid = true;
        } catch (InvalidConfigException) {
            $phpValid = false;
        }

        expect($phpValid)->toBeFalse($name)
            ->and(matchesSchema($data, $schema))->toBeFalse($name);
    }
});
