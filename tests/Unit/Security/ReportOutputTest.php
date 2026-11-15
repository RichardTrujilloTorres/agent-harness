<?php

declare(strict_types=1);

use Desemax\AgentHarness\Agent\AgentReply;
use Desemax\AgentHarness\Agent\ToolCall;
use Desemax\AgentHarness\Report\HtmlReport;
use Desemax\AgentHarness\Report\JunitReport;
use Desemax\AgentHarness\Report\ReportWriter;
use Desemax\AgentHarness\Report\TranscriptReport;
use Desemax\AgentHarness\Runner\ExpectationResult;
use Desemax\AgentHarness\Runner\ScenarioResult;
use Desemax\AgentHarness\Runner\ScenarioStatus;
use Desemax\AgentHarness\Runner\SuiteResult;
use Desemax\AgentHarness\Runner\Transcript;
use Desemax\AgentHarness\Scenario\Expectation;
use Desemax\AgentHarness\Scenario\ExpectationType;
use Desemax\AgentHarness\Scenario\Scenario;
use Desemax\AgentHarness\Scenario\UserMode;
use Desemax\AgentHarness\Scenario\UserScript;

function hostileSuite(string $name = '"><img src=x onerror=alert(1)>', string $reply = "<script>alert('x')</script>"): SuiteResult
{
    $expectation = new Expectation(ExpectationType::Said, 'x');
    $transcript = (new Transcript())->with("' onmouseover='alert(1)", new AgentReply($reply, [new ToolCall('<b>t</b>', ['k' => '"></pre><script>'])]));

    return new SuiteResult([new ScenarioResult(
        new Scenario($name, new UserScript(UserMode::Scripted, ['a']), [$expectation], file: '"><x>.yaml'),
        ScenarioStatus::Failed,
        $transcript,
        [ExpectationResult::failed($expectation, '<i>reason</i>')],
        5,
        "message \x01\x1B[31m",
        warnings: ['<u>warning</u>'],
    )], 5);
}

function tempDir(): string
{
    $dir = sys_get_temp_dir() . '/agent-harness-out-' . bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

it('escapes untrusted text in HTML text and attribute contexts', function (): void {
    $html = (new HtmlReport())->render(hostileSuite());

    expect($html)->not->toContain('<script')
        ->and($html)->not->toContain('<img')
        ->and($html)->not->toContain('<b>t</b>')
        ->and($html)->not->toContain('<i>reason')
        ->and($html)->not->toContain('<u>warning')
        ->and($html)->not->toContain("' onmouseover='")
        ->and($html)->toContain('&quot;&gt;&lt;x&gt;.yaml')
        ->and($html)->toContain('&quot;&gt;&lt;/pre&gt;&lt;script&gt;')
        ->and($html)->toContain('&quot;&gt;&lt;img src=x onerror=alert(1)&gt;')
        ->and($html)->toContain('&apos; onmouseover=&apos;alert(1)')
        ->and($html)->toContain('id="img-src-x-onerror-alert-1"');
});

it('sets a restrictive Content-Security-Policy that matches the inline stylesheet', function (): void {
    $html = (new HtmlReport())->render(hostileSuite());
    preg_match('#<style>(.*?)</style>#s', $html, $style);
    preg_match('#<meta http-equiv="Content-Security-Policy" content="([^"]+)">#', $html, $csp);
    $policy = html_entity_decode($csp[1] ?? '', ENT_QUOTES | ENT_HTML5);

    expect($policy)->toBe(HtmlReport::csp())
        ->and($policy)->toStartWith("default-src 'none'; style-src 'sha256-")
        ->and($policy)->toContain("img-src 'none'; base-uri 'none'; form-action 'none'")
        ->and($policy)->toContain("'sha256-" . base64_encode(hash('sha256', $style[1] ?? '', true)) . "'")
        ->and($html)->not->toContain('<script')
        ->and($html)->toContain('<meta name="referrer" content="no-referrer">');
});

it('writes JUnit XML that parses even with control characters and invalid UTF-8 in the text', function (): void {
    $xml = (new JunitReport())->render(hostileSuite("bad \x01\x08 name \xFF", "reply \x00\x0B\x1F \u{FFFE} end"));
    $doc = new DOMDocument();

    expect($doc->loadXML($xml))->toBeTrue()
        ->and($xml)->not->toMatch('/[\x00-\x08\x0B\x0C\x0E-\x1F]/')
        ->and($xml)->toContain("name=\"bad \u{FFFD}\u{FFFD} name ?\"")
        ->and(JunitReport::xml("a\tb\nc\rd"))->toBe("a\tb\nc\rd")
        ->and(JunitReport::xml("x\u{10000}y"))->toBe("x\u{10000}y");
});

it('keeps transcript files inside the target directory whatever the scenario name', function (string $name, string $file): void {
    $dir = tempDir();
    $paths = (new TranscriptReport())->write(hostileSuite($name), $dir);

    expect($paths)->toBe([$dir . DIRECTORY_SEPARATOR . $file])
        ->and(dirname($paths[0]))->toBe($dir)
        ->and(is_file($paths[0]))->toBeTrue();
})->with([
    'parent traversal' => ['../../x', 'x.json'],
    'absolute path' => ['/etc/passwd', 'etc-passwd.json'],
    'windows traversal' => ['..\\..\\boot.ini', 'boot-ini.json'],
    'dots only' => ['..', 'scenario.json'],
    'null byte' => ["a\x00b", 'a-b.json'],
]);

it('refuses to write through a symlink', function (): void {
    $dir = tempDir();
    $outside = tempDir() . '/victim.txt';
    file_put_contents($outside, 'original');
    symlink($outside, $dir . '/report.html');

    expect(fn() => ReportWriter::write($dir . '/report.html', 'x'))->toThrow(RuntimeException::class, 'Refusing to write ' . $dir . '/report.html: it is a symbolic link')
        ->and((string) file_get_contents($outside))->toBe('original');
});

it('refuses a planted symlink in the transcripts directory', function (): void {
    $dir = tempDir();
    $outside = tempDir() . '/victim.json';
    file_put_contents($outside, 'original');
    symlink($outside, $dir . '/x.json');

    expect(fn(): array => (new TranscriptReport())->write(hostileSuite('../../x'), $dir))->toThrow(RuntimeException::class, 'it is a symbolic link')
        ->and((string) file_get_contents($outside))->toBe('original');
});

it('replaces an existing regular file atomically and leaves no temporary file', function (): void {
    $dir = tempDir();
    file_put_contents($dir . '/r.xml', 'old');
    ReportWriter::write($dir . '/r.xml', 'new');

    expect((string) file_get_contents($dir . '/r.xml'))->toBe('new')
        ->and(scandir($dir))->toBe(['.', '..', 'r.xml'])
        ->and(fileperms($dir . '/r.xml') & 0o777)->toBe(0o666 & ~umask());
});

it('refuses a directory as the report file', function (): void {
    $dir = tempDir();

    expect(fn() => ReportWriter::write($dir, 'x'))->toThrow(RuntimeException::class, 'it is a directory');
});
