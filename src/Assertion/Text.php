<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Assertion;

/**
 * Small text helpers shared by the evaluators.
 */
final class Text
{
    /**
     * Case-insensitive position of $needle in $haystack, or null.
     */
    public static function find(string $haystack, string $needle): ?int
    {
        $pos = mb_stripos($haystack, $needle);

        return $pos === false ? null : $pos;
    }

    /**
     * A one-line excerpt of $text around position $pos, with "..." where it was cut.
     */
    public static function excerpt(string $text, int $pos = 0, int $length = 0, int $context = 40): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $start = max(0, $pos - $context);
        $end = min(mb_strlen($text), $pos + $length + $context);

        return ($start > 0 ? '...' : '') . mb_substr($text, $start, $end - $start) . ($end < mb_strlen($text) ? '...' : '');
    }

    public static function quote(string $text): string
    {
        return '"' . $text . '"';
    }

    /**
     * Compact display of a value: strings quoted, scalars as JSON, maps and lists as JSON.
     */
    public static function value(mixed $value): string
    {
        if (is_string($value)) {
            return self::quote($value);
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
