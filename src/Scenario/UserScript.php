<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Scenario;

final readonly class UserScript
{
    /**
     * @param list<string> $turns
     */
    public function __construct(
        public UserMode $mode,
        public array $turns = [],
    ) {
    }
}
