<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Scenario;

final readonly class ValidationError implements \Stringable
{
    public function __construct(
        public string $file,
        public string $path,
        public string $reason,
    ) {}

    #[\Override]
    public function __toString(): string
    {
        return sprintf('%s: %s: %s', $this->file, $this->path, $this->reason);
    }
}
