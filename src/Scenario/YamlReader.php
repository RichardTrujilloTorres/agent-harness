<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Scenario;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads scenario and config YAML as plain data: no PHP objects, constants or custom tags,
 * with caps on file size, nesting depth and node count.
 */
final readonly class YamlReader
{
    public const int MAX_BYTES = 1_048_576;
    public const int MAX_DEPTH = 32;
    public const int MAX_NODES = 100_000;

    /** A "!php/..." or "!!php/..." tag in a node position (Symfony YAML would otherwise turn it into null). */
    private const string PHP_TAG = '/(?:^|[\s\[\{,:\-?])!!?php\//im';

    public function __construct(
        private int $maxBytes = self::MAX_BYTES,
        private int $maxDepth = self::MAX_DEPTH,
        private int $maxNodes = self::MAX_NODES,
    ) {}

    /**
     * @throws YamlException when the file cannot be read, is too large, too deep, uses PHP tags or is not valid YAML
     */
    public function read(string $file): mixed
    {
        $size = is_file($file) ? filesize($file) : false;
        if ($size === false) {
            throw new YamlException(sprintf('cannot read the file %s', $file));
        }
        if ($size > $this->maxBytes) {
            throw new YamlException(sprintf('file is %d bytes; the limit is %d bytes. Split it into smaller files.', $size, $this->maxBytes));
        }

        return $this->parse((string) file_get_contents($file));
    }

    /**
     * @throws YamlException
     */
    public function parse(string $yaml): mixed
    {
        if (strlen($yaml) > $this->maxBytes) {
            throw new YamlException(sprintf('YAML is %d bytes; the limit is %d bytes. Split it into smaller files.', strlen($yaml), $this->maxBytes));
        }
        if (preg_match(self::PHP_TAG, $yaml, $m, PREG_OFFSET_CAPTURE) === 1) {
            $line = substr_count($yaml, "\n", 0, (int) strpos($yaml, '!', $m[0][1])) + 1;

            throw new YamlException(sprintf('PHP tags such as !php/object and !php/const are not allowed (line %d). Write plain values instead.', $line));
        }

        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new YamlException('invalid YAML: ' . $e->getMessage(), 0, $e);
        }

        $nodes = 0;
        $this->measure($data, 1, $nodes);

        return $data;
    }

    /**
     * @throws YamlException
     */
    private function measure(mixed $value, int $depth, int &$nodes): void
    {
        $nodes++;
        if ($nodes > $this->maxNodes) {
            throw new YamlException(sprintf('YAML has more than %d values; the limit protects against alias expansion. Simplify the file.', $this->maxNodes));
        }
        if (!is_array($value)) {
            return;
        }
        if ($depth > $this->maxDepth) {
            throw new YamlException(sprintf('YAML is nested more than %d levels deep. Flatten the structure.', $this->maxDepth));
        }
        foreach ($value as $item) {
            $this->measure($item, $depth + 1, $nodes);
        }
    }
}
