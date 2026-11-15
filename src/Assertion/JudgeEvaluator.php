<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Assertion;

use Desemax\AgentHarness\Llm\LlmClient;
use Desemax\AgentHarness\Llm\LlmConfig;
use Desemax\AgentHarness\Llm\LlmException;
use Desemax\AgentHarness\Llm\LlmOptions;
use Desemax\AgentHarness\Llm\OpenAiCompatibleClient;
use Desemax\AgentHarness\Runner\ExpectationResult;
use Desemax\AgentHarness\Runner\Transcript;
use Desemax\AgentHarness\Scenario\Expectation;

/**
 * judge: an LLM reads the transcript and decides whether the rubric is met.
 *
 * Skipped without an LLM. Invalid JSON from the judge is retried once, then reported as an error.
 */
final class JudgeEvaluator implements Evaluator
{
    use PayloadReader;

    public const SKIP_REASON = LlmConfig::NOT_CONFIGURED;

    public const SYSTEM_PROMPT = <<<'PROMPT'
        You are a strict reviewer of a conversation between a user and an automated agent.
        Decide whether the agent's behaviour in the transcript satisfies the rubric.
        Judge only what is in the transcript; do not assume anything that is not shown.
        Answer with a JSON object and nothing else: {"pass": true or false, "reason": "one or two sentences"}
        PROMPT;

    public function __construct(
        private readonly ?LlmClient $client = null,
        private readonly ?int $seed = null,
    ) {}

    public function evaluate(Expectation $expectation, Transcript $transcript): ExpectationResult
    {
        $rubric = $this->stringPayload($expectation);
        if ($this->client === null) {
            return ExpectationResult::skipped($expectation, self::SKIP_REASON);
        }

        $label = 'judge ' . Text::quote(Text::excerpt($rubric, 0, 0, 80));
        $messages = [['role' => 'user', 'content' => self::prompt($rubric, $transcript)]];
        $options = new LlmOptions(temperature: 0.0, seed: $this->seed, jsonMode: true);

        $raw = '';
        try {
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $raw = $this->client->complete(self::SYSTEM_PROMPT, $messages, $options);
                $verdict = self::verdict($raw);
                if ($verdict !== null) {
                    return $verdict['pass']
                        ? ExpectationResult::passed($expectation, sprintf('%s: pass: %s', $label, $verdict['reason']))
                        : ExpectationResult::failed($expectation, sprintf('%s: fail: %s', $label, $verdict['reason']));
                }
            }
        } catch (LlmException $e) {
            return ExpectationResult::errored($expectation, sprintf('%s: %s', $label, $e->getMessage()));
        }

        return ExpectationResult::errored($expectation, sprintf(
            '%s: judge did not answer {"pass": bool, "reason": string} after a retry; last answer: %s',
            $label,
            OpenAiCompatibleClient::excerpt($raw),
        ));
    }

    public static function prompt(string $rubric, Transcript $transcript): string
    {
        $lines = ['Rubric:', $rubric, '', 'Transcript:'];
        if ($transcript->count() === 0) {
            $lines[] = '(no turns)';
        }
        foreach ($transcript->turns as $turn) {
            $lines[] = sprintf('Turn %d', $turn->number);
            $lines[] = 'User: ' . $turn->userMessage;
            $lines[] = 'Agent: ' . $turn->reply->reply;
            foreach ($turn->reply->toolCalls as $call) {
                $lines[] = sprintf('Agent called tool %s with %s', $call->name, Text::value((object) $call->arguments));
            }
            if ($turn->reply->handoff) {
                $lines[] = 'Agent handed the conversation off to a human.';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return ?array{pass: bool, reason: string}
     */
    private static function verdict(string $raw): ?array
    {
        $json = trim($raw);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $json, $m) === 1) {
            $json = $m[1];
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !is_bool($data['pass'] ?? null) || !is_string($data['reason'] ?? null)) {
            return null;
        }

        return ['pass' => $data['pass'], 'reason' => trim($data['reason'])];
    }
}
