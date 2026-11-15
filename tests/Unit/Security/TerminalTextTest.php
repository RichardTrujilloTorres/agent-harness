<?php

declare(strict_types=1);

use Desemax\AgentHarness\Security\TerminalText;

it('removes ANSI and other escape sequences and control characters', function (string $input, string $expected): void {
    expect(TerminalText::clean($input))->toBe($expected);
})->with([
    'colour' => ["\e[31mred\e[0m", 'red'],
    'clear screen and cursor' => ["a\e[2J\e[1;1Hb", 'ab'],
    'private mode' => ["\e[?25lhidden cursor", 'hidden cursor'],
    'window title (OSC, BEL)' => ["\e]0;pwned\x07text", 'text'],
    'hyperlink (OSC, ST)' => ["\e]8;;https://evil.test\e\\link\e]8;;\e\\", 'link'],
    'DCS' => ["\eP+q544e\e\\after", 'after'],
    'two-character escape' => ["\ecreset", 'reset'],
    'C1 CSI' => ["\u{9B}31mred", 'red'],
    'C0 controls' => ["a\x00b\x08c\x1Cd\x7Fe", 'abcde'],
    'carriage return overwrite' => ["safe\rEVIL", 'safeEVIL'],
    'tabs and newlines kept' => ["a\tb\nc\r\nd", "a\tb\nc\nd"],
    'unicode kept' => ['Caffè ✓ 日本', 'Caffè ✓ 日本'],
    'invalid UTF-8 replaced' => ["a\xFFb", 'a?b'],
]);

it('escapes console formatter tags so agent text cannot style or hide output', function (): void {
    expect(TerminalText::console("<error>x</error> \e[1mbold"))->toBe('\\<error\\>x\\</error\\> bold');
});
