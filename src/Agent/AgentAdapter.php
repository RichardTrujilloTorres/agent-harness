<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Agent;

interface AgentAdapter
{
    /**
     * Sends one user message and returns the agent's reply.
     *
     * @throws AgentException when the agent cannot be reached or answers with something unusable
     */
    public function send(string $sessionId, string $message): AgentReply;
}
