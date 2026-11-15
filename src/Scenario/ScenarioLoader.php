<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Scenario;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ScenarioLoader
{
    public function __construct(
        private readonly ScenarioValidator $validator = new ScenarioValidator(),
    ) {
    }

    /**
     * Loads one scenario file or every .yaml/.yml file under a directory (recursive).
     *
     * @return list<Scenario>
     *
     * @throws InvalidArgumentException when the path does not exist
     * @throws InvalidScenarioException when any file is invalid (errors of all files are collected)
     */
    public function load(string $path): array
    {
        $scenarios = [];
        $errors = [];

        foreach ($this->files($path) as $file) {
            $fileErrors = $this->check($file);
            if ($fileErrors !== []) {
                array_push($errors, ...$fileErrors);
                continue;
            }
            $scenarios[] = $this->hydrate($this->parse($file), $file);
        }

        if ($errors !== []) {
            throw new InvalidScenarioException($errors);
        }

        return $scenarios;
    }

    /**
     * @return list<string>
     *
     * @throws InvalidArgumentException when the path does not exist
     */
    public function files(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        if (!is_dir($path)) {
            throw new InvalidArgumentException(sprintf('Path does not exist: %s', $path));
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $info) {
            if (!$info instanceof SplFileInfo || !$info->isFile()) {
                continue;
            }
            if (in_array(strtolower($info->getExtension()), ['yaml', 'yml'], true)) {
                $files[] = $info->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Parses and validates one file, returning every error found.
     *
     * @return list<ValidationError>
     */
    public function check(string $file): array
    {
        try {
            $data = $this->parse($file);
        } catch (ParseException $e) {
            return [new ValidationError($file, '(root)', 'invalid YAML: ' . $e->getMessage())];
        }

        return $this->validator->validate($data, $file);
    }

    private function parse(string $file): mixed
    {
        return Yaml::parseFile($file);
    }

    private function hydrate(mixed $data, string $file): Scenario
    {
        $data = $this->map($data);
        $user = $this->map($data['user']);

        $turns = [];
        foreach ($this->list($user['turns'] ?? []) as $turn) {
            $turns[] = $this->string($turn);
        }

        $expect = [];
        foreach ($this->list($data['expect']) as $item) {
            $item = $this->map($item);
            $key = (string) array_key_first($item);
            $payload = $item[$key];
            $expect[] = new Expectation(
                ExpectationType::from($key),
                is_array($payload) ? $this->map($payload) : $this->scalar($payload),
            );
        }

        return new Scenario(
            name: $this->string($data['name']),
            user: new UserScript(
                UserMode::from($this->string($user['mode'])),
                $turns,
                isset($user['opening']) ? $this->string($user['opening']) : null,
            ),
            expect: $expect,
            maxTurns: isset($data['max_turns']) ? $this->int($data['max_turns']) : Scenario::DEFAULT_MAX_TURNS,
            persona: isset($data['persona']) ? $this->string($data['persona']) : null,
            goal: isset($data['goal']) ? $this->string($data['goal']) : null,
            file: $file,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function map(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \LogicException('Expected a map in validated scenario data.');
        }

        $map = [];
        foreach ($value as $k => $v) {
            $map[(string) $k] = $v;
        }

        return $map;
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \LogicException('Expected a list in validated scenario data.');
        }

        return array_values($value);
    }

    private function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \LogicException('Expected a string in validated scenario data.');
        }

        return $value;
    }

    private function int(mixed $value): int
    {
        if (!is_int($value)) {
            throw new \LogicException('Expected an integer in validated scenario data.');
        }

        return $value;
    }

    private function scalar(mixed $value): string|int|bool
    {
        if (!is_string($value) && !is_int($value) && !is_bool($value)) {
            throw new \LogicException('Expected a scalar in validated scenario data.');
        }

        return $value;
    }
}
