# Threat model

This document describes what agent-harness protects, where trust changes hands, and how each threat is handled. Every row in the table names the code that handles it and the test that proves it. Report a vulnerability as described in [SECURITY.md](../../SECURITY.md).

## What agent-harness does

A developer or a CI job runs `agent-harness run` (or a Pest test using `conversation()`) on scenario files. The harness sends user messages to the agent under test over HTTP, optionally lets an LLM play the user or judge the transcript, and writes the results to the terminal, JSON, HTML, JUnit XML and transcript files.

## Assets

| Asset | Why it matters |
|---|---|
| LLM API key | Read from the environment variable named in `llm.api_key_env`. Leaking it costs money and access. |
| Configured agent headers (`agent.headers`) | Often carry API keys or session tokens for the agent under test. Every header value is treated as a secret. |
| Integrity of the verdict | A test run must not pass because the system under test asked it to. |
| The developer's terminal and file system | Output is printed and files are written on the machine that runs the harness. |
| CI logs and artifacts | Reports and transcripts are uploaded and may be public. |

## Trust boundaries

| Source | Trust | Notes |
|---|---|---|
| Scenario files (`*.yaml`) | Trusted author | Written by the person who runs the tests. Still parsed defensively: no PHP tags, size, depth and node caps. |
| `harness.yaml`, CLI options, environment | Trusted | Chosen by the person who runs the tests. |
| The agent under test (HTTP responses) | **Untrusted** | The whole point is to test it; it may be buggy, compromised or hostile. |
| LLM output (LLM-played user, judge) | **Untrusted** | It reads agent text and can be steered by it; it can also be wrong on its own. |

## Threats

