# Contributing

Thanks for helping. Issues and pull requests are welcome.

## Setup

PHP 8.3 or later and Composer.

```bash
composer install
```

## Before opening a pull request

```bash
composer test     # Pest, must be green
composer stan     # PHPStan at level max, must report no errors
bin/agent-harness validate examples/scenarios
```

- Add or update tests for every behaviour change. Tests never call a real LLM: use `tests/Support/FakeLlmClient.php`.
- No PHPStan baseline and no ignore comments; fix the types instead.
- Keep the example run at 4 passed and 2 failed: start the demo agent with `composer demo` and run `bin/agent-harness run examples/scenarios --url http://127.0.0.1:8787/chat`.
- Update `README.md` and `CHANGELOG.md` (under Unreleased) when behaviour or options change.

## Scope

The harness stays framework-free: Symfony Console, Yaml and HttpClient are the only runtime dependencies. Please open an issue to discuss larger changes such as new adapters before writing them.
