<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Report;

use RuntimeException;

/**
 * Writes report files, creating missing parent directories.
 */
final class ReportWriter
{
    /**
     * @throws RuntimeException when the file cannot be written
     */
    public static function write(string $file, string $contents): void
    {
        self::directory(dirname($file));
        set_error_handler(static fn (): bool => true);
        try {
            $written = file_put_contents($file, $contents);
        } finally {
            restore_error_handler();
        }
        if ($written === false) {
            throw new RuntimeException(sprintf('Cannot write %s', $file));
        }
    }

    /**
     * @throws RuntimeException when the directory cannot be created
     */
    public static function directory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        set_error_handler(static fn (): bool => true);
        try {
            $created = mkdir($dir, 0777, true);
        } finally {
            restore_error_handler();
        }
        if (!$created && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create directory %s', $dir));
        }
    }
}
