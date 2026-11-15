<?php

declare(strict_types=1);

/**
 * @return array{status: int, stdout: string, stderr: string, calls: int}
 */
function runAuditScript(string $behaviour, int $exitCode = 11): array
{
    $directory = sys_get_temp_dir() . '/agent-harness-audit-test-' . bin2hex(random_bytes(4));
    mkdir($directory);
    $composer = $directory . '/composer';
    $php = $directory . '/php';
    $state = $directory . '/calls';

    file_put_contents($composer, <<<'BASH'
#!/usr/bin/env bash
count=0
if [[ -f "$FAKE_AUDIT_STATE" ]]; then
  read -r count < "$FAKE_AUDIT_STATE"
fi
count=$((count + 1))
printf '%s\n' "$count" > "$FAKE_AUDIT_STATE"

case "$FAKE_AUDIT_BEHAVIOUR" in
  crash)
    if [[ "$count" -eq 1 ]]; then
      exit "$FAKE_AUDIT_EXIT"
    fi
    printf '%s\n' '{"advisories": [], "abandoned": []}'
    ;;
  crash-output)
    printf 'audit failed before a response\n'
    exit "$FAKE_AUDIT_EXIT"
    ;;
  findings)
    printf 'Found 1 security advisory\n'
    exit 1
    ;;
  success)
    printf '%s\n' '{"advisories": [], "abandoned": []}'
    ;;
  other)
    exit "$FAKE_AUDIT_EXIT"
    ;;
esac
BASH);
    file_put_contents($php, <<<'BASH'
#!/usr/bin/env bash
while [[ "${1:-}" == "-d" ]]; do
  shift 2
done
program="$1"
shift
exec "$program" "$@"
BASH);
    chmod($composer, 0755);
    chmod($php, 0755);

    $environment = array_merge(stringKeyed($_ENV), [
        'PATH' => $directory . ':' . getenv('PATH'),
        'FAKE_AUDIT_STATE' => $state,
        'FAKE_AUDIT_BEHAVIOUR' => $behaviour,
        'FAKE_AUDIT_EXIT' => (string) $exitCode,
    ]);
    $process = proc_open(
        ['bash', projectRoot() . '/scripts/audit', '--format=json'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $environment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the audit wrapper.');
    }

    try {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $calls = file_exists($state) ? (int) file_get_contents($state) : 0;

        return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr, 'calls' => $calls];
    } finally {
        unlink($composer);
        unlink($php);
        if (file_exists($state)) {
            unlink($state);
        }
        rmdir($directory);
    }
}

it('retries an empty-output crash with curl-multi disabled', function (int $exitCode): void {
    $result = runAuditScript('crash', $exitCode);

    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toContain('"advisories": []')
        ->and($result['stderr'])->toContain('retrying with curl-multi disabled')
        ->and($result['calls'])->toBe(2);
})->with([[11], [139]]);

it('returns audit findings without retrying', function (): void {
    $result = runAuditScript('findings');

    expect($result['status'])->toBe(1)
        ->and($result['stdout'])->toContain('Found 1 security advisory')
        ->and($result['calls'])->toBe(1);
});

it('does not retry a crash that produced output', function (): void {
    $result = runAuditScript('crash-output');

    expect($result['status'])->toBe(11)
        ->and($result['stdout'])->toContain('audit failed before a response')
        ->and($result['calls'])->toBe(1);
});

it('does not retry another empty-output failure', function (): void {
    $result = runAuditScript('other', 2);

    expect($result['status'])->toBe(2)
        ->and($result['calls'])->toBe(1);
});

it('returns a successful audit without retrying', function (): void {
    $result = runAuditScript('success');

    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toContain('"abandoned": []')
        ->and($result['calls'])->toBe(1);
});
