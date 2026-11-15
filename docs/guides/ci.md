# Using it in CI

You will be able to run your scenarios in GitHub Actions, keep the reports, and block a merge when a conversation breaks.

## A GitHub Actions job

This job starts the agent, runs the scenarios, and uploads the JUnit and HTML reports even when the run fails. Replace the start command and paths with your own. The action versions are pinned to full commit SHAs, as in this repository's own workflows.

```yaml
name: Conversation tests

on:
  pull_request:
  push:
    branches: [main]

permissions:
  contents: read

jobs:
  conversations:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
        with:
          persist-credentials: false
      - uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: '8.3'
          coverage: none
      - run: composer install --no-interaction --no-progress

      - name: Start the agent
        run: php -S 127.0.0.1:8787 -t public > /dev/null 2>&1 &

      - name: Conversation tests
        run: |
          vendor/bin/agent-harness run tests/scenarios \
            --url http://127.0.0.1:8787/chat \
            --report-junit build/agent-harness.xml \
            --report-html build/agent-harness.html

      - name: Keep the reports
        if: always()
        uses: actions/upload-artifact@cf430e030ddbb5b0abf93d22962f4752f3646cd9 # v7.0.2
        with:
          name: agent-harness-reports
          path: build/
```

If your agent needs a moment to start, wait for its port before the run, for example with a short loop around `curl`.

## Gating a merge

The step fails when `run` exits with a non-zero code, and so does the job. Make the job a required status check in the branch protection settings of your repository, and a pull request that breaks a conversation cannot be merged.

| Code | Meaning in CI |
| --- | --- |
| 0 | All scenarios passed, or were skipped or flaky |
| 1 | The agent answered, and an expectation did not hold |
| 2 | The job is misconfigured: path, config, option, scenario file, LLM key or report path |
| 3 | At least one conversation could not be completed: agent or LLM down, bad response, judge without a usable answer |

To check the scenario files without starting the agent, for example in a fast lint job, run `validate`:

```bash
vendor/bin/agent-harness validate tests/scenarios
```

## LLM scenarios in CI

Store the key as a repository secret and expose it as the variable named in `llm.api_key_env`:

```yaml
- name: Conversation tests
  env:
    HARNESS_LLM_API_KEY: ${{ secrets.HARNESS_LLM_API_KEY }}
  run: vendor/bin/agent-harness run tests/scenarios --url http://127.0.0.1:8787/chat
```

The key is redacted from the output and the reports. Without the secret, for example in pull requests from forks, the run stops with exit code 2 when a scenario needs the LLM. Keep LLM scenarios in a separate directory and job if you want the deterministic ones to run everywhere.

## Reading results in CI

- Upload the JUnit file to a test report view to see each scenario as a test case.
- Open the HTML report from the artifact for full transcripts.
- Use `--format=json` when a later step needs to read the results; see [JSON output](../reference/json-output.md).
- Add `--retries 1` only for LLM-played or network-dependent scenarios; see [Flaky runs](flaky-runs.md).
