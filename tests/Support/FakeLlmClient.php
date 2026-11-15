<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Tests\Support;

use Desemax\AgentHarness\Llm\LlmClient;
use Desemax\AgentHarness\Llm\LlmException;
use Desemax\AgentHarness\Llm\LlmOptions;
use LogicException;

/**
 * Scripted LLM: returns queued responses in order and records every request. Never touches the network.
 */
final class FakeLlmClient implements LlmClient
{
    /** @var list<array{system: string, messages: list<array{role: 'user'|'assistant', content: string}>, options: LlmOptions}> */
    public array $requests = [];

    /**
     * @param list<string|LlmException> $responses
     */
    public function __construct(private array $responses) {}

    public function complete(string $system, array $messages, LlmOptions $options): string
    {
        $this->requests[] = ['system' => $system, 'messages' => $messages, 'options' => $options];
        $next = array_shift($this->responses) ?? throw new LogicException('FakeLlmClient has no more responses queued.');
        if ($next instanceof LlmException) {
            throw $next;
        }

        return $next;
    }

    public function remaining(): int
    {
        return count($this->responses);
    }
}
