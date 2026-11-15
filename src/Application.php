<?php

declare(strict_types=1);

namespace Desemax\AgentHarness;

use Desemax\AgentHarness\Command\InitCommand;
use Desemax\AgentHarness\Command\ListCommand;
use Desemax\AgentHarness\Command\RunCommand;
use Desemax\AgentHarness\Command\ValidateCommand;
use Symfony\Component\Console\Application as ConsoleApplication;

final class Application extends ConsoleApplication
{
    public const string NAME = 'agent-harness';
    public const string VERSION = '0.1.0';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        $this->addCommand(new InitCommand());
        $this->addCommand(new ValidateCommand());
        $this->addCommand(new ListCommand());
        $this->addCommand(new RunCommand());
    }
}
