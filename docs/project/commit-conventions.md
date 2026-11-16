# Commit conventions

You will be able to write a commit message that passes this project's commit check, and run the check yourself.

## The rule

Every commit subject follows [Conventional Commits 1.0.0](https://www.conventionalcommits.org/en/v1.0.0/):

```text
type(scope): subject
```

- `type` is one of the types below.
- `scope` is optional, lowercase, in parentheses.
- `subject` is in the imperative mood, starts with a lowercase letter, has no trailing period, and the whole first line is at most 72 characters.
- A body is optional, after a blank line.
- No co-author trailers, generated-by lines or other attribution of automated tools.

Examples from the history of this repository:

```text
feat(scenario): add schema, loader, validator and validate command
fix(deps): rename audit script and make the audit gate robust
docs(reference): document the cli, files, json output and exit codes
```

## Types

| Type | Use for |
| --- | --- |
| `feat` | A new capability for users |
| `fix` | A bug fix |
| `docs` | Documentation only |
| `test` | Tests only |
| `refactor` | A code change that neither fixes a bug nor adds a feature |
| `style` | Formatting, no change in behaviour |
| `build` | Build system, dependencies, packaging, local tooling |
| `ci` | GitHub Actions workflows |
| `chore` | Maintenance that fits no other type |
| `perf` | A performance improvement |

## Scopes in use

Scopes name the part of the project that changed: `agent`, `assertion`, `cli`, `demo`, `deps`, `docs`, `guides`, `llm`, `pest`, `reference`, `release`, `report`, `runner`, `scenario`, `schema`, `security`. Use one of these when it fits, or leave the scope out.

## How the check runs

The rule is enforced by [commitlint](https://commitlint.js.org/) with the conventional preset and the stricter settings in `commitlint.config.mjs` at the repository root: the type list above, lowercase scopes, no sentence-case or upper-case subjects, no trailing period, and a 72-character limit.

```bash
npm ci
composer commits
```

`npm ci` installs commitlint from the lockfile at the repository root. `composer commits` then lints every commit from the first one to `HEAD`. It is part of `composer check` and `composer check:ci`, and the `commits` job in `.github/workflows/ci.yml` runs it on every push and pull request.
