<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * --format=text|json, shared by the commands that support it.
 */
enum OutputFormat: string
{
    case Text = 'text';
    case Json = 'json';

    /** Version of the JSON documents; bumped only on a breaking change of their shape. */
    public const int SCHEMA_VERSION = 1;

    public static function option(): InputOption
    {
        return new InputOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json (one JSON document on stdout, no colours)', self::Text->value);
    }

    public static function fromInput(InputInterface $input): ?self
    {
        $value = $input->getOption('format');

        return is_string($value) ? self::tryFrom(strtolower($value)) : null;
    }

    /**
     * Writes one JSON document: {"schema_version": 1, "command": ..., "ok": ..., "exit_code": ..., ...$data}.
     *
     * @param array<string, mixed> $data
     */
    public static function writeJson(OutputInterface $output, string $command, int $exitCode, array $data): int
    {
        $document = ['schema_version' => self::SCHEMA_VERSION, 'command' => $command, 'ok' => $exitCode === 0, 'exit_code' => $exitCode] + $data;
        $output->writeln(
            (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE),
            OutputInterface::OUTPUT_RAW,
        );

        return $exitCode;
    }
}
