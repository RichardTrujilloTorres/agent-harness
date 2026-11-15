## What and why

<!-- What does this change, and why? Link the issue if there is one. -->

## Checklist

- [ ] `composer check` passes locally (style, PHPStan, Rector, Psalm taint, dependencies, audit, coverage >= 95 %, type coverage 100 %, mutation score >= 85 %, demo run)
- [ ] Tests added or updated for every behaviour change; no test calls a real LLM
- [ ] No ignore comments, baselines, suppressions, skipped tests or lowered thresholds
- [ ] `README.md` and `CHANGELOG.md` (under Unreleased) updated if behaviour or options changed
- [ ] JSON schemas in `schema/` updated if the scenario or config format changed
- [ ] Commit subjects follow `type(scope): subject`, for example `feat(runner): add retry support` or `fix(security): redact secrets in reports`; `composer commits` passes and messages have no generated-by or co-author lines