| # | Threat | Impact | Status | Where in code | Test |
|---|---|---|---|---|---|
| 1 | Agent reply tells the LLM judge to ignore its instructions and answer `pass: true` | False pass | Mitigated | `src/Assertion/JudgeEvaluator.php`: system prompt marks the transcript as untrusted data; rubric stays in the system message; transcript between `<<<TRANSCRIPT nonce>>>` markers with a fresh random nonce per request | `tests/Unit/Security/JudgeInjectionTest.php`: "tells the judge that the transcript is untrusted data…", "keeps the rubric in the system message…", "uses a new random nonce for every judge request…" |
| 2 | Agent reply closes the transcript block early and adds fake instructions | False pass | Fixed | `JudgeEvaluator::neutralise()`: removes the nonce (any case) and collapses `<<<`/`>>>` runs in all untrusted text, tool arguments included | `JudgeInjectionTest`: "neutralises the nonce and the markers…" |
| 3 | Judge answer is loosely parsed (prose, code fences, extra keys) and an injected fragment is taken as the verdict | False pass | Fixed | `JudgeEvaluator::verdict()`: only a JSON object with exactly a boolean `pass` and a non-empty string `reason`; anything else is retried once, then an error (never a pass) | `JudgeInjectionTest`: "accepts only an object with exactly…", "errors instead of passing when the judge echoes injected text" |
| 4 | Injection that still gets through to the judge | False pass | **Accepted**, with a deterministic warning | `src/Security/InjectionDetector.php` flags known patterns as a `suspicious_reply` warning in CLI, JSON, HTML and JUnit output; it does not change the status | `tests/Unit/Security/InjectionDetectorTest.php`; `tests/Feature/SecretRedactionTest.php`: "surfaces the suspicious_reply warning…" |
| 5 | Agent ends an LLM-played conversation early by writing `[[DONE]]` | Scenario stops before the behaviour under test | Fixed | `LlmUser::neutralise()` defuses the token in agent replies before the LLM sees them; only the LLM user's own output can end the conversation | `tests/Unit/Security/LlmUserInjectionTest.php`: "defuses the end token…", "keeps playing when the agent injects the end token…" |
| 6 | Agent tells the LLM-played user to change persona or goal | Wrong test, false pass | Mitigated | `LlmUser::systemPrompt()`: persona and goal only in the system message, which says agent messages are untrusted and never instructions | `LlmUserInjectionTest`: "tells the LLM user that agent messages are untrusted…", "keeps the persona in the system prompt on every turn…" |
| 7 | API key or header value printed in output, reports, transcripts or exceptions (for example an agent that echoes request headers, or an LLM that repeats its key) | Credential leak, often into public CI logs | Fixed | `src/Security/Redactor.php` applied to every result before printing or writing (`RunCommand`, `Pest\Conversation`); `OpenAiCompatibleClient::redact()` scrubs the key from its own errors; `#[\SensitiveParameter]` on the key | `tests/Feature/SecretRedactionTest.php`: canary secrets checked in text and JSON output at `-vvv`, HTML, JUnit and transcript files, and Pest results; `tests/Unit/Security/RedactorTest.php`; `BoundedHttpTest`: "never puts the API key in an LLM error…" |
| 8 | Agent URL with another scheme (`file://`, `gopher://`) | Local file read, SSRF variants | Fixed | `BoundedHttp::isHttpUrl()` in `ConfigLoader`, `RunCommand --url`, `Conversation::against()`, `HttpAgentAdapter` and at request time | `tests/Unit/Security/BoundedHttpTest.php`: "accepts only http and https URLs with a host", "refuses a non-http URL at request time too" |
| 9 | TLS verification disabled | Man in the middle reads the key and responses | Fixed | `BoundedHttp::postJson()` always sets `verify_peer` and `verify_host` to true; no option turns them off | `BoundedHttpTest`: "always verifies TLS…", "applies the same limits to the LLM client…" |
| 10 | Agent redirects the harness to another host (with the configured headers) | Header leak, SSRF | Fixed | Redirects off by default (`max_redirects: 0`); `agent.follow_redirects: true` allows up to 5; the LLM client never follows redirects | `BoundedHttpTest`: "…does not follow redirects by default", "follows up to 5 redirects only when…", "explains an unfollowed redirect…" |
| 11 | Agent or LLM sends a huge or endless body | Memory exhaustion, hung CI | Fixed | Body cap (`agent.max_response_bytes`, `llm.max_response_bytes`, default 1 MiB) checked on `Content-Length` and while streaming; timeouts apply to idle time and to the whole request | `BoundedHttpTest`: "caps the agent response body by Content-Length", "caps a streamed agent response body…", "accepts a body of exactly the cap" |
| 12 | YAML with `!php/object` or `!php/const` | Object injection, constant disclosure (Symfony YAML silently turns them into `null` without flags, hiding the problem) | Fixed | `src/Scenario/YamlReader.php` rejects PHP tags with a clear validation error; custom tags are rejected by the parser | `tests/Unit/Security/YamlReaderTest.php`: "rejects PHP object, constant and other PHP tags…", "rejects PHP tags in harness.yaml too", "rejects custom tags" |
| 13 | Very large, deeply nested or alias-expanding YAML | Memory and CPU exhaustion | Fixed | `YamlReader`: 1 MiB size cap, depth cap 32, 100 000 value cap | `YamlReaderTest`: "caps the file size", "caps the nesting depth", "caps the number of values…" |
| 14 | Agent text with HTML in the HTML report | Script execution when the report is opened | Fixed | `HtmlReport`: every value escaped with `ENT_QUOTES \| ENT_SUBSTITUTE`; no remote resources; Content-Security-Policy `default-src 'none'` with the stylesheet allowed by hash; `no-referrer` | `tests/Unit/Security/ReportOutputTest.php`: "escapes untrusted text in HTML text and attribute contexts", "sets a restrictive Content-Security-Policy…" |
| 15 | Control characters or invalid UTF-8 in JUnit XML | Broken CI report parsing | Fixed | `JunitReport::xml()` replaces characters outside the XML 1.0 range and invalid UTF-8 | `ReportOutputTest`: "writes JUnit XML that parses even with control characters…" |
| 16 | Scenario name such as `../../x` used as a transcript file name | File written outside the target directory | Fixed | `ResultData::slug()` keeps `[a-z0-9-]` only | `ReportOutputTest`: "keeps transcript files inside the target directory…" |
| 17 | Symlink planted at a report or transcript path | Overwriting a file elsewhere | Fixed | `ReportWriter::write()` refuses symlinks and writes via a temporary file plus `rename()`, which replaces a link instead of following it | `ReportOutputTest`: "refuses to write through a symlink", "refuses a planted symlink in the transcripts directory", "replaces an existing regular file atomically…" |
| 18 | Agent or LLM text with ANSI escapes or console tags | Terminal injection: hidden output, fake results, changed window title, clickable links | Fixed | `src/Security/TerminalText.php` strips CSI, OSC, DCS and other escapes and control characters, and escapes Symfony console tags; used for every untrusted value printed by `run` and `validate` | `tests/Unit/Security/TerminalTextTest.php`; `SecretRedactionTest`: "strips escape sequences and does not interpret console tags…" |
| 19 | Demo agent: session id used in a file name | Path traversal | Fixed | `examples/demo-agent/public/index.php`: only the SHA-256 of the session id is used | `tests/Feature/DemoAgentTest.php`: "stores session state under a hash of the session id…" |
| 20 | Demo agent: oversized bodies, wrong method or content type | Resource use, confusing behaviour | Fixed | 16 KiB body cap (413), `POST` only (405 with `Allow`), `application/json` only (415) | `DemoAgentTest`: "answers 413…", "answers 405…", "answers 415…" |
| 21 | Demo agent: reflected text served as HTML | XSS if opened in a browser | Fixed | JSON with `<`, `>`, `&`, quotes escaped, `charset=utf-8` and `X-Content-Type-Options: nosniff` | `DemoAgentTest`: "escapes HTML characters in JSON and sends nosniff"; Psalm taint analysis |
| 22 | Demo agent exposed on a network | Anyone can use it | Accepted | It is a local demo: README and the file header say to bind it to `127.0.0.1` only, and `composer demo` does | — |
| 23 | Secrets committed to the repository | Credential leak | Mitigated | gitleaks over the full history in CI (`.github/workflows/security.yml`) | CI job |
| 24 | Vulnerable dependency | Depends on the advisory | Mitigated | `composer audit` locally (`composer check`) and in CI, weekly scheduled run, Dependabot | CI job |
| 25 | Tainted data reaching a sink in the code (SQL, shell, HTML, file) | Injection | Mitigated | Psalm taint analysis on `src`, `bin` and the demo agent, with no suppressions | `composer taint`, CI job |
| 26 | Agent misbehaviour inside the agent's own systems (it books, emails, deletes) | Side effects of testing | Accepted | The harness only sends the scripted or LLM-written messages; run it against a test deployment of the agent | — |

## Honest limits

- **An LLM judge can be fooled.** The hardening above makes injection harder and visible; it does not make an LLM a security boundary. A model can still follow text in the transcript, misread the rubric or be inconsistent. Checks that matter (a tool was or was not called, a forbidden phrase was never said, a handoff happened) should be deterministic expectations: `tool_called`, `never_said`, `said`, `handoff`, `max_turns`, `latency_ms_under`. Use `judge` for qualities that cannot be checked deterministically, and treat a `suspicious_reply` warning as a reason to read the transcript.
- **The injection detector is a heuristic.** It catches common phrasings in English and misses others. It never changes a result, so a false positive costs a look, and a false negative costs nothing that the judge hardening does not already cover.
- **Redaction covers known values only:** the configured header values (4 characters or longer) and the LLM API key. A secret that the agent invents or that is not configured cannot be recognised. Values shorter than 4 characters are not redacted, because replacing them would mangle ordinary text.
- **The LLM-played user is steered by agent text by design.** It has to read and answer the agent. The rules keep its persona and the end of the conversation under the harness's control; they do not make it immune to persuasion.
