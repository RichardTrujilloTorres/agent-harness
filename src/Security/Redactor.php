<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Security;

use Desemax\AgentHarness\Agent\AgentReply;
use Desemax\AgentHarness\Agent\ToolCall;
use Desemax\AgentHarness\Runner\ExpectationResult;
use Desemax\AgentHarness\Runner\ScenarioResult;
use Desemax\AgentHarness\Runner\SuiteResult;
use Desemax\AgentHarness\Runner\Transcript;
use Desemax\AgentHarness\Runner\Turn;
use SensitiveParameter;

/**
 * Replaces known secret values (the LLM API key, configured header values) with a placeholder
 * in every piece of text that can reach the terminal, a report or a transcript file.
 */
final readonly class Redactor
{
    public const string PLACEHOLDER = '[REDACTED]';

    /** Values shorter than this are not treated as secrets: redacting them would mangle ordinary text. */
    public const int MIN_LENGTH = 4;

    /** @var list<string> longest first, so a secret that contains another is replaced whole */
    private array $secrets;

    /**
     * @param iterable<string> $secrets
     */
    public function __construct(#[SensitiveParameter] iterable $secrets = [])
    {
        $unique = [];
        foreach ($secrets as $secret) {
            $secret = trim($secret);
            if (mb_strlen($secret) >= self::MIN_LENGTH) {
                $unique[$secret] = true;
            }
        }
        $list = array_map(strval(...), array_keys($unique));
        usort($list, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $this->secrets = $list;
    }

    public function text(string $text): string
    {
        return $this->secrets === [] ? $text : str_replace($this->secrets, self::PLACEHOLDER, $text);
    }

    public function value(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->text($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[is_string($key) ? $this->text($key) : $key] = $this->value($item);
        }

        return $out;
    }

    public function suite(SuiteResult $suite): SuiteResult
    {
        return new SuiteResult(array_map($this->result(...), $suite->results), $suite->durationMs);
    }

    public function result(ScenarioResult $result): ScenarioResult
    {
        return new ScenarioResult(
            $result->scenario,
            $result->status,
            $this->transcript($result->transcript),
            array_map(fn(ExpectationResult $r): ExpectationResult => new ExpectationResult($r->expectation, $r->status, $this->text($r->reason)), $result->expectations),
            $result->durationMs,
            $result->message === null ? null : $this->text($result->message),
            $result->attempts,
            array_map($this->text(...), $result->warnings),
        );
    }

    private function transcript(Transcript $transcript): Transcript
    {
        return new Transcript(array_map(fn(Turn $turn): Turn => new Turn(
            $turn->number,
            $this->text($turn->userMessage),
            new AgentReply(
                $this->text($turn->reply->reply),
                array_map(fn(ToolCall $call): ToolCall => new ToolCall($this->text($call->name), $this->stringKeyed($this->value($call->arguments))), $turn->reply->toolCalls),
                $turn->reply->handoff,
                $turn->reply->latencyMs,
                $this->listOrMap($this->value($turn->reply->raw)),
            ),
        ), $transcript->turns));
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyed(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /**
     * @return array<mixed>
     */
    private function listOrMap(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
