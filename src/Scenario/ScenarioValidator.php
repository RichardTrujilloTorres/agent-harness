<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Scenario;

/**
 * Validates raw (parsed YAML) scenario data and collects every error found.
 */
final class ScenarioValidator
{
    private const TOP_LEVEL_KEYS = ['name', 'persona', 'goal', 'user', 'max_turns', 'expect'];
    private const USER_KEYS = ['mode', 'turns', 'opening'];
    private const TOOL_CALLED_KEYS = ['name', 'args'];

    /** @var list<ValidationError> */
    private array $errors = [];

    private string $file = '';

    /**
     * @return list<ValidationError>
     */
    public function validate(mixed $data, string $file): array
    {
        $this->errors = [];
        $this->file = $file;

        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            $this->error('(root)', 'scenario must be a map');

            return $this->errors;
        }

        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::TOP_LEVEL_KEYS, true)) {
                $this->error((string) $key, 'unknown field');
            }
        }

        $this->requireNonEmptyString($data, 'name', 'name');
        $this->optionalString($data, 'persona', 'persona');
        $this->optionalString($data, 'goal', 'goal');
        $this->validateUser($data);

        if (array_key_exists('max_turns', $data)) {
            $this->positiveInt($data['max_turns'], 'max_turns');
        }

        $this->validateExpect($data);

        return $this->errors;
    }

    /**
     * @param array<mixed> $data
     */
    private function validateUser(array $data): void
    {
        if (!array_key_exists('user', $data)) {
            $this->error('user', 'required field is missing');

            return;
        }

        $user = $data['user'];
        if (!$this->isMap($user)) {
            $this->error('user', 'must be a map');

            return;
        }

        foreach (array_keys($user) as $key) {
            if (!in_array($key, self::USER_KEYS, true)) {
                $this->error('user.' . $key, 'unknown field');
            }
        }

        $mode = null;
        if (!array_key_exists('mode', $user)) {
            $this->error('user.mode', 'required field is missing');
        } elseif (!is_string($user['mode']) || ($mode = UserMode::tryFrom($user['mode'])) === null) {
            $this->error('user.mode', 'must be one of: scripted, llm');
        }

        if (array_key_exists('turns', $user)) {
            $turns = $user['turns'];
            if (!is_array($turns) || ($turns !== [] && !array_is_list($turns))) {
                $this->error('user.turns', 'must be a list of strings');
            } else {
                foreach ($turns as $i => $turn) {
                    if (!is_string($turn) || trim($turn) === '') {
                        $this->error(sprintf('user.turns[%d]', $i), 'must be a non-empty string');
                    }
                }
                if ($turns === [] && $mode === UserMode::Scripted) {
                    $this->error('user.turns', 'must not be empty when mode is scripted');
                }
            }
        } elseif ($mode === UserMode::Scripted) {
            $this->error('user.turns', 'required when mode is scripted');
        }

        if (array_key_exists('opening', $user)) {
            if (!is_string($user['opening']) || trim($user['opening']) === '') {
                $this->error('user.opening', 'must be a non-empty string');
            } elseif ($mode === UserMode::Scripted) {
                $this->error('user.opening', 'only used when mode is llm; put the first message in turns');
            }
        }
    }

    /**
     * @param array<mixed> $data
     */
    private function validateExpect(array $data): void
    {
        if (!array_key_exists('expect', $data)) {
            $this->error('expect', 'required field is missing');

            return;
        }

        $expect = $data['expect'];
        if (!is_array($expect) || ($expect !== [] && !array_is_list($expect))) {
            $this->error('expect', 'must be a list');

            return;
        }

        if ($expect === []) {
            $this->error('expect', 'must not be empty');

            return;
        }

        foreach ($expect as $i => $item) {
            $path = sprintf('expect[%d]', $i);

            if (!$this->isMap($item) || $item === []) {
                $this->error($path, 'must be a map with exactly one key');
                continue;
            }

            if (count($item) !== 1) {
                $this->error($path, sprintf('must have exactly one key, found %d: %s', count($item), implode(', ', array_keys($item))));
                continue;
            }

            $key = (string) array_key_first($item);
            $type = ExpectationType::tryFrom($key);
            if ($type === null) {
                $this->error($path . '.' . $key, 'unknown expectation; allowed: ' . implode(', ', array_column(ExpectationType::cases(), 'value')));
                continue;
            }

            $this->validateExpectationPayload($type, $item[$key], $path . '.' . $key);
        }
    }

    private function validateExpectationPayload(ExpectationType $type, mixed $payload, string $path): void
    {
        switch ($type) {
            case ExpectationType::ToolCalled:
                if (!$this->isMap($payload) || $payload === []) {
                    $this->error($path, 'must be a map with at least "name"');

                    return;
                }
                foreach (array_keys($payload) as $key) {
                    if (!in_array($key, self::TOOL_CALLED_KEYS, true)) {
                        $this->error($path . '.' . $key, 'unknown field');
                    }
                }
                $this->requireNonEmptyString($payload, 'name', $path . '.name');
                if (array_key_exists('args', $payload) && !$this->isMap($payload['args'])) {
                    $this->error($path . '.args', 'must be a map');
                }

                return;

            case ExpectationType::Said:
            case ExpectationType::NeverSaid:
            case ExpectationType::Judge:
                if (!is_string($payload) || trim($payload) === '') {
                    $this->error($path, 'must be a non-empty string');
                }

                return;

            case ExpectationType::Handoff:
                if (!is_bool($payload)) {
                    $this->error($path, 'must be a boolean');
                }

                return;

            case ExpectationType::MaxTurns:
            case ExpectationType::LatencyMsUnder:
                $this->positiveInt($payload, $path);

                return;
        }
    }

    /**
     * @param array<mixed> $data
     */
    private function requireNonEmptyString(array $data, string $key, string $path): void
    {
        if (!array_key_exists($key, $data)) {
            $this->error($path, 'required field is missing');
        } elseif (!is_string($data[$key]) || trim($data[$key]) === '') {
            $this->error($path, 'must be a non-empty string');
        }
    }

    /**
     * @param array<mixed> $data
     */
    private function optionalString(array $data, string $key, string $path): void
    {
        if (array_key_exists($key, $data) && !is_string($data[$key])) {
            $this->error($path, 'must be a string');
        }
    }

    private function positiveInt(mixed $value, string $path): void
    {
        if (!is_int($value)) {
            $this->error($path, 'must be an integer');
        } elseif ($value < 1) {
            $this->error($path, 'must be >= 1');
        }
    }

    /**
     * @phpstan-assert-if-true array<mixed> $value
     */
    private function isMap(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    private function error(string $path, string $reason): void
    {
        $this->errors[] = new ValidationError($this->file, $path, $reason);
    }
}
