<?php

declare(strict_types=1);

namespace Desemax\AgentHarness;

use Desemax\AgentHarness\Command\InitCommand;
use Desemax\AgentHarness\Command\ListCommand;
use Desemax\AgentHarness\Command\RunCommand;
use Desemax\AgentHarness\Command\ValidateCommand;
use Override;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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

    /**
     * The "list" command of this tool lists scenarios and needs a path, so it cannot be the
     * default command. Without a command name, print the command overview instead.
     */
    #[Override]
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        if ($this->getCommandName($input) === null && !$input->hasParameterOption(['--help', '-h', '--version', '-V'], true)) {
            (new DescriptorHelper())->describe($output, $this);

            return 0;
        }

        return parent::doRun($input, $output);
    }
}
