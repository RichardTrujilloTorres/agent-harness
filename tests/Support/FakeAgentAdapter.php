<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Tests\Support;

use Closure;
use Desemax\AgentHarness\Agent\AgentAdapter;
use Desemax\AgentHarness\Agent\AgentException;
use Desemax\AgentHarness\Agent\AgentReply;

/**
 * In-memory agent: returns queued replies in order and records what it was sent.
 */
final class FakeAgentAdapter implements AgentAdapter
{
    /** @var list<array{session: string, message: string}> */
    public array $sent = [];

    /**
     * @param list<AgentReply|AgentException|Closure(string): AgentReply> $replies
     */
    public function __construct(private array $replies)
    {
    }

    public function send(string $sessionId, string $message): AgentReply
    {
        $this->sent[] = ['session' => $sessionId, 'message' => $message];
        $next = array_shift($this->replies) ?? throw new \LogicException('FakeAgentAdapter has no more replies queued.');
        if ($next instanceof AgentException) {
            throw $next;
        }

        return $next instanceof Closure ? $next($message) : $next;
    }
}
