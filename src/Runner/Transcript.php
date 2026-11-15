<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Runner;

use Desemax\AgentHarness\Agent\AgentReply;
use Desemax\AgentHarness\Agent\ToolCall;

/**
 * The ordered turns of one conversation. Immutable: with() returns a new transcript.
 */
final readonly class Transcript
{
    /**
     * @param list<Turn> $turns
     */
    public function __construct(
        public array $turns = [],
    ) {
    }

    public function with(string $userMessage, AgentReply $reply): self
    {
        return new self([...$this->turns, new Turn(count($this->turns) + 1, $userMessage, $reply)]);
    }

    public function count(): int
    {
        return count($this->turns);
    }

    public function last(): ?Turn
    {
        return $this->turns === [] ? null : $this->turns[count($this->turns) - 1];
    }

    /**
     * @return list<array{turn: int, call: ToolCall}>
     */
    public function toolCalls(): array
    {
        $calls = [];
        foreach ($this->turns as $turn) {
            foreach ($turn->reply->toolCalls as $call) {
                $calls[] = ['turn' => $turn->number, 'call' => $call];
            }
        }

        return $calls;
    }

    /**
     * Number of the first turn in which the agent handed off, or null.
     */
    public function handoffTurn(): ?int
    {
        foreach ($this->turns as $turn) {
            if ($turn->reply->handoff) {
                return $turn->number;
            }
        }

        return null;
    }

    public function handedOff(): bool
    {
        return $this->handoffTurn() !== null;
    }

    /**
     * Plain-text lines, e.g. "1. user:  Hi", "   agent: Hello (12 ms)", "   tool:  create_booking {...}", "   handoff".
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [];
        foreach ($this->turns as $turn) {
            $lines[] = sprintf('%d. user:  %s', $turn->number, $turn->userMessage);
            $lines[] = sprintf('   agent: %s (%d ms)', $turn->reply->reply, $turn->reply->latencyMs);
            foreach ($turn->reply->toolCalls as $call) {
                $lines[] = '   tool:  ' . $call->name . ' ' . json_encode((object) $call->arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if ($turn->reply->handoff) {
                $lines[] = '   handoff';
            }
        }

        return $lines;
    }

    public function totalLatencyMs(): int
    {
        return array_sum(array_map(static fn (Turn $t): int => $t->reply->latencyMs, $this->turns));
    }
}
