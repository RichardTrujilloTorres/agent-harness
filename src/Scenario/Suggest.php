<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Scenario;

/**
 * "Did you mean" for unknown keys and values, by Levenshtein distance.
 */
final class Suggest
{
    /**
     * The closest allowed value, or null when nothing is close enough
     * (at most 2 edits, or a third of the length for longer words).
     *
     * @param list<string> $allowed
     */
    public static function closest(string $given, array $allowed): ?string
    {
        $given = strtolower(trim($given));
        $best = null;
        $bestDistance = max(2, intdiv(strlen($given), 3)) + 1;
        foreach ($allowed as $candidate) {
            $distance = levenshtein($given, strtolower($candidate));
            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * E.g. 'unknown field "nmae"; did you mean "name"? Allowed: name, persona, goal'.
     *
     * @param list<string> $allowed
     */
    public static function unknown(string $what, string $given, array $allowed): string
    {
        $closest = self::closest($given, $allowed);

        return sprintf(
            'unknown %s "%s"; %sallowed: %s',
            $what,
            $given,
            $closest === null ? '' : sprintf('did you mean "%s"? ', $closest),
            implode(', ', $allowed),
        );
    }
}
