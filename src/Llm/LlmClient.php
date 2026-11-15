<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Llm;

/**
 * The only way the harness talks to a language model.
 */
interface LlmClient
{
    /**
     * Returns the text of the model's next message.
     *
     * @param list<array{role: 'user'|'assistant', content: string}> $messages conversation after the system prompt
     *
     * @throws LlmException when the model cannot be reached or answers with something unusable
     */
    public function complete(string $system, array $messages, LlmOptions $options): string;
}
