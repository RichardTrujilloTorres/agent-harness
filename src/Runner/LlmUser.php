<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Runner;

use Desemax\AgentHarness\Llm\LlmClient;
use Desemax\AgentHarness\Llm\LlmException;
use Desemax\AgentHarness\Llm\LlmOptions;
use Desemax\AgentHarness\Scenario\Scenario;

/**
 * Plays the user of a scenario with an LLM, from its persona and goal.
 */
final readonly class LlmUser
{
    public const string DONE = '[[DONE]]';

    /** Some variety in how the user phrases things; --seed makes it repeatable where the server supports it. */
    public const float TEMPERATURE = 0.7;

    private const string START = '(The agent is waiting. Write your first message to it.)';
    private const string DEFAULT_PERSONA = 'An ordinary user of this service.';
    private const string DEFAULT_GOAL = 'Get help with a typical request.';

    public function __construct(
        private LlmClient $client,
        private ?int $seed = null,
    ) {}

    public function systemPrompt(Scenario $scenario): string
    {
        $persona = $this->text($scenario->persona) ?? self::DEFAULT_PERSONA;
        $goal = $this->text($scenario->goal) ?? self::DEFAULT_GOAL;
        $done = self::DONE;

        return <<<PROMPT
            You are role-playing a user who is chatting with an agent, so that the agent can be tested.

            Persona: {$persona}
            Goal: {$goal}

            Rules:
            - Write only your next message to the agent, exactly as the user would type it. No quotes, labels or narration.
            - Stay in character. Never mention that you are an AI or that this is a test.
            - Answer the agent's questions in line with your persona and goal.
            - When the goal is reached, or it is clearly impossible to reach, write {$done} alone and nothing else.
            PROMPT;
    }

    /**
     * The user's next message, or null when the user is done.
     *
     * @throws LlmException when the LLM fails or writes an empty message
     */
    public function next(Scenario $scenario, Transcript $transcript): ?string
    {
        if ($transcript->count() === 0 && $scenario->user->opening !== null) {
            return $scenario->user->opening;
        }

        // Roles are reversed: the LLM is the "assistant" writing user messages, the agent's replies are its input.
        $messages = [['role' => 'user', 'content' => self::START]];
        foreach ($transcript->turns as $turn) {
            $messages[] = ['role' => 'assistant', 'content' => $turn->userMessage];
            $messages[] = ['role' => 'user', 'content' => $turn->reply->reply];
        }

        $text = trim($this->client->complete(
            $this->systemPrompt($scenario),
            $messages,
            new LlmOptions(temperature: self::TEMPERATURE, seed: $this->seed),
        ));

        if (str_contains($text, self::DONE)) {
            return null;
        }
        if ($text === '') {
            throw new LlmException('LLM-played user wrote an empty message.');
        }

        return $text;
    }

    private function text(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
