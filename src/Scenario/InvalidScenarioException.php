<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Scenario;

use RuntimeException;

final class InvalidScenarioException extends RuntimeException
{
    /**
     * @param list<ValidationError> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode("\n", array_map(static fn(ValidationError $e): string => (string) $e, $errors)));
    }
}
