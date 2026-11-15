<?php

declare(strict_types=1);

namespace Desemax\AgentHarness;

use Symfony\Component\Console\Application as ConsoleApplication;

final class Application extends ConsoleApplication
{
    public const NAME = 'agent-harness';
    public const VERSION = '0.1.0-dev';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);
    }
}
