<?php

declare(strict_types=1);

use Desemax\AgentHarness\Application;
use Desemax\AgentHarness\Command\RunCommand;
use Desemax\AgentHarness\Config\ConfigLoader;
use Desemax\AgentHarness\Config\InvalidConfigException;
use Desemax\AgentHarness\Scenario\ScenarioLoader;
use Desemax\AgentHarness\Scenario\YamlReader;
use Desemax\AgentHarness\Tests\Support\DocsCorpus;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Tester\CommandTester;

/*
 * Keeps the documentation site in docs/ true to the code: command lines, YAML examples,
 * exit codes and the CLI reference are checked against the real application.
 */

function docs(): DocsCorpus
{
    return DocsCorpus::load(projectRoot() . '/docs');
}

/**
 * The commands a reader can run: everything the application registers except hidden ones.
 *
 * @return array<string, Command>
 */
function visibleCommands(): array
{
    $commands = [];
    foreach ((new Application())->all() as $name => $command) {
        if (!$command->isHidden()) {
            $commands[(string) $name] = $command;
        }
    }
    ksort($commands);

    return $commands;
}

/**
 * Long option names of a definition, plus the "no-" names of negatable options.
 *
 * @return list<string>
 */
function definitionOptions(InputDefinition $definition): array
{
    $names = [];
    foreach ($definition->getOptions() as $option) {
        $names[] = $option->getName();
        if ($option->isNegatable()) {
            $names[] = 'no-' . $option->getName();
        }
    }
    sort($names);

    return $names;
}

/**
 * Exit codes listed in a command's help text, e.g. "  0  every file is valid".
 *
 * @return list<int>
 */
function helpExitCodes(Command $command): array
{
    preg_match_all('/^\s+(\d+)\s{2,}\S/m', $command->getHelp(), $matches);

    return array_map(intval(...), $matches[1]);
}

/**
 * A YAML block parsed as plain data.
 */
function docsYaml(string $code): mixed
{
    return (new YamlReader())->parse($code);
}

function isScenarioBlock(mixed $data): bool
{
    return is_array($data) && !array_is_list($data) && (array_key_exists('expect', $data) || array_key_exists('user', $data));
}

function isConfigBlock(mixed $data): bool
{
    return is_array($data) && !array_is_list($data)
        && array_diff(array_map(strval(...), array_keys($data)), ['agent', 'request', 'response', 'llm']) === [];
}

it('finds the documentation pages', function (): void {
    expect(array_keys(docs()->pages))->toContain('index.md', 'quickstart.md', 'reference/cli.md', 'reference/exit-codes.md');
});

it('uses only real commands and options in every documented command line', function (): void {
    $app = new Application();
    $global = $app->getDefinition();
    $lines = docs()->commandLines();
    $problems = [];

    foreach ($lines as $line) {
        $args = $line['args'];
        $commandName = null;
        foreach ($args as $arg) {
            if (!str_starts_with($arg, '-')) {
                $commandName = $arg;
                break;
            }
        }
        if ($commandName !== null && !$app->has($commandName)) {
            $problems[] = "{$line['page']}: unknown command \"{$commandName}\" in: {$line['line']}";
            continue;
        }
        $definition = new InputDefinition();
        $definition->addOptions($global->getOptions());
        if ($commandName !== null) {
            $definition->addOptions($app->get($commandName)->getDefinition()->getOptions());
        }
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                $name = explode('=', substr($arg, 2), 2)[0];
                if (!$definition->hasOption($name) && !$definition->hasNegation($name)) {
                    $problems[] = "{$line['page']}: unknown option --{$name} in: {$line['line']}";
                }
            } elseif (preg_match('/^-([A-Za-z])/', $arg, $short) === 1 && !$definition->hasShortcut($short[1])) {
                $problems[] = "{$line['page']}: unknown option -{$short[1]} in: {$line['line']}";
            }
        }
    }

    expect($lines)->not->toBeEmpty()
        ->and($problems)->toBe([]);
});

it('only shows scenario YAML that passes the validator, unless marked invalid', function (): void {
    $loader = new ScenarioLoader();
    $checked = 0;
    $problems = [];

    foreach (docs()->blocks('yaml') as $block) {
        $data = docsYaml($block['code']);
        if (!isScenarioBlock($data)) {
            continue;
        }
        $file = tempnam(sys_get_temp_dir(), 'agent-harness-docs-') . '.yaml';
        file_put_contents($file, $block['code']);
        try {
            $errors = $loader->check($file);
        } finally {
            unlink($file);
        }
        $checked++;
        $invalid = $block['before'] === DocsCorpus::INVALID_MARKER;
        if ($invalid && $errors === []) {
            $problems[] = "{$block['page']}: a block marked invalid passes the validator:\n{$block['code']}";
        }
        if (!$invalid && $errors !== []) {
            $problems[] = "{$block['page']}: {$errors[0]->path}: {$errors[0]->reason}\n{$block['code']}";
        }
    }

    expect($checked)->toBeGreaterThan(0)
        ->and($problems)->toBe([]);
});

it('only shows harness.yaml blocks that pass config validation', function (): void {
    $checked = 0;
    $problems = [];

    foreach (docs()->blocks('yaml') as $block) {
        $data = docsYaml($block['code']);
        if (!isConfigBlock($data)) {
            continue;
        }
        $checked++;
        try {
            (new ConfigLoader())->fromArray($data, $block['page']);
        } catch (InvalidConfigException $e) {
            $problems[] = "{$block['page']}: {$e->errors[0]->path}: {$e->errors[0]->reason}";
        }
    }

    expect($checked)->toBeGreaterThan(0)
        ->and($problems)->toBe([]);
});

