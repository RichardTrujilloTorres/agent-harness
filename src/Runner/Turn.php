<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Runner;

use Desemax\AgentHarness\Agent\AgentReply;

final readonly class Turn
{
    /**
     * @param int $number 1-based position in the conversation
     */
    public function __construct(
        public int $number,
        public string $userMessage,
        public AgentReply $reply,
    ) {
    }
}
