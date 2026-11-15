<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Report;

use Desemax\AgentHarness\Runner\ExpectationResult;
use Desemax\AgentHarness\Runner\ScenarioResult;
use Desemax\AgentHarness\Runner\SuiteResult;
use Desemax\AgentHarness\Runner\Turn;

/**
 * Plain-array view of a scenario result, shared by the reports.
 */
final class ResultData
{
    /**
     * @return array{
     *     name: string,
     *     file: ?string,
     *     status: string,
     *     message: ?string,
     *     attempts: int,
     *     duration_ms: int,
     *     turns: list<array{number: int, user: string, agent: string, tool_calls: list<array{name: string, arguments: array<string, mixed>}>, handoff: bool, latency_ms: int}>,
     *     expectations: list<array{type: string, expected: mixed, status: string, reason: string}>,
     *     warnings: list<string>
     * }
     */
    public static function scenario(ScenarioResult $result): array
    {
        return [
            'name' => $result->scenario->name,
            'file' => $result->scenario->file,
            'status' => $result->status->value,
            'message' => $result->message,
            'attempts' => $result->attempts,
            'duration_ms' => $result->durationMs,
            'turns' => array_map(self::turn(...), $result->transcript->turns),
            'expectations' => array_map(self::expectation(...), $result->expectations),
            'warnings' => $result->warnings,
        ];
    }

    /**
     * @return array{number: int, user: string, agent: string, tool_calls: list<array{name: string, arguments: array<string, mixed>}>, handoff: bool, latency_ms: int}
     */
    public static function turn(Turn $turn): array
    {
        $calls = [];
        foreach ($turn->reply->toolCalls as $call) {
            $calls[] = ['name' => $call->name, 'arguments' => $call->arguments];
        }

        return [
            'number' => $turn->number,
            'user' => $turn->userMessage,
            'agent' => $turn->reply->reply,
            'tool_calls' => $calls,
            'handoff' => $turn->reply->handoff,
            'latency_ms' => $turn->reply->latencyMs,
        ];
    }

    /**
     * @return array{type: string, expected: mixed, status: string, reason: string}
     */
    public static function expectation(ExpectationResult $result): array
    {
        return [
            'type' => $result->expectation->type->value,
            'expected' => $result->expectation->payload,
            'status' => $result->status->value,
            'reason' => $result->reason,
        ];
    }

    /**
     * Lowercase ASCII letters, digits and dashes, e.g. "Happy path booking" -> "happy-path-booking".
     */
    public static function slug(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        // Some iconv builds transliterate "è" as "`e": drop the accent marks before replacing the rest.
        $ascii = str_replace(['`', "'", '^', '~', '"'], '', $ascii === false ? $name : $ascii);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');

        return $slug === '' ? 'scenario' : $slug;
    }

    /**
     * Unique slugs for a run, in order: a repeated name gets "-2", "-3", ...
     *
     * @param list<string> $names
     * @return list<string>
     */
    public static function uniqueSlugs(array $names): array
    {
        $seen = [];
        $slugs = [];
        foreach ($names as $name) {
            $base = self::slug($name);
            $slug = $base;
            for ($i = 2; isset($seen[$slug]); $i++) {
                $slug = sprintf('%s-%d', $base, $i);
            }
            $seen[$slug] = true;
            $slugs[] = $slug;
        }

        return $slugs;
    }

    /**
     * @return list<string>
     */
    public static function suiteSlugs(SuiteResult $suite): array
    {
        return self::uniqueSlugs(array_map(static fn(ScenarioResult $r): string => $r->scenario->name, $suite->results));
    }

    public static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
