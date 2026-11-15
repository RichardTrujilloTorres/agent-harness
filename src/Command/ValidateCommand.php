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

#[AsCommand(name: 'validate', description: 'Validate scenario files (a file or a directory, recursive)')]
final class ValidateCommand extends Command
{
    public const string NAME = 'validate';
    public const int EXIT_PATH_NOT_FOUND = 2;

    public function __construct(private readonly ScenarioLoader $loader = new ScenarioLoader())
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'Scenario file or directory')
            ->setHelp(<<<'HELP'
                Exit codes:
                  0  every file is valid
                  1  at least one file is invalid, or the directory has no .yaml/.yml file
                  2  the path does not exist, or a bad option
                HELP)
            ->getDefinition()->addOption(OutputFormat::option());
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = OutputFormat::fromInput($input);
        if (!$format instanceof OutputFormat) {
            return $this->fail($output, OutputFormat::Text, self::EXIT_PATH_NOT_FOUND, '--format must be "text" or "json".');
        }
        $path = $input->getArgument('path');
        if (!is_string($path)) {
            return $this->fail($output, $format, self::EXIT_PATH_NOT_FOUND, 'Path must be a string.');
        }

        try {
            $files = $this->loader->files($path);
        } catch (InvalidArgumentException $e) {
            return $this->fail($output, $format, self::EXIT_PATH_NOT_FOUND, $e->getMessage());
        }

        if ($files === []) {
            return $this->fail($output, $format, Command::FAILURE, sprintf('No .yaml or .yml files found in %s. Scenario files need a .yaml or .yml extension.', $path));
        }

        $printer = new ValidationPrinter($this->loader);
        $results = $printer->results($files);
        $exit = ValidationPrinter::invalid($results) === 0 ? Command::SUCCESS : Command::FAILURE;
        if ($format === OutputFormat::Json) {
            return OutputFormat::writeJson($output, self::NAME, $exit, ValidationPrinter::json($results));
        }
        $printer->print($results, $output);

        return $exit;
    }

    private function fail(OutputInterface $output, OutputFormat $format, int $exit, string $message): int
    {
        if ($format === OutputFormat::Json) {
            return OutputFormat::writeJson($output, self::NAME, $exit, ['error' => $message]);
        }
        $output->writeln(sprintf('<error>%s</error>', TerminalText::console($message)));

        return $exit;
    }
}
