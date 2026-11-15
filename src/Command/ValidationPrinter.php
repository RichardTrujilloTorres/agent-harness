<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Command;

use Desemax\AgentHarness\Scenario\ScenarioLoader;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Checks scenario files and prints the validate report (shared by validate and run).
 */
final class ValidationPrinter
{
    public function __construct(private readonly ScenarioLoader $loader)
    {
    }

    /**
     * Checks every file; prints the report when $always is true or any file is invalid.
     *
     * @param list<string> $files
     * @return int number of invalid files
     */
    public function check(array $files, OutputInterface $output, bool $always = true): int
    {
        $report = [];
        $invalid = 0;
        foreach ($files as $file) {
            $errors = $this->loader->check($file);
            if ($errors === []) {
                $report[] = sprintf('<info>OK</info>  %s', $file);
                continue;
            }

            $invalid++;
            $report[] = sprintf('<error>FAIL</error> %s', $file);
            foreach ($errors as $error) {
                $report[] = sprintf('  - %s: %s', $error->path, $error->reason);
            }
        }

        if ($always || $invalid > 0) {
            $output->writeln($report);
            $output->writeln('');
            $output->writeln(sprintf('%d file(s), %d valid, %d invalid', count($files), count($files) - $invalid, $invalid));
        }

        return $invalid;
    }
}
