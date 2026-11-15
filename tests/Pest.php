<?php

declare(strict_types=1);

use Desemax\AgentHarness\Tests\Support\ScenarioExpectation;
use Symfony\Component\HttpClient\Response\MockResponse;

function fixture(string $path): string
{
    return __DIR__ . '/Fixtures/' . $path;
}

function projectRoot(): string
{
    return dirname(__DIR__);
}

/**
 * expect() for a scenario result, with the plugin's toPassScenario, toHaveCalledTool, toHaveSaid and toHaveHandedOff.
 */
function expectScenario(mixed $value): ScenarioExpectation
{
    return ScenarioExpectation::of($value);
}

/**
 * Decodes JSON that must be an object or an array.
 *
 * @return array<mixed>
 */
function jsonArray(string $json): array
{
    $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new UnexpectedValueException('Expected a JSON object or array, got ' . get_debug_type($data) . '.');
    }

    return $data;
}

/**
 * The array stored under $key.
 *
 * @param array<mixed> $data
 *
 * @return array<mixed>
 */
function arrayAt(array $data, int|string $key): array
{
    $value = $data[$key] ?? null;
    if (!is_array($value)) {
        throw new UnexpectedValueException("Expected an array at key {$key}, got " . get_debug_type($value) . '.');
    }

    return $value;
}

/**
 * The JSON body a mocked response was requested with, decoded.
 *
 * @return array<mixed>
 */
function requestJson(MockResponse $response): array
{
    $body = $response->getRequestOptions()['body'] ?? null;
    if (!is_string($body)) {
        throw new UnexpectedValueException('The request has no string body, got ' . get_debug_type($body) . '.');
    }

    return jsonArray($body);
}

/**
 * A dataset array checked to have string keys only, e.g. command options.
 *
 * @param array<mixed> $values
 *
 * @return array<string, mixed>
 */
function stringKeyed(array $values): array
{
    $checked = [];
    foreach ($values as $key => $value) {
        if (!is_string($key)) {
            throw new UnexpectedValueException("Expected string keys only, got {$key}.");
        }
        $checked[$key] = $value;
    }

    return $checked;
}

/**
 * A dataset array checked to be a list of strings.
 *
 * @param array<mixed> $values
 *
 * @return list<string>
 */
function stringList(array $values): array
{
    $checked = [];
    foreach ($values as $value) {
        if (!is_string($value)) {
            throw new UnexpectedValueException('Expected a list of strings, got ' . get_debug_type($value) . '.');
        }
        $checked[] = $value;
    }
    if ($checked !== $values) {
        throw new UnexpectedValueException('Expected a list, got an array with keys.');
    }

    return $checked;
}
