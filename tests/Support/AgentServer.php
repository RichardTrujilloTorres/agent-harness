<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Tests\Support;

use RuntimeException;

/**
 * Runs a PHP document root with the built-in server on a free local port, for feature tests.
 */
final class AgentServer
{
    /** @var resource */
    private $process;

    private function __construct(public readonly int $port, mixed $process)
    {
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start the PHP built-in server.');
        }
        $this->process = $process;
    }

    public static function start(string $docroot): self
    {
        $port = self::freePort();
        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        $server = new self($port, $process);

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            set_error_handler(static fn(): bool => true);
            try {
                $conn = fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            } finally {
                restore_error_handler();
            }
            if ($conn !== false) {
                fclose($conn);

                return $server;
            }
            usleep(50_000);
        }

        $server->stop();

        throw new RuntimeException("Server for {$docroot} did not start within 5 seconds.");
    }

    /**
     * A port nothing listens on right now.
     */
    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("Cannot find a free port: {$errstr}");
        }
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }

    public function url(string $path = ''): string
    {
        return "http://127.0.0.1:{$this->port}{$path}";
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }
}
