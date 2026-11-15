<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

/**
 * The Markdown pages of the documentation site, and the parts of them that the docs tests check.
 */
final readonly class DocsCorpus
{
    /** Lines that start an agent-harness command: from a clone, a project, the PHAR or the bare name. */
    private const string COMMAND = '~^(?:bin/agent-harness|vendor/bin/agent-harness|php build/agent-harness\.phar|build/agent-harness\.phar|agent-harness)(?:\s|$)~';

    /** An HTML comment placed right before a YAML block that must be rejected by the validator. */
    public const string INVALID_MARKER = '<!-- docs-test: invalid -->';

    /**
     * @param array<string, string> $pages relative path => contents
     */
    public function __construct(public string $root, public array $pages) {}

    public static function load(string $root): self
    {
        $pages = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'md') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (preg_match('~(^|/)(node_modules|\.vitepress)/~', $relative) === 1) {
                continue;
            }
            $pages[$relative] = (string) file_get_contents($file->getPathname());
        }
        ksort($pages);

        return new self($root, $pages);
    }

    public function page(string $relative): string
    {
        return $this->pages[$relative] ?? throw new UnexpectedValueException("No docs page {$relative}.");
    }

    /**
     * Fenced code blocks of one language, with the line before the fence.
     *
     * @return list<array{page: string, code: string, before: string}>
     */
    public function blocks(string $language): array
    {
        $blocks = [];
        foreach ($this->pages as $page => $contents) {
            $lines = explode("\n", $contents);
            $count = count($lines);
            for ($i = 0; $i < $count; $i++) {
                if (preg_match('/^```' . preg_quote($language, '/') . '\s*$/', $lines[$i]) !== 1) {
                    continue;
                }
                $before = '';
                for ($j = $i - 1; $j >= 0; $j--) {
                    if (trim($lines[$j]) !== '') {
                        $before = trim($lines[$j]);
                        break;
                    }
                }
                $code = [];
                for ($i++; $i < $count && !str_starts_with($lines[$i], '```'); $i++) {
                    $code[] = $lines[$i];
                }
                $blocks[] = ['page' => $page, 'code' => implode("\n", $code), 'before' => $before];
            }
        }

        return $blocks;
    }

    /**
     * Every agent-harness command line in bash blocks, continuation lines joined.
     *
     * @return list<array{page: string, line: string, args: list<string>}>
     */
    public function commandLines(): array
    {
        $commands = [];
        foreach ($this->blocks('bash') as $block) {
            $joined = (string) preg_replace('/\\\\\n\s*/', ' ', $block['code']);
            foreach (explode("\n", $joined) as $line) {
                $line = trim($line);
                if (preg_match(self::COMMAND, $line) !== 1) {
                    continue;
                }
                $tokens = self::tokens($line);
                $binary = $tokens[0] === 'php' ? 2 : 1;
                $commands[] = ['page' => $block['page'], 'line' => $line, 'args' => array_slice($tokens, $binary)];
            }
        }

        return $commands;
    }

    /**
     * Splits a shell line into words: single and double quotes group, a comment or a pipe ends the line.
     *
     * @return list<string>
     */
    public static function tokens(string $line): array
    {
        preg_match_all('/"([^"]*)"|\'([^\']*)\'|(\S+)/', $line, $matches, PREG_SET_ORDER);
        $tokens = [];
        foreach ($matches as $match) {
            $word = $match[0];
            if (in_array($word, ['#', '|', '>', '&&'], true)) {
                break;
            }
            $tokens[] = $word;
        }

        return $tokens;
    }

    /**
     * The text under a "## heading" up to the next "## " heading.
     */
    public static function section(string $contents, string $heading): string
    {
        $pattern = '/^## ' . preg_quote($heading, '/') . '\s*$(.*?)(?=^## |\z)/ms';
        if (preg_match($pattern, $contents, $match) !== 1) {
            throw new UnexpectedValueException("No section \"## {$heading}\".");
        }

        return $match[1];
    }

    /**
     * The "## `name`" headings of a page.
     *
     * @return list<string>
     */
    public static function codeHeadings(string $contents): array
    {
        preg_match_all('/^## `([^`]+)`\s*$/m', $contents, $matches);

        return $matches[1];
    }

    /**
     * The rows of every Markdown table in a text, as lists of trimmed cells, header and separator rows excluded.
     *
     * @return list<list<string>>
     */
    public static function tableRows(string $text): array
    {
        $rows = [];
        $inTable = false;
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, '|')) {
                $inTable = false;
                continue;
            }
            if (!$inTable) {
                $inTable = true; // header row
                continue;
            }
            if (preg_match('/^\|(\s*:?-+:?\s*\|)+$/', $line) === 1) {
                continue;
            }
            $rows[] = array_map(trim(...), explode('|', trim($line, '|')));
        }

        return $rows;
    }

    /**
     * The options named in the first cell of each row, without dashes: "--url=URL" => "url", "-v" => "v".
     *
     * @param list<list<string>> $rows
     * @return list<string>
     */
    public static function optionNames(array $rows): array
    {
        $names = [];
        foreach ($rows as $row) {
            preg_match_all('/`(--?[A-Za-z][A-Za-z-]*)/', $row[0], $matches);
            foreach ($matches[1] as $option) {
                $names[] = ltrim($option, '-');
            }
        }
        sort($names);

        return array_values(array_unique($names));
    }

    /**
     * Exit code => meaning, from the tables of a text.
     *
     * @return array<int, string>
     */
    public static function exitCodes(string $text): array
    {
        $codes = [];
        foreach (self::tableRows($text) as $row) {
            if (preg_match('/^\d+$/', $row[0]) === 1) {
                $codes[(int) $row[0]] = $row[1] ?? '';
            }
        }
        ksort($codes);

        return $codes;
    }
}
