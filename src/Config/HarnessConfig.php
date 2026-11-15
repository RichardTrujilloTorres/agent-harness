<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Config;

final readonly class HarnessConfig
{
    public const DEFAULT_TIMEOUT_MS = 10000;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public ?string $url = null,
        public int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        public array $headers = [],
        public string $sessionField = 'session_id',
        public string $messageField = 'message',
        public string $replyPath = 'reply',
        public string $toolCallsPath = 'tool_calls',
        public string $toolNamePath = 'name',
        public string $toolArgumentsPath = 'arguments',
        public string $handoffPath = 'handoff',
        public ?string $file = null,
    ) {
    }

    public function withUrl(string $url): self
    {
        return new self(
            $url,
            $this->timeoutMs,
            $this->headers,
            $this->sessionField,
            $this->messageField,
            $this->replyPath,
            $this->toolCallsPath,
            $this->toolNamePath,
            $this->toolArgumentsPath,
            $this->handoffPath,
            $this->file,
        );
    }
}
