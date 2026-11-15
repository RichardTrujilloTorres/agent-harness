# Flaky runs

You will be able to make runs repeatable where possible, and to tell a flaky scenario from a broken one.

A scenario is flaky when it passes on some runs and fails on others without a code change. With conversational agents, the usual causes are LLM sampling in the agent, an LLM-played user or the judge, network timeouts, and latency limits that are too tight.

## Seed

`--seed=<int>` is sent to the LLM with every request of the LLM-played user and the judge:

```bash
vendor/bin/agent-harness run scenarios --seed=42
```

Whether the same seed gives the same answers depends on the LLM server. The seed does not reach your agent. If your agent uses an LLM, make it deterministic in tests by its own means, for example a fixed temperature or a seed in its test configuration.

## Retries

`--retries=<n>` re-runs a scenario that failed or errored, up to `n` more times. A scenario that passes on a retry is reported FLAKY, and counts as a pass for the exit code. A scenario that fails on every attempt is reported as it failed on the last attempt.

This run used a local test agent that answers "wrong" to its first request and "right" afterwards:

```yaml
name: Says right
user:
  mode: scripted
  turns:
    - Hello
expect:
  - said: right
```

```bash
vendor/bin/agent-harness run scenarios --url http://127.0.0.1:8793/chat --retries=2 -v
```

```text
FLAKY  Says right  (1 turn, 1 ms)
       passed on attempt 2 of 3
       ✓ said "right": said in turn 1
       Transcript:
         1. user:  Hello
            agent: right (1 ms)

1 scenario(s): 0 passed, 1 flaky, 0 failed, 0 skipped, 0 errors in 5 ms
```

The exit code is 0. In the JSON output and the reports, the scenario has the status `flaky` and `attempts` shows how many runs it took. The transcript is the one of the passing attempt.

## Using retries well

- Keep the default of 0 for scripted scenarios against a deterministic agent: a failure there is a real failure.
- Use `--retries 1` for LLM-played users, judges and agents that call an LLM. Every retry repeats the scenario's LLM requests.
- Treat FLAKY as a signal, not as a pass to forget. Track it in the reports, and fix the cause: an ambiguous rubric, a tight latency limit, a substring that only appears in some phrasings.
- Prefer expectations that are stable across phrasings: `tool_called` with arguments rather than `said` with exact wording.
