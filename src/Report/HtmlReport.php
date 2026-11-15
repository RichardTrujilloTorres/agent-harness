<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Report;

use DateTimeImmutable;
use Desemax\AgentHarness\Runner\ExpectationStatus;
use Desemax\AgentHarness\Runner\ScenarioResult;
use Desemax\AgentHarness\Runner\ScenarioStatus;
use Desemax\AgentHarness\Runner\SuiteResult;

/**
 * One self-contained HTML file: inline CSS, no scripts, no external assets.
 */
final class HtmlReport
{
    private const string CSS = <<<'CSS'
        :root { --bg: #ffffff; --fg: #1f2328; --muted: #656d76; --border: #d0d7de; --panel: #f6f8fa;
          --pass: #1a7f37; --fail: #cf222e; --skip: #9a6700; --error: #a40e26; --flaky: #8250df; }
        @media (prefers-color-scheme: dark) {
          :root { --bg: #0d1117; --fg: #e6edf3; --muted: #8d96a0; --border: #30363d; --panel: #161b22;
            --pass: #3fb950; --fail: #f85149; --skip: #d29922; --error: #ff7b72; --flaky: #a371f7; }
        }
        * { box-sizing: border-box; }
        body { margin: 0 auto; max-width: 960px; padding: 24px 16px 48px; background: var(--bg); color: var(--fg);
          font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 17px; margin: 0; display: flex; gap: 10px; align-items: baseline; flex-wrap: wrap; }
        h3 { font-size: 14px; margin: 16px 0 6px; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
        .muted { color: var(--muted); }
        .summary { display: flex; flex-wrap: wrap; gap: 8px 20px; margin: 16px 0 24px; padding: 12px 16px;
          border: 1px solid var(--border); border-radius: 8px; background: var(--panel); }
        .summary b { font-size: 18px; }
        section { border: 1px solid var(--border); border-radius: 8px; padding: 16px; margin: 0 0 16px; }
        .badge { display: inline-block; min-width: 54px; text-align: center; padding: 1px 8px; border-radius: 4px;
          font: 600 12px/20px ui-monospace, SFMono-Regular, Menlo, monospace; color: #fff; }
        .passed { background: var(--pass); } .failed { background: var(--fail); } .skipped { background: var(--skip); }
        .error { background: var(--error); } .flaky { background: var(--flaky); }
        .c-passed { color: var(--pass); } .c-failed { color: var(--fail); } .c-skipped { color: var(--skip); }
        .c-error { color: var(--error); } .c-flaky { color: var(--flaky); }
        ul.expect { list-style: none; padding: 0; margin: 0; }
        ul.expect li { padding: 2px 0; }
        ul.expect .mark { display: inline-block; width: 1.4em; font-weight: 700; }
        .message { margin: 8px 0 0; }
        .turn { border-left: 3px solid var(--border); padding: 4px 0 4px 12px; margin: 8px 0; }
        .who { font-weight: 600; display: inline-block; min-width: 3.5em; }
        .text { white-space: pre-wrap; overflow-wrap: anywhere; }
        pre { margin: 4px 0 0; padding: 8px; background: var(--panel); border-radius: 6px; overflow-x: auto;
          font: 12px/1.45 ui-monospace, SFMono-Regular, Menlo, monospace; white-space: pre-wrap; overflow-wrap: anywhere; }
        .meta { font-size: 13px; color: var(--muted); }
        CSS;

    private const array MARKS = ['passed' => '✓', 'failed' => '✗', 'skipped' => '~', 'error' => '!'];

    public function render(SuiteResult $suite, ?DateTimeImmutable $generatedAt = null): string
    {
        $generatedAt ??= new DateTimeImmutable();
        $counts = $suite->counts();

        $summary = [];
        $summary[] = sprintf('<span><b>%d</b> scenario(s)</span>', count($suite->results));
        foreach (ScenarioStatus::cases() as $status) {
            $summary[] = sprintf('<span class="c-%s"><b>%d</b> %s</span>', $status->value, $counts[$status->value], $this->e(strtolower($status->label())));
        }
        $summary[] = sprintf('<span class="muted">%d ms</span>', $suite->durationMs);

        $sections = array_map($this->scenario(...), $suite->results, ResultData::suiteSlugs($suite));

        return '<!DOCTYPE html>' . "\n"
            . '<html lang="en">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<meta name="color-scheme" content="light dark">' . "\n"
            . '<title>agent-harness report</title>' . "\n"
            . '<style>' . "\n" . self::CSS . "\n" . '</style>' . "\n"
            . '</head>' . "\n"
            . '<body>' . "\n"
            . '<h1>agent-harness report</h1>' . "\n"
            . sprintf('<div class="muted">Generated %s</div>', $this->e($generatedAt->format('Y-m-d H:i:s T'))) . "\n"
            . '<div class="summary">' . implode(' ', $summary) . '</div>' . "\n"
            . implode("\n", $sections) . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    private function scenario(ScenarioResult $result, string $id): string
    {
        $data = ResultData::scenario($result);
        $html = [];
        $html[] = '<section id="' . $this->e($id) . '">';
        $html[] = sprintf(
            '<h2><span class="badge %s">%s</span> <span>%s</span></h2>',
            $this->e($data['status']),
            $this->e($result->status->label()),
            $this->e($data['name']),
        );

        $meta = [sprintf('%d %s', count($data['turns']), count($data['turns']) === 1 ? 'turn' : 'turns'), sprintf('%d ms', $data['duration_ms'])];
        if ($data['attempts'] > 1) {
            $meta[] = sprintf('%d attempts', $data['attempts']);
        }
        if ($data['file'] !== null) {
            $meta[] = $data['file'];
        }
        $html[] = '<div class="meta">' . $this->e(implode(' · ', $meta)) . '</div>';

        if ($data['message'] !== null) {
            $html[] = '<p class="message">' . $this->e($data['message']) . '</p>';
        }

        if ($data['expectations'] !== []) {
            $html[] = '<h3>Expectations</h3>';
            $html[] = '<ul class="expect">';
            foreach ($result->expectations as $expectation) {
                $status = $expectation->status->value;
                $text = $expectation->status === ExpectationStatus::Skipped
                    ? sprintf('%s: skipped (%s)', $expectation->expectation->type->value, $expectation->reason)
                    : $expectation->reason;
                $html[] = sprintf(
                    '<li class="c-%s"><span class="mark" title="%s">%s</span><span class="text">%s</span></li>',
                    $this->e($status),
                    $this->e($status),
                    self::MARKS[$status],
                    $this->e($text),
                );
            }
            $html[] = '</ul>';
        }

        $html[] = '<h3>Transcript</h3>';
        if ($data['turns'] === []) {
            $html[] = '<p class="muted">No turns.</p>';
        }
        foreach ($data['turns'] as $turn) {
            $html[] = '<div class="turn">';
            $html[] = sprintf('<div class="meta">Turn %d · %d ms%s</div>', $turn['number'], $turn['latency_ms'], $turn['handoff'] ? ' · <strong>handoff</strong>' : '');
            $html[] = '<div><span class="who">User</span> <span class="text">' . $this->e($turn['user']) . '</span></div>';
            $html[] = '<div><span class="who">Agent</span> <span class="text">' . $this->e($turn['agent']) . '</span></div>';
            foreach ($turn['tool_calls'] as $call) {
                $html[] = '<div><span class="who">Tool</span> <code>' . $this->e($call['name']) . '</code>'
                    . '<pre>' . $this->e(ResultData::json((object) $call['arguments'])) . '</pre></div>';
            }
            $html[] = '</div>';
        }

        $html[] = '</section>';

        return implode("\n", $html);
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
