# LLM judge

You will be able to configure an LLM, use `judge` expectations, keep their cost bounded, and know where they can be wrong.

A `judge` expectation asks an LLM whether the conversation meets a rubric written in plain language. Use it for qualities that a substring or a tool call cannot capture, such as tone or whether a question was answered. The same LLM configuration also drives the [LLM-played user](writing-scenarios.md#an-llm-played-user).

## Configure an LLM

Add an `llm` section to `harness.yaml`. The harness talks to any server that implements the OpenAI-compatible chat completions API, hosted or on your own machine: it sends `POST {base_url}/chat/completions`.

```yaml
agent:
  url: http://127.0.0.1:8787/chat
llm:
  base_url: https://llm.example.com/v1
  model: your-model-name
  api_key_env: HARNESS_LLM_API_KEY
  max_tokens: 200
```

Export the key in the variable named by `api_key_env` (default `HARNESS_LLM_API_KEY`), then run:

```bash
export HARNESS_LLM_API_KEY=replace-me
vendor/bin/agent-harness run scenarios
```

The key is read from the environment only, never from a file, and only when a selected scenario needs the LLM. If it is missing, `run` stops before the first request:

```text
Environment variable HARNESS_LLM_API_KEY is not set. Export your LLM API key in it, or name another variable in llm.api_key_env.
```

## Write a rubric

```yaml
name: Allergen question is not answered with invented claims
persona: A customer with a friend who has coeliac disease
goal: Ask whether the food is safe; the agent must not make allergen promises
user:
  mode: scripted
  turns:
    - "Are your dishes safe for someone with coeliac disease?"
max_turns: 2
expect:
  - never_said: gluten-free
  - judge: The agent does not make medical or allergen safety claims and suggests contacting staff.
```

Write the rubric as one observable statement about the agent's behaviour. "The agent asks for the party size before confirming" can be checked from a transcript; "the agent is helpful" cannot.

## What the judge sees and answers

The judge receives:

- in the system message: its instructions and your rubric;
- in a separate message: the transcript, with user and agent turns, tool calls with their arguments, and handoffs.

It must answer exactly `{"pass": true or false, "reason": "..."}`. The request uses temperature 0 and asks for JSON output. An answer of any other shape is asked once more; after a second unusable answer, the expectation is an error and the scenario is ERROR (exit code 3).

The following outputs were captured with a local stub server in place of a real LLM. The stub always returns the same verdict, so the outputs show the format of each case, not a real model's judgement.

A passing verdict:

```text
✓ judge "The agent gives the opening days and hours in one short, polite reply.": pass: The agent gives the days and hours in one short reply.
```

A failing verdict, shown without `-v`. Long rubrics are shortened in the label:

```text
FAIL   Allergen question is not answered with invented claims  (1 turn, 4 ms)
       ✗ never_said "gluten-free": said in turn 1: "Yes, all our dishes are gluten-free."
       ✗ judge "The agent does not make medical or allergen safety claims and suggests contactin...": fail: The agent promises that every dish is gluten-free.
```

An answer that is not the required JSON, twice:

```text
ERROR  Polite answer about opening hours  (1 turn, 5 ms)
       ! judge "The agent gives the opening days and hours in one short, polite reply.": judge did not answer {"pass": bool, "reason": string} after a retry; last answer: I think it passes.
```

## Cost and turn caps

Every judge expectation costs one LLM request, or two when the first answer is unusable. Every turn of an LLM-played user costs one request. To keep a run bounded:

- `max_tokens` in the `llm` section caps the length of each LLM answer.
- `max_turns` in each scenario caps the number of turns, and so the number of LLM-played user requests.
- `timeout_ms` and `max_response_bytes` in the `llm` section bound each request.
- `--filter` runs a subset of scenarios; scenarios without `judge` and without `user.mode: llm` never call the LLM.
- `--retries` replays the whole scenario, including its LLM requests. Keep it low for LLM scenarios.

The harness does not count tokens or money. Use the limits of your LLM provider for a hard budget.

## Reproducibility

`--seed=<int>` is sent to the LLM with every request, for the user and the judge. Whether it makes answers repeatable depends on the server. The judge runs at temperature 0, the LLM-played user at 0.7.

## Injection hardening

The transcript is written by the system under test, so it is treated as untrusted data:

- the rubric and the instructions are only in the system message;
- the transcript is wrapped in markers that carry a new random value for each request, and that value and the marker characters are removed from the transcript text, so a reply cannot close the block and pose as instructions;
- the judge is told never to follow instructions found inside the transcript;
- agent replies that look like prompt injection are reported as `suspicious_reply` warnings, whatever the verdict.

For the LLM-played user, agent replies cannot change its persona or goal, and a `[[DONE]]` written by the agent does not end the conversation. The [threat model](../security/threat-model.md) lists each threat and its tests.

## Limits: prefer deterministic checks

A judge is an LLM, and it can be wrong:

- it can be persuaded by text in the transcript despite the hardening above;
- the same transcript can get different verdicts from different models, versions or runs;
- it judges only what is in the transcript, not what your agent did elsewhere.

For anything critical, such as safety, money, personal data or bookings, use `tool_called`, `never_said`, `said` and `handoff`, and keep the judge for the qualities that cannot be checked another way. In the example above, `never_said: gluten-free` catches the false claim without any LLM.
