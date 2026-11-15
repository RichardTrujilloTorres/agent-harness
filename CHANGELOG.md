# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [0.1.0] - Unreleased

First release.

### Added

- YAML scenario format: `name`, `persona`, `goal`, `user` (`scripted` turns or `llm` with optional `opening`), `max_turns`, `expect`.
- `validate` command: every error with file, field path and reason.
- `run` command: plays scenarios against an agent over HTTP, prints PASS, FAIL, SKIP, ERROR or FLAKY with reasons, `-v` for full transcripts, `--filter`.
- HTTP adapter with request and response fields remapped by dot-path in `harness.yaml`, custom headers and timeout.
- Deterministic expectations: `tool_called` (subset argument matching), `said`, `never_said`, `handoff`, `max_turns`, `latency_ms_under`.
- LLM access through the `LlmClient` interface with an OpenAI-compatible chat completions client; API key read from an environment variable named in the config.
- LLM-played user: persona and goal driven, ends with `[[DONE]]`, a handoff or `max_turns`.
- LLM judge for `judge` expectations: JSON verdict with a reason, one retry on invalid JSON, then an error.
- Without an `llm` section, LLM scenarios and judge expectations are skipped with a clear reason.
- `--seed` for the LLM and `--retries` with FLAKY reporting.
- Reports: self-contained HTML (`--report-html`), JUnit XML (`--report-junit`), one JSON transcript per scenario (`--transcripts`).
- Pest plugin: `conversation()->against()->run()` with `toPassScenario()`, `toHaveCalledTool()`, `toHaveSaid()`, `toHaveHandedOff()`.
- Demo restaurant booking agent with six example scenarios (two fail on purpose) and two LLM example scenarios.
- Exit codes: 0 pass, 1 failures, 2 bad input, 3 errors.
- `init` creates a commented config and example scenario without overwriting; `list` shows scenario modes and expectation counts.
- Running `agent-harness` without a command prints the command overview.
- Versioned JSON output for `validate` and `run`, `--fail-fast`, and did-you-mean suggestions for unknown fields and expectation types.
- Draft 2020-12 JSON Schemas for scenarios and config, checked against the PHP validators and all YAML fixtures.
- Prompt-injection hardening for the judge and LLM-played user, suspicious-reply warnings, secret redaction, safe HTTP/YAML limits, secure report and terminal output, and a hardened demo agent.
- Threat model and private security-reporting policy.
- Pint, strict PHPStan, Rector, dependency, architecture, Psalm taint, line/type coverage and covered-mutation gates, all available through `composer check`.
- CI, security, SonarCloud, Scorecard and tag-release workflows with pinned third-party actions.
- PHAR build and smoke checks; release workflow attaches the PHAR, SHA-256 checksum and build provenance.
