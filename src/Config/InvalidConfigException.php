<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Config;

use Desemax\AgentHarness\Scenario\ValidationError;
use RuntimeException;

final class InvalidConfigException extends RuntimeException
{
    /**
     * @param list<ValidationError> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode("\n", array_map(static fn(ValidationError $e): string => (string) $e, $errors)));
    }
}
