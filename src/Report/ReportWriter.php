<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Report;

use RuntimeException;

/**
 * Writes report files, creating missing parent directories.
 *
 * A symlink at the target path is refused, never followed. The file is written to a temporary
 * file in the same directory and renamed into place, so a symlink planted between the check
 * and the write is replaced, not written through.
 */
final class ReportWriter
{
    /**
     * @throws RuntimeException when the file cannot be written
     */
    public static function write(string $file, string $contents): void
    {
        if (is_link($file)) {
            throw new RuntimeException(sprintf('Refusing to write %s: it is a symbolic link. Remove it or choose another path.', $file));
        }
        if (is_dir($file)) {
            throw new RuntimeException(sprintf('Cannot write %s: it is a directory. Pass a file path.', $file));
        }
        $dir = dirname($file);
        self::directory($dir);

        set_error_handler(static fn(): bool => true);
        try {
            $tmp = tempnam($dir, '.agent-harness-');
            $ok = $tmp !== false
                && file_put_contents($tmp, $contents) === strlen($contents)
                && chmod($tmp, 0o666 & ~umask())
                && rename($tmp, $file);
            if (!$ok && $tmp !== false && is_file($tmp)) {
                unlink($tmp);
            }
        } finally {
            restore_error_handler();
        }
        if (!$ok) {
            throw new RuntimeException(sprintf('Cannot write %s. Check that the directory exists and is writable.', $file));
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
        set_error_handler(static fn(): bool => true);
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
