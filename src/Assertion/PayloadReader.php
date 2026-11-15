<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Assertion;

use Desemax\AgentHarness\Scenario\Expectation;
use LogicException;

/**
 * Typed access to validated expectation payloads.
 */
trait PayloadReader
{
    private function stringPayload(Expectation $expectation): string
    {
        if (!is_string($expectation->payload)) {
            throw new LogicException(sprintf('%s expects a string payload.', $expectation->type->value));
        }

        return $expectation->payload;
    }

    private function intPayload(Expectation $expectation): int
    {
        if (!is_int($expectation->payload)) {
            throw new LogicException(sprintf('%s expects an integer payload.', $expectation->type->value));
        }

        return $expectation->payload;
    }

    private function boolPayload(Expectation $expectation): bool
    {
        if (!is_bool($expectation->payload)) {
            throw new LogicException(sprintf('%s expects a boolean payload.', $expectation->type->value));
        }

        return $expectation->payload;
    }
}
