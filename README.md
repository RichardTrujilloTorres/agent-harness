# agent-harness

A test runner for conversational agents, written in PHP. You describe a conversation in a YAML scenario (who the user is, what they say or want, what must happen), the harness talks to your agent over HTTP and reports pass or fail, so agent behaviour can be checked in CI like any other code.

- Scripted users, or an **LLM-played user** that works towards a goal in character
- Deterministic checks (`tool_called`, `said`, `never_said`, `handoff`, `max_turns`, `latency_ms_under`) and an **LLM judge** for rubrics
- Works with any HTTP agent: request and response fields are remapped by dot-path, so the agent needs no changes
- **HTML, JUnit and JSON transcript reports**, `--retries` with FLAKY detection, `--seed`
- A **Pest plugin** to write conversation tests in your own test suite
- A demo agent with no dependencies and no API key, to try it in two minutes

Status: v0.1. Text conversations over HTTP only. See the [roadmap](#roadmap) for what is planned.

## Contents

- [Install](#install)
- [Quickstart (2 minutes)](#quickstart-2-minutes)
- [Documentation](#documentation)
- [Start a project](#start-a-project)
- [Scenarios](#scenarios)
- [Configuration, LLM user and judge](#configuration-llm-user-and-judge)
- [The run command](#the-run-command)
- [Pest plugin](#pest-plugin)
- [Demo agent](#demo-agent)
- [Security model](#security-model)
- [Development and quality gates](#development-and-quality-gates)
- [Roadmap](#roadmap)

## Install

PHP 8.3 or later.

After the first release, install it in a project with:

```bash
composer require --dev desemax/agent-harness
```

Until then, clone this repository and run `composer install`. The commands below use `bin/agent-harness` from a clone; use `vendor/bin/agent-harness` when installed in another project.

## Quickstart (2 minutes)

From a clone of this repository, start the demo agent in one terminal:

```bash
composer demo        # same as: php -S 127.0.0.1:8787 -t examples/demo-agent/public
```

Run the example scenarios against it in another:

```bash
bin/agent-harness run examples/scenarios --url http://127.0.0.1:8787/chat
```

```
FAIL   Allergen question is not answered with invented claims  (1 turn, 4 ms)
       ✗ never_said "gluten-free": said in turn 1: "Yes, all our dishes are gluten-free."
PASS   Happy path booking  (3 turns, 2 ms)
PASS   Request for a human  (1 turn, 1 ms)
FAIL   Large party goes to staff  (2 turns, 1 ms)
       ✗ handoff true: no handoff in 2 turn(s)
       ✗ never_said "confirmed": said in turn 2: "...turday at 20:00 under the name Marco is confirmed."
PASS   Missing details, agent asks for the name  (1 turn, 1 ms)
PASS   Opening hours question  (1 turn, 1 ms)

6 scenario(s): 4 passed, 2 failed, 0 skipped, 0 errors in 10 ms
```

The two failures are intended: the demo agent claims every dish is gluten-free, and it books a party of 12 instead of handing off to staff. The exit code is 1.

Add `-v` to see every expectation and the full transcript:

```
FAIL   Allergen question is not answered with invented claims  (1 turn, 3 ms)
       ✗ never_said "gluten-free": said in turn 1: "Yes, all our dishes are gluten-free."
       ~ judge: skipped (no llm configured in harness.yaml)
       Transcript:
         1. user:  Are your dishes safe for someone with coeliac disease?
            agent: Yes, all our dishes are gluten-free. (3 ms)
```

Add `--report-html=report.html` and open the file for a readable report of the same run.

## Documentation

The full documentation is the Markdown in [`docs/`](docs/index.md): quickstart, guides, reference, concepts, security and troubleshooting. It reads as plain Markdown on GitHub, and builds into a static site and a PDF. You need Node.js 20 or later.

```bash
npm --prefix docs ci
npm --prefix docs run dev     # read the site locally, with search
npm --prefix docs run build   # build the static site into docs/.vitepress/dist
composer docs:pdf             # build build/agent-harness-docs.pdf
```

The PDF build uses a headless Chromium from Playwright; install it once with `cd docs && npx playwright install --only-shell chromium`. Each GitHub release attaches the PDF. The repository has a GitHub Pages workflow for the site; no hosted address is listed here until it is live.

## Start a project

Run `init` in the project directory to create a commented `harness.yaml` and `scenarios/example.yaml`:

```bash
vendor/bin/agent-harness init
```

Existing files are never overwritten. Edit the agent URL and the example, then follow the printed next steps:

```bash
vendor/bin/agent-harness validate scenarios
vendor/bin/agent-harness run scenarios
```

From a clone, use `bin/agent-harness` instead. To try the example without your own agent, start the bundled demo with `composer demo`.

## Scenarios

```yaml
name: Happy path booking
persona: A friendly customer who answers every question
goal: Book a table for 4 on Saturday at 20:00
user:
  mode: scripted          # scripted | llm
  turns:
    - "Hi, I'd like to book a table for 4"
    - "Saturday at 8pm"
    - "My name is Anna"
max_turns: 5
expect:
  - tool_called:
      name: create_booking
      args: { party_size: 4, day: saturday, time: "20:00", name: Anna }
  - said: confirmed
  - handoff: false
  - max_turns: 3
  - latency_ms_under: 2000
```

Expectations are `tool_called`, `said`, `never_said`, `handoff`, `max_turns`, `latency_ms_under` and `judge` (an LLM checks a rubric). A scenario is PASS, FAIL, ERROR, SKIP or, with `--retries`, FLAKY. `bin/agent-harness validate <path>` checks scenario files and names the file, the field path and the fix for every error. JSON Schemas for scenarios and `harness.yaml` are in `schema/` for editor completion.

See [Writing scenarios](docs/guides/writing-scenarios.md), [Assertions](docs/guides/assertions.md) and the [scenario reference](docs/reference/scenario.md).

## Configuration, LLM user and judge

`run` reads an optional `harness.yaml`: the agent URL, headers and timeout, dot-paths that map your agent's request and response fields, and an optional `llm` section for any server that speaks the OpenAI-compatible chat completions API. The API key is read from the environment variable named in `llm.api_key_env` and never written to a file. Without an `llm` section, `user.mode: llm` scenarios and `judge` expectations are skipped; everything else runs.

See [Connecting your agent](docs/guides/connecting-agent.md), [LLM judge](docs/guides/llm-judge.md) and the [harness.yaml reference](docs/reference/config.md).

## The run command

```bash
bin/agent-harness run <file-or-directory> [options]
```

Options include `--url`, `--config`, `--filter`, `--seed`, `--retries`, `--fail-fast`, `--format=json`, `--report-html`, `--report-junit`, `--transcripts` and `-v`. Exit codes: 0 no failures, 1 a scenario failed, 2 bad input or config, 3 a scenario errored (takes precedence over 1).

See the [CLI reference](docs/reference/cli.md), [Exit codes](docs/reference/exit-codes.md), [Reports](docs/guides/reports.md) and the [JSON output reference](docs/reference/json-output.md).

## Pest plugin

With Pest installed in your project, `conversation()` and the expectations `toPassScenario()`, `toHaveCalledTool()`, `toHaveSaid()` and `toHaveHandedOff()` are available in your tests, with nothing to configure:

```php
it('books a table', function () {
    $result = conversation(__DIR__.'/scenarios/happy-booking.yaml')
        ->against('http://127.0.0.1:8787/chat')
        ->run();

    expect($result)->toPassScenario();
});
```

See [Pest plugin](docs/guides/pest-plugin.md) and [Using it in CI](docs/guides/ci.md).

## Demo agent

`examples/demo-agent/public/index.php` is a small rule-based restaurant booking bot in plain PHP: no dependencies, no API key, deterministic. It has two deliberate bugs so that two example scenarios fail. It speaks the default HTTP contract: `POST /chat` with `{"session_id": "...", "message": "..."}`, answered with `{"reply": "...", "tool_calls": [...], "handoff": false}`.

## Security model

Scenario files and local config are trusted author input. The agent under test and all LLM output are untrusted. The harness wraps judge transcripts in random-nonce delimiters, keeps rubrics outside untrusted data, detects suspicious replies, and prevents agent text from changing the LLM user's persona or ending its conversation. An LLM judge can still be fooled, so safety-critical expectations should be deterministic.

Configured header values and API keys are redacted from terminal, JSON, HTML, JUnit and transcript output. HTTP uses TLS verification, bounded response bodies and explicit redirect policy. YAML object/custom tags, excessive size and excessive nesting are rejected. Reports escape their contexts, use no remote resources, refuse symlink writes, and sanitize filenames and terminal control sequences.

See the [threat model](docs/security/threat-model.md) for each threat, mitigation and regression test, and [SECURITY.md](SECURITY.md) for private vulnerability reporting.

## Development and quality gates

```bash
composer install
composer check       # fast local gate
composer check:ci    # every gate, as CI runs them (heavy)
composer demo        # start the demo agent
composer phar        # build build/agent-harness.phar
composer phar:check  # validate examples and run the demo with the PHAR
```

`composer check` is the local gate: commit messages, Pint, PHPStan at level max with strict rules, dependency checks, the Pest suite, schema tests, and markdownlint and cspell over the docs. It runs no coverage, mutation testing or builds.

`composer check:ci` adds Rector, Psalm taint analysis, the dependency audit, architecture tests, all three coverage gates, the demo, the PHAR smoke test and the docs site build. It uses every CPU core for several minutes and is meant for CI, where the same gates run as separate jobs; the release workflow runs it in full. CI additionally tests PHP 8.3 and 8.4 with lowest and stable dependencies and includes security, SonarCloud, Scorecard and tag-release workflows.

Measured in this hardening run: **336 tests and 1,168 assertions**, **95.3% line coverage**, **100.0% type coverage**, and **94.33% covered-only mutation score**. Pint, PHPStan, Rector and Psalm taint analysis all pass with no errors. These are measured results, not badges.

See [CONTRIBUTING.md](CONTRIBUTING.md) for individual commands and commit rules.

## Roadmap

- Voice mode: text-to-speech for the user, speech-to-text for the agent, barge-in
- More adapters beyond HTTP JSON
- Parallel runs

## License

MIT
