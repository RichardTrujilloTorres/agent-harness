# Contributing

You will be able to set up the project, run every quality gate, and prepare a pull request that passes CI.

The full rules are in `CONTRIBUTING.md` at the root of the repository. This page summarises the workflow and the gates.

## Setup

You need PHP 8.3 or later with Xdebug or PCOV for the coverage gates, Composer, and Node.js 20 or later with npm for the commit and documentation checks.

```bash
git clone https://github.com/RichardTrujilloTorres/agent-harness
cd agent-harness
composer install
npm ci
npm --prefix docs ci
composer check
```

## Quality gates

There are two entry points. Both run their gates in order and stop at the first failure. Each gate is also a Composer script; `composer list` shows them with descriptions.

- `composer check` is the fast local gate. It runs the commit messages, code style, static analysis, dependencies, tests and schemas gates, and `composer docs:lint` (markdownlint and cspell). It runs no coverage, mutation testing or builds. Run it before every push.
- `composer check:ci` runs every gate in the table: `composer check` plus the heavy ones. It uses every CPU core for several minutes. CI runs the same gates as separate jobs on every push and pull request, and the release workflow runs `composer check:ci` in full.

| Gate | Script | Threshold | In `check` |
| --- | --- | --- | --- |
| Commit messages | `composer commits` | Every subject follows the [Commit conventions](commit-conventions.md) | Yes |
| Code style | `composer lint` | Pint, no changes | Yes |
| Static analysis | `composer stan` | PHPStan at level max with strict and deprecation rules | Yes |
| Refactoring rules | `composer rector` | Rector dry run, no changes | No |
| Taint analysis | `composer taint` | Psalm taint analysis, no findings | No |
| Dependencies | `composer deps` | `composer.json` valid and normalised, no unused or undeclared dependencies | Yes |
| Dependency audit | `composer deps:audit` | No security advisories and no abandoned packages | No |
| Tests | `composer test` | All pass | Yes |
| Architecture | `composer arch` | All architecture rules pass | Yes, inside `composer test` |
| Line coverage | `composer coverage` | At least 95 % | No |
| Type coverage | `composer types` | 100 % | No |
| Mutation testing | `composer mutate` | Covered-only mutation score at least 85 % | No |
| Schemas | `composer schema` | JSON Schemas agree with the validators, fixtures and examples | Yes |
| Demo | `composer demo:check` | The examples give 4 passed, 2 failed, exit code 1 | No |
| PHAR | `composer phar` and `composer phar:check` | The PHAR builds, validates the examples and runs the demo | No |
| Documentation | `composer docs:check` | See below | Lint, spelling and docs tests; no site build |

Measured on 2026-11-15 with the full set of gates on an Apple M1 Pro laptop: 373 tests with 1,258 assertions, 95.4 % line coverage, 100.0 % type coverage, and a covered-only mutation score of 94.32 %. These are the results of that run, not badges; run the gates to get current numbers.

## Documentation gates

`composer docs:lint` runs markdownlint and cspell only; it is part of `composer check`. `composer docs:check` runs:

- the VitePress build, which fails on dead links;
- markdownlint and cspell over `docs/`, with the configuration and the project dictionary committed in `docs/`;
- the Pest tests in `tests/Docs`, which check that every documented command and option exists, every scenario and config example is valid, the exit codes match the code, and the CLI reference lists exactly what `--help` shows.

Read the site locally with `npm --prefix docs run dev`.

## Before opening a pull request

- Run `composer check`. CI runs the heavy gates; they must pass too.
- Add or update tests for every behaviour change. Tests never call a real LLM.
- Do not add baselines, ignore comments, suppressions or skipped tests, and do not lower a threshold. Fix the code or the tests.
- Update the documentation, `README.md` and `CHANGELOG.md` when behaviour or options change.

## Known local issue

Homebrew PHP 8.4.20 on macOS can exit with code 11 and no output during Composer network calls. `composer deps:audit` retries only an empty-output exit 11 or 139 with the curl-multi functions disabled. Audit findings and every other failure are returned unchanged and fail the gate. For other Composer commands, see [Troubleshooting](../troubleshooting.md#local-composer-crash).
