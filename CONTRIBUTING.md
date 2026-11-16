# Contributing

Thanks for helping. Issues and pull requests are welcome.

## Setup

PHP 8.3 or later, Composer, and Node.js 20 or later with npm for the commit and documentation checks. Xdebug or PCOV is needed only for the coverage gates in `composer check:ci`.

```bash
composer install
npm ci
npm --prefix docs ci
composer check
```

There are two entry points:

- `composer check` is the fast local gate: commit messages, Pint, PHPStan, dependency checks, the Pest suite, schema checks, and markdownlint and cspell over the docs. It runs no coverage, mutation testing or builds. Run it before every push.
- `composer check:ci` runs every gate: `composer check` plus Rector, Psalm taint analysis, the dependency audit, architecture tests, line coverage (at least 95 %), type coverage (100 %), covered-only mutation testing (at least 85 %), the demo run, the PHAR build and smoke test, and the docs site build with the docs tests. It is heavy: it uses every CPU core for several minutes. CI runs these gates as separate jobs on every push and pull request, and the release workflow runs `composer check:ci`. Run it locally only before a release, if at all.

The same gates are available individually as Composer scripts and as `make` targets. Run `composer list` to see the commands and their descriptions. `composer lint:fix` applies the code style; `composer rector` must remain a no-change dry run.

## Before opening a pull request

- Run `composer check`; it must pass. CI then runs the heavy gates of `composer check:ci`, and they must pass too. To run one heavy gate locally, use its own script, for example `composer coverage` or `composer mutate`.
- Add or update tests for every behaviour change. Tests never call a real LLM: use `tests/Support/FakeLlmClient.php`.
- Do not add PHPStan or Psalm baselines, ignore comments, suppressions, skipped tests, or lower a quality threshold. Fix the code or improve the tests.
- Keep the example run at 4 passed and 2 failed. `composer demo:check` verifies the counts and the expected exit code 1.
- Update both JSON schemas when the scenario or config format changes. `composer schema` checks them against the PHP validators, all YAML fixtures, and the examples.
- Update `README.md` and `CHANGELOG.md` (under Unreleased) when behaviour or options change.
- Build and test `build/agent-harness.phar` with `composer phar && composer phar:check` after changing the CLI or packaging.

## Commits and scope

Use small, topical commits with Conventional Commits subjects: `type(scope): subject`. Use a lowercase imperative subject of at most 72 characters, with no trailing period. For example: `feat(runner): add retry support` or `fix(security): redact secrets in reports`. Run `composer commits` to lint the full history; it is also part of `composer check` and CI.

Do not add co-author trailers, generated-by lines, or other AI attribution.

The harness stays framework-free: Symfony Console, Yaml and HttpClient are the only runtime dependencies. Please open an issue to discuss larger changes such as new adapters before writing them.

## Known local issue

Homebrew PHP 8.4.20 on macOS can exit with code 11 and no output during Composer network calls. `composer deps:audit` retries only an empty-output exit 11 or 139 with the curl-multi functions disabled. Audit findings and every other failure are returned unchanged and fail the gate.
