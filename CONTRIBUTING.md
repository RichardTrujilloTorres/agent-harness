# Contributing

Thanks for helping. Issues and pull requests are welcome.

## Setup

PHP 8.3 or later, Composer, and Xdebug or PCOV for the coverage gates.

```bash
composer install
composer check
```

The same gates are available individually as Composer scripts and as `make` targets. Run `composer list` to see the commands and their descriptions. `composer lint:fix` applies the code style; `composer rector` must remain a no-change dry run.

## Before opening a pull request

- Run `composer check`: Pint, PHPStan at level max with strict rules, Rector, Psalm taint analysis, dependency checks and audit, Pest, architecture tests, line and type coverage, covered-only mutation testing, schema checks, the demo run, and the PHAR smoke test must all pass.
- Add or update tests for every behaviour change. Tests never call a real LLM: use `tests/Support/FakeLlmClient.php`.
- Do not add PHPStan or Psalm baselines, ignore comments, suppressions, skipped tests, or lower a quality threshold. Fix the code or improve the tests.
- Keep the example run at 4 passed and 2 failed. `composer demo:check` verifies the counts and the expected exit code 1.
- Update both JSON schemas when the scenario or config format changes. `composer schema` checks them against the PHP validators, all YAML fixtures, and the examples.
- Update `README.md` and `CHANGELOG.md` (under Unreleased) when behaviour or options change.
- Build and test `build/agent-harness.phar` with `composer phar && composer phar:check` after changing the CLI or packaging.

## Commits and scope

Use small, topical commits with plain messages. Do not add co-author trailers, generated-by lines, or other AI attribution.

The harness stays framework-free: Symfony Console, Yaml and HttpClient are the only runtime dependencies. Please open an issue to discuss larger changes such as new adapters before writing them.
