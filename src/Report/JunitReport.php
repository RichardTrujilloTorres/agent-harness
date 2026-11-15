<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Report;

use Desemax\AgentHarness\Runner\ExpectationResult;
use Desemax\AgentHarness\Runner\ScenarioResult;
use Desemax\AgentHarness\Runner\ScenarioStatus;
use Desemax\AgentHarness\Runner\SuiteResult;
use DOMDocument;
use DOMElement;

/**
 * JUnit XML: one testsuite, one testcase per scenario.
 */
final class JunitReport
{
    public const string SUITE_NAME = 'agent-harness';

    public function render(SuiteResult $suite): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $counts = [
            'tests' => (string) count($suite->results),
            'failures' => (string) $suite->count(ScenarioStatus::Failed),
            'errors' => (string) $suite->count(ScenarioStatus::Error),
            'skipped' => (string) $suite->count(ScenarioStatus::Skipped),
            'time' => $this->seconds($suite->durationMs),
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
        $case->setAttribute('name', self::xml($result->scenario->name));
        $case->setAttribute('classname', self::xml($result->scenario->file ?? self::SUITE_NAME));
        if ($result->scenario->file !== null) {
            $case->setAttribute('file', self::xml($result->scenario->file));
        }
        $case->setAttribute('time', $this->seconds($result->durationMs));

        if ($result->warnings !== []) {
            $properties = $doc->createElement('properties');
            foreach ($result->warnings as $warning) {
                $property = $doc->createElement('property');
                $property->setAttribute('name', 'warning');
                $property->setAttribute('value', self::xml($warning));
                $properties->appendChild($property);
            }
            $case->appendChild($properties);
        }

        switch ($result->status) {
            case ScenarioStatus::Failed:
                $failures = $result->failures();
                $case->appendChild($this->problem($doc, 'failure', $failures[0]->reason ?? 'failed', $this->reasons($failures)));
                break;
            case ScenarioStatus::Error:
                $errors = $result->errors();
                $message = $result->message ?? ($errors[0]->reason ?? 'error');
                $case->appendChild($this->problem($doc, 'error', $message, $errors === [] ? $message : $this->reasons($errors)));
                break;
            case ScenarioStatus::Skipped:
                $skipped = $doc->createElement('skipped');
                $skipped->setAttribute('message', self::xml($result->message ?? 'nothing could be checked'));
                $case->appendChild($skipped);
                break;
            case ScenarioStatus::Passed:
            case ScenarioStatus::Flaky:
                break;
        }

        $out = $doc->createElement('system-out');
        $out->appendChild($doc->createTextNode(self::xml($this->transcript($result))));
        $case->appendChild($out);

        if ($result->warnings !== []) {
            $err = $doc->createElement('system-err');
            $err->appendChild($doc->createTextNode(self::xml(implode("\n", $result->warnings))));
            $case->appendChild($err);
        }

        return $case;
    }

    /**
     * @param list<ExpectationResult> $results
     */
    private function reasons(array $results): string
    {
        return implode("\n", array_map(static fn(ExpectationResult $r): string => $r->reason, $results));
    }

    private function problem(DOMDocument $doc, string $tag, string $message, string $details): DOMElement
    {
        $el = $doc->createElement($tag);
        $el->setAttribute('message', self::xml($message));
        $el->setAttribute('type', $tag === 'failure' ? 'ExpectationFailed' : 'ScenarioError');
        $el->appendChild($doc->createTextNode(self::xml($details)));

        return $el;
    }

    private function transcript(ScenarioResult $result): string
    {
        $lines = $result->transcript->lines();
        if ($result->message !== null) {
            array_unshift($lines, $result->message);
        }

        return implode("\n", $lines);
    }

    private function seconds(int $ms): string
    {
        return number_format($ms / 1000, 3, '.', '');
    }

    /**
     * Text that is legal in XML 1.0: invalid UTF-8 and characters outside the XML Char range
     * (most control characters, lone surrogates, U+FFFE/U+FFFF) become U+FFFD.
     */
    public static function xml(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');

        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', "\u{FFFD}", $text);
    }
}
