<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Assertion;

/**
 * Subset match of expected tool arguments against actual ones.
 *
 * - Maps: every expected key must exist and match (extra actual keys are ignored), recursively.
 * - Lists: same length, element by element.
 * - Numbers: int, float and numeric strings compare by value (2 equals "2").
 * - Strings: case-insensitive after trim.
 * - Anything else (bool, null): strict.
 */
final class ArgumentMatcher
{
    /**
     * Describes the first mismatch, e.g. `party_size=10, expected 2`, or null when everything matches.
     */
    public function mismatch(mixed $expected, mixed $actual, string $path = ''): ?string
    {
        if (is_array($expected) && ($expected === [] || !array_is_list($expected))) {
            if (!is_array($actual) || ($actual !== [] && array_is_list($actual))) {
                return $this->describe($path, $actual, $expected);
            }
            foreach ($expected as $key => $value) {
                $child = $path === '' ? (string) $key : $path . '.' . $key;
                if (!array_key_exists($key, $actual)) {
                    return sprintf('no %s, expected %s', $child, Text::value($value));
                }
                $mismatch = $this->mismatch($value, $actual[$key], $child);
                if ($mismatch !== null) {
                    return $mismatch;
                }
            }

            return null;
        }

        if (is_array($expected)) {
            if (!is_array($actual) || !array_is_list($actual) || count($actual) !== count($expected)) {
                return $this->describe($path, $actual, $expected);
            }
            foreach ($expected as $i => $value) {
                $mismatch = $this->mismatch($value, $actual[$i], sprintf('%s[%d]', $path, $i));
                if ($mismatch !== null) {
                    return $mismatch;
                }
            }

            return null;
        }

        return $this->scalarEquals($expected, $actual) ? null : $this->describe($path, $actual, $expected);
    }

    private function scalarEquals(mixed $expected, mixed $actual): bool
    {
        if ($this->isNumber($expected) && $this->isNumber($actual)) {
            return (float) $expected === (float) $actual;
        }
        if (is_string($expected) && is_string($actual)) {
            return mb_strtolower(trim($expected)) === mb_strtolower(trim($actual));
        }

        return $expected === $actual;
    }

    /**
     * @phpstan-assert-if-true int|float|numeric-string $value
     */
    private function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)));
    }

    private function describe(string $path, mixed $actual, mixed $expected): string
    {
        return sprintf('%s=%s, expected %s', $path === '' ? 'arguments' : $path, Text::value($actual), Text::value($expected));
    }
}
