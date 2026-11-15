<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Scenario;

final readonly class Scenario
{
    public const int DEFAULT_MAX_TURNS = 10;

    /**
     * @param list<Expectation> $expect
     */
    public function __construct(
        public string $name,
        public UserScript $user,
        public array $expect,
        public int $maxTurns = self::DEFAULT_MAX_TURNS,
        public ?string $persona = null,
        public ?string $goal = null,
        public ?string $file = null,
    ) {}
}
