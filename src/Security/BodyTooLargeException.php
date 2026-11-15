<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Security;

use RuntimeException;

final class BodyTooLargeException extends RuntimeException
{
    public function __construct(public readonly int $maxBytes)
    {
        parent::__construct(sprintf('response body is larger than %d bytes', $maxBytes));
    }
}
