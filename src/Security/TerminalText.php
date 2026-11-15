<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Security;

use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Makes untrusted text (agent replies, LLM answers, file contents) safe to print on a terminal.
 */
final class TerminalText
{
    /** CSI, OSC, DCS/PM/APC/SOS strings and two-character escapes. */
    private const string ANSI = '/\x1B(?:\[[0-?]*[ -\/]*[@-~]|\][^\x07\x1B]*(?:\x07|\x1B\\\\)?|[PX^_][^\x1B]*(?:\x1B\\\\)?|[0-~])|\x9B[0-?]*[ -\/]*[@-~]/u';

    /** C0 controls except tab and newline, DEL and C1 controls. */
    private const string CONTROL = '/[\x00-\x08\x0B-\x1F\x7F\x{80}-\x{9F}]/u';

    /**
     * Removes escape sequences and control characters; keeps tabs and newlines. Invalid UTF-8 is replaced.
     */
    public static function clean(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = (string) preg_replace(self::ANSI, '', $text);

        return (string) preg_replace(self::CONTROL, '', str_replace("\r\n", "\n", $text));
    }

    /**
     * clean() and escaped for the Symfony console formatter, so "<error>" in agent text is printed, not interpreted.
     */
    public static function console(string $text): string
    {
        return OutputFormatter::escape(self::clean($text));
    }
}
