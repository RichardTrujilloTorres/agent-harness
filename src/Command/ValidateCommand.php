<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Command;

use Desemax\AgentHarness\Scenario\ScenarioLoader;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'validate', description: 'Validate scenario files (a file or a directory, recursive)')]
final class ValidateCommand extends Command
{
    public const int EXIT_PATH_NOT_FOUND = 2;

    public function __construct(private readonly ScenarioLoader $loader = new ScenarioLoader())
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Scenario file or directory');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('path');
        if (!is_string($path)) {
            $output->writeln('<error>Path must be a string.</error>');

            return self::EXIT_PATH_NOT_FOUND;
        }

        try {
            $files = $this->loader->files($path);
        } catch (InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::EXIT_PATH_NOT_FOUND;
        }

        if ($files === []) {
            $output->writeln(sprintf('<error>No .yaml or .yml files found in %s</error>', $path));

            return Command::FAILURE;
        }

        $invalid = (new ValidationPrinter($this->loader))->check($files, $output);

        return $invalid === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
