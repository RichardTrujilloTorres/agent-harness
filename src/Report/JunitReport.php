<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Report;

use DOMDocument;
use DOMElement;
use Desemax\AgentHarness\Runner\ExpectationResult;
use Desemax\AgentHarness\Runner\ScenarioResult;
use Desemax\AgentHarness\Runner\ScenarioStatus;
use Desemax\AgentHarness\Runner\SuiteResult;

/**
 * JUnit XML: one testsuite, one testcase per scenario.
 */
final class JunitReport
{
    public const SUITE_NAME = 'agent-harness';

    public function render(SuiteResult $suite): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $counts = [
            'tests' => (string) count($suite->results),
            'failures' => (string) $suite->count(ScenarioStatus::Failed),
            'errors' => (string) $suite->count(ScenarioStatus::Error),
            'skipped' => (string) $suite->count(ScenarioStatus::Skipped),
            'time' => self::seconds($suite->durationMs),
        ];

        $root = $doc->createElement('testsuites');
        $root->setAttribute('name', self::SUITE_NAME);
        foreach ($counts as $name => $value) {
            $root->setAttribute($name, $value);
        }
        $doc->appendChild($root);

        $testsuite = $doc->createElement('testsuite');
        $testsuite->setAttribute('name', self::SUITE_NAME);
        foreach ($counts as $name => $value) {
            $testsuite->setAttribute($name, $value);
        }
        $root->appendChild($testsuite);

        foreach ($suite->results as $result) {
            $testsuite->appendChild($this->testcase($doc, $result));
        }

        return (string) $doc->saveXML();
    }

    private function testcase(DOMDocument $doc, ScenarioResult $result): DOMElement
    {
        $case = $doc->createElement('testcase');
        $case->setAttribute('name', $result->scenario->name);
        $case->setAttribute('classname', $result->scenario->file ?? self::SUITE_NAME);
        if ($result->scenario->file !== null) {
            $case->setAttribute('file', $result->scenario->file);
        }
        $case->setAttribute('time', self::seconds($result->durationMs));

        switch ($result->status) {
            case ScenarioStatus::Failed:
                $failures = $result->failures();
                $case->appendChild($this->problem($doc, 'failure', $failures[0]->reason ?? 'failed', self::reasons($failures)));
                break;
            case ScenarioStatus::Error:
                $errors = $result->errors();
                $message = $result->message ?? ($errors[0]->reason ?? 'error');
                $case->appendChild($this->problem($doc, 'error', $message, $errors === [] ? $message : self::reasons($errors)));
                break;
            case ScenarioStatus::Skipped:
                $skipped = $doc->createElement('skipped');
                $skipped->setAttribute('message', $result->message ?? 'nothing could be checked');
                $case->appendChild($skipped);
                break;
            case ScenarioStatus::Passed:
            case ScenarioStatus::Flaky:
                break;
        }

        $out = $doc->createElement('system-out');
        $out->appendChild($doc->createTextNode(self::transcript($result)));
        $case->appendChild($out);

        return $case;
    }

    /**
     * @param list<ExpectationResult> $results
     */
    private static function reasons(array $results): string
    {
        return implode("\n", array_map(static fn (ExpectationResult $r): string => $r->reason, $results));
    }

    private function problem(DOMDocument $doc, string $tag, string $message, string $details): DOMElement
    {
        $el = $doc->createElement($tag);
        $el->setAttribute('message', $message);
        $el->setAttribute('type', $tag === 'failure' ? 'ExpectationFailed' : 'ScenarioError');
        $el->appendChild($doc->createTextNode($details));

        return $el;
    }

    private static function transcript(ScenarioResult $result): string
    {
        $lines = [];
        if ($result->message !== null) {
            $lines[] = $result->message;
        }
        foreach ($result->transcript->turns as $turn) {
            $data = ResultData::turn($turn);
            $lines[] = sprintf('%d. user:  %s', $data['number'], $data['user']);
            $lines[] = sprintf('   agent: %s (%d ms)', $data['agent'], $data['latency_ms']);
            foreach ($data['tool_calls'] as $call) {
                $lines[] = sprintf('   tool:  %s %s', $call['name'], json_encode((object) $call['arguments'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            if ($data['handoff']) {
                $lines[] = '   handoff';
            }
        }

        return implode("\n", $lines);
    }

    private static function seconds(int $ms): string
    {
        return number_format($ms / 1000, 3, '.', '');
    }
}
