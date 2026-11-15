<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Agent;

/**
 * Reads and writes values in nested arrays with dot-paths such as "data.output.text" or "choices.0.text".
 */
final class DotPath
{
    /**
     * @param array<mixed> $data
     * @return array{0: bool, 1: mixed} [found, value]
     */
    public static function get(array $data, string $path): array
    {
        $current = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return [false, null];
            }
            $current = $current[$segment];
        }

        return [true, $current];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function set(array $data, string $path, mixed $value): array
    {
        $segments = explode('.', $path);
        $key = array_shift($segments);
        if ($segments === []) {
            $data[$key] = $value;

            return $data;
        }

        $child = $data[$key] ?? [];
        $data[$key] = self::set(is_array($child) ? self::stringKeys($child) : [], implode('.', $segments), $value);

        return $data;
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function stringKeys(array $value): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }
}
