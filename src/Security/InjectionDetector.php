<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Security;

use Desemax\AgentHarness\Runner\Transcript;

/**
 * Deterministic pre-check for agent replies that look like prompt injection aimed at the LLM judge
 * or the LLM-played user. It only raises a warning: a match is a reason to look, not a verdict.
 */
final class InjectionDetector
{
    public const string WARNING = 'suspicious_reply';

    /** @var array<string, string> label => case-insensitive pattern */
    private const array PATTERNS = [
        'ignore previous instructions' => '/\b(?:ignore|disregard|forget|override)\b[^.\n]{0,40}\b(?:previous|prior|above|earlier|all|your|system)\b[^.\n]{0,20}\b(?:instructions?|prompts?|rules?|directions?|guidelines|persona|role)\b/i',
        'new instructions' => '/\b(?:new|updated|real|actual)\s+(?:system\s+)?instructions?\b/i',
        'role reassignment' => '/\b(?:you are now|from now on,? you|act as|pretend to be|you must now)\b/i',
        'system prompt reference' => '/\bsystem\s*(?:prompt|message)\b|<\/?\s*system\s*>|\[\s*system\s*\]/i',
        'verdict injection' => '/["\']?\bpass\b["\']?\s*[:=]\s*(?:true|false)\b/i',
        'conversation end token' => '/\[\[\s*DONE\s*\]\]/i',
        'transcript delimiter' => '/<<<\s*(?:END[- ]?)?(?:TRANSCRIPT|UNTRUSTED)/i',
    ];

    /**
     * Labels of the patterns found in one text, in a stable order.
     *
     * @return list<string>
     */
    public function scan(string $text): array
    {
        $found = [];
        foreach (self::PATTERNS as $label => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $found[] = $label;
            }
        }

        return $found;
    }

    /**
     * One warning per suspicious agent reply, e.g. 'suspicious_reply: turn 2 looks like prompt injection (verdict injection)'.
     *
     * @return list<string>
     */
    public function warnings(Transcript $transcript): array
    {
        $warnings = [];
        foreach ($transcript->turns as $turn) {
            $labels = $this->scan($turn->reply->reply);
            foreach ($turn->reply->toolCalls as $call) {
                $labels = [...$labels, ...$this->scan((string) json_encode($call->arguments))];
            }
            if ($labels !== []) {
                $warnings[] = sprintf('%s: turn %d looks like prompt injection (%s)', self::WARNING, $turn->number, implode(', ', array_values(array_unique($labels))));
            }
        }

        return $warnings;
    }
}
