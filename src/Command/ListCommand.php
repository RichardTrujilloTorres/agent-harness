<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Command;

use Desemax\AgentHarness\Scenario\ScenarioLoader;
use Desemax\AgentHarness\Security\TerminalText;
use InvalidArgumentException;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'list', description: 'List scenarios with their user mode and expectation count')]
final class ListCommand extends Command
{
    public const string NAME = 'list';

    public function __construct(private readonly ScenarioLoader $loader = new ScenarioLoader())
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Scenario file or directory');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('path');
        if (!is_string($path)) {
            $output->writeln('<error>Path must be a string.</error>');

            return Command::INVALID;
        }

        try {
            $files = $this->loader->files($path);
        } catch (InvalidArgumentException $e) {
            $output->writeln('<error>' . TerminalText::console($e->getMessage()) . '</error>');

            return Command::INVALID;
        }
        if ($files === []) {
            $output->writeln(sprintf('<error>No .yaml or .yml files found in %s. Scenario files need a .yaml or .yml extension.</error>', TerminalText::console($path)));

            return Command::FAILURE;
        }

        $printer = new ValidationPrinter($this->loader);
        $validation = $printer->results($files);
        if (ValidationPrinter::invalid($validation) > 0) {
            $printer->print($validation, $output);

            return Command::FAILURE;
        }

        $output->writeln(sprintf('%-60s %-8s %-12s %s', 'NAME', 'MODE', 'EXPECTATIONS', 'FILE'));
        foreach ($this->loader->load($path) as $scenario) {
            $output->writeln(sprintf(
                '%-60s %-8s %-12d %s',
                TerminalText::console($scenario->name),
                $scenario->user->mode->value,
                count($scenario->expect),
                TerminalText::console($scenario->file ?? '(inline)'),
            ));
        }
        $output->writeln(sprintf('%d scenario(s)', count($files)));

        return Command::SUCCESS;
    }
}
