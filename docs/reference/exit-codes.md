# Exit codes

You will be able to gate a script or a CI job on the exit code of each command.

## `run`

| Code | Meaning |
| --- | --- |
| 0 | No failures. Skipped and flaky scenarios are allowed |
| 1 | At least one scenario failed |
| 2 | Bad input: bad path, bad config, bad option, missing URL, invalid scenarios, LLM key variable not set, or a report could not be written |
| 3 | At least one scenario errored: agent or LLM unreachable, bad response, or a judge without a usable answer |

Code 3 takes precedence over 1: when one scenario failed and another errored, the run exits with 3, because the errored scenario could not be judged. With `--format=json`, the exit code is the same and also appears as `exit_code` in the document.

## `validate`

| Code | Meaning |
| --- | --- |
| 0 | Every file is valid |
| 1 | At least one file is invalid, or the directory has no `.yaml` or `.yml` file |
| 2 | The path does not exist, or a bad option |

## `list`

| Code | Meaning |
| --- | --- |
| 0 | The scenarios were listed |
| 1 | At least one file is invalid, or the directory has no `.yaml` or `.yml` file |
| 2 | The path does not exist |

## `init`

| Code | Meaning |
| --- | --- |
| 0 | Both files were created |
| 1 | A file already exists, or a file could not be written |

## Gating a merge

Treat any non-zero code as a failed check. If you want to tell agent outages apart from wrong behaviour, compare the code: 1 means the agent answered and an expectation did not hold, 3 means at least one conversation could not be completed. [Using it in CI](../guides/ci.md) shows a complete job.
