<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Command;

use Desemax\AgentHarness\Scenario\ScenarioLoader;
use Desemax\AgentHarness\Scenario\ValidationError;
use Desemax\AgentHarness\Security\TerminalText;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Checks scenario files and prints the validate report (shared by validate and run).
 */
final readonly class ValidationPrinter
{
    public function __construct(private ScenarioLoader $loader) {}

    /**
     * Every file with its errors (an empty list for a valid file), in order.
     *
     * @param list<string> $files
     * @return list<array{file: string, errors: list<ValidationError>}>
     */
    public function results(array $files): array
    {
        return array_map(fn(string $file): array => ['file' => $file, 'errors' => $this->loader->check($file)], $files);
    }

    /**
     * Checks every file; prints the report when $always is true or any file is invalid.
     *
     * @param list<string> $files
     * @return int number of invalid files
     */
    public function check(array $files, OutputInterface $output, bool $always = true): int
    {
        $results = $this->results($files);
        $invalid = self::invalid($results);
        if ($always || $invalid > 0) {
            $this->print($results, $output);
        }

        return $invalid;
    }

    /**
     * @param list<array{file: string, errors: list<ValidationError>}> $results
     */
    public function print(array $results, OutputInterface $output): void
    {
        $report = [];
        foreach ($results as $result) {
            if ($result['errors'] === []) {
                $report[] = sprintf('<info>OK</info>  %s', TerminalText::console($result['file']));
                continue;
            }
            $report[] = sprintf('<error>FAIL</error> %s', TerminalText::console($result['file']));
            foreach ($result['errors'] as $error) {
                $report[] = sprintf('  - %s: %s', TerminalText::console($error->path), TerminalText::console($error->reason));
            }
        }

        $output->writeln($report);
        $output->writeln('');
        $output->writeln(sprintf('%d file(s), %d valid, %d invalid', count($results), count($results) - self::invalid($results), self::invalid($results)));
    }

    /**
     * @param list<array{file: string, errors: list<ValidationError>}> $results
     */
    public static function invalid(array $results): int
    {
        return count(array_filter($results, static fn(array $r): bool => $r['errors'] !== []));
    }

    /**
     * The "files" and "summary" members of the JSON output.
     *
     * @param list<array{file: string, errors: list<ValidationError>}> $results
     * @return array{files: list<array{file: string, valid: bool, errors: list<array{path: string, message: string}>}>, summary: array{files: int, valid: int, invalid: int}}
     */
    public static function json(array $results): array
    {
        $files = array_map(static fn(array $r): array => [
            'file' => $r['file'],
            'valid' => $r['errors'] === [],
            'errors' => array_map(static fn(ValidationError $e): array => ['path' => $e->path, 'message' => $e->reason], $r['errors']),
        ], $results);
        $invalid = self::invalid($results);

        return ['files' => $files, 'summary' => ['files' => count($results), 'valid' => count($results) - $invalid, 'invalid' => $invalid]];
    }
}