it('documents the exit codes of run and validate as the commands define them', function (string $command): void {
    $documented = DocsCorpus::exitCodes(DocsCorpus::section(docs()->page('reference/exit-codes.md'), "`{$command}`"));

    expect(array_keys($documented))->toBe(helpExitCodes((new Application())->get($command)));
})->with(['run', 'validate']);

it('documents the run exit codes that the code returns', function (): void {
    $documented = array_keys(DocsCorpus::exitCodes(DocsCorpus::section(docs()->page('reference/exit-codes.md'), '`run`')));

    expect($documented)->toBe([
        RunCommand::EXIT_PASSED,
        RunCommand::EXIT_FAILED,
        RunCommand::EXIT_BAD_INPUT,
        RunCommand::EXIT_AGENT_ERROR,
    ]);
});

it('documents the list and init exit codes that the commands return', function (): void {
    $root = sys_get_temp_dir() . '/agent-harness-docs-exit-' . bin2hex(random_bytes(4));
    mkdir($root . '/invalid', 0o777, true);
    file_put_contents($root . '/invalid/broken.yaml', "name: broken\n");
    $cwd = (string) getcwd();
    chdir($root);
    try {
        $list = static function (string $path): int {
            $tester = new CommandTester((new Application())->get('list'));

            return $tester->execute(['path' => $path]);
        };
        $init = static fn(): int => (new CommandTester((new Application())->get('init')))->execute([]);
        $observed = [
            'list' => [$list(projectRoot() . '/examples/scenarios'), $list($root . '/invalid'), $list($root . '/missing')],
            'init' => [$init(), $init()],
        ];
    } finally {
        chdir($cwd);
        exec('rm -rf ' . escapeshellarg($root));
    }
    $page = docs()->page('reference/exit-codes.md');

    expect($observed)->toBe(['list' => [0, 1, 2], 'init' => [0, 1]])
        ->and(array_keys(DocsCorpus::exitCodes(DocsCorpus::section($page, '`list`'))))->toBe([0, 1, 2])
        ->and(array_keys(DocsCorpus::exitCodes(DocsCorpus::section($page, '`init`'))))->toBe([0, 1]);
});

it('shows the same exit codes in the CLI reference and on the exit codes page', function (string $command): void {
    $cli = DocsCorpus::exitCodes(DocsCorpus::section(docs()->page('reference/cli.md'), "`{$command}`"));
    $page = DocsCorpus::exitCodes(DocsCorpus::section(docs()->page('reference/exit-codes.md'), "`{$command}`"));

    expect(array_keys($cli))->toBe(array_keys($page));
})->with(['init', 'validate', 'list', 'run']);

it('has a CLI reference section for exactly the commands the tool has', function (): void {
    $documented = DocsCorpus::codeHeadings(docs()->page('reference/cli.md'));
    sort($documented);

    expect($documented)->toBe(array_keys(visibleCommands()));
});

it('lists exactly the arguments and options of each command in the CLI reference', function (string $name): void {
    $command = visibleCommands()[$name];
    $global = definitionOptions((new Application())->getDefinition());
    $own = array_values(array_diff(definitionOptions($command->getDefinition()), $global));
    $section = DocsCorpus::section(docs()->page('reference/cli.md'), "`{$name}`");
    $rows = DocsCorpus::tableRows($section);
    $optionRows = array_values(array_filter($rows, static fn(array $row): bool => str_starts_with($row[0], '`-')));
    $argumentRows = array_values(array_filter($rows, static fn(array $row): bool => preg_match('/^`[a-z_]+`$/', $row[0]) === 1));
    $arguments = array_map(static fn(array $row): string => trim($row[0], '`'), $argumentRows);
    sort($arguments);
    $expectedArguments = array_keys($command->getDefinition()->getArguments());
    sort($expectedArguments);

    expect(DocsCorpus::optionNames($optionRows))->toBe($own)
        ->and($arguments)->toBe($expectedArguments);

    foreach ($command->getDefinition()->getOptions() as $option) {
        $default = $option->getDefault();
        if (!in_array($option->getName(), $own, true) || !is_string($default)) {
            continue;
        }
        $row = array_values(array_filter($optionRows, static fn(array $r): bool => str_starts_with($r[0], '`--' . $option->getName())));
        expect($row[0][1] ?? '')->toContain('`' . $default . '`');
    }
})->with(fn(): array => array_keys(visibleCommands()));

it('lists exactly the global options in the CLI reference', function (): void {
    $section = DocsCorpus::section(docs()->page('reference/cli.md'), 'Global options');
    $documented = DocsCorpus::optionNames(DocsCorpus::tableRows($section));
    $definition = (new Application())->getDefinition();
    $expected = definitionOptions($definition);
    foreach ($definition->getOptions() as $option) {
        foreach (explode('|', (string) $option->getShortcut()) as $shortcut) {
            if ($shortcut !== '') {
                $expected[] = $shortcut;
            }
        }
    }
    sort($expected);

    expect($documented)->toBe(array_values(array_unique($expected)));
});

it('shows the real version in the documented --version output', function (): void {
    expect(docs()->page('reference/cli.md'))->toContain(Application::NAME . ' ' . Application::VERSION);
});
