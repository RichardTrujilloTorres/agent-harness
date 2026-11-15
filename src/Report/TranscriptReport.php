<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Report;

use Desemax\AgentHarness\Runner\SuiteResult;
use RuntimeException;

/**
 * One JSON file per scenario, named after the slugified scenario name.
 */
final class TranscriptReport
{
    /**
     * @return array<string, string> file name => JSON contents, in run order
     */
    public function render(SuiteResult $suite): array
    {
        $files = [];
        foreach (ResultData::suiteSlugs($suite) as $i => $slug) {
            $files[$slug . '.json'] = ResultData::json(ResultData::scenario($suite->results[$i])) . "\n";
        }

        return $files;
    }

    /**
     * @return list<string> paths written
     *
     * @throws RuntimeException when a file cannot be written
     */
    public function write(SuiteResult $suite, string $dir): array
    {
        ReportWriter::directory($dir);
        $paths = [];
        foreach ($this->render($suite) as $name => $json) {
            // $name is a slug ([a-z0-9-] plus ".json"): it cannot contain a separator or "..".
            $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
            ReportWriter::write($path, $json);
            $paths[] = $path;
        }

        return $paths;
    }
}
