# Security

You will be able to tell what agent-harness trusts, what it sends where, how it handles secrets, and what it does not protect against.

The full analysis, with each threat, its mitigation and the test that proves it, is in the [threat model](threat-model.md). To report a vulnerability privately, follow the [security policy](policy.md).

## What is trusted

| Source | Trust |
| --- | --- |
| Scenario files, `harness.yaml`, command options, environment | Trusted: written by the person who runs the tests. Still parsed defensively: no PHP or custom tags, limits on size, depth and node count |
| Responses of the agent under test | Untrusted: it may be buggy, compromised or hostile |
| LLM output, from the LLM-played user and the judge | Untrusted: it reads agent text and can be steered by it |

## What the tool sends where

| Destination | What | When |
| --- | --- | --- |
| The agent URL | The session id, each user message, the configured headers | Every turn of every scenario |
| `{llm.base_url}/chat/completions` | The model name, the persona, goal and conversation for the LLM-played user; the rubric and transcript for the judge; the seed; the API key in the `Authorization` header | Only for `user.mode: llm` scenarios and `judge` expectations, when an `llm` section exists |
| Local files | Reports and transcripts at the paths you pass | Only with `--report-html`, `--report-junit` or `--transcripts` |

Nothing else leaves your machine: no telemetry, no update checks. Transcripts contain whatever the agent said; if your agent can return personal data, treat reports as sensitive.

## Secrets

- The LLM API key is read from the environment variable named in `llm.api_key_env`, never from a file, and only when a scenario needs it.
- Header values from `agent.headers` and the API key are replaced with `[REDACTED]` in the terminal output, the JSON output, the HTML and JUnit reports and the transcripts, including when the agent echoes them back.
- `harness.yaml` does not expand environment variables. Do not commit real header values: generate the file in CI from a secret.

## HTTP and files

- TLS certificates and host names are verified. Only `http://` and `https://` URLs are accepted.
- Response bodies are limited (`max_response_bytes`, default 1,048,576 bytes), and every request has a timeout.
- Redirects from the agent are refused unless `agent.follow_redirects` is `true`. Redirects from the LLM server are never followed.
- Reports escape their content, load no remote resources and refuse to write through symbolic links. Terminal output is stripped of control sequences that come from the agent.

## Prompt injection

The agent under test can try to steer the LLM-played user or the judge. The harness keeps instructions and rubrics out of the untrusted data, wraps transcripts in markers with a random value per request, defuses the `[[DONE]]` token in agent replies, and reports suspicious replies as warnings. See [LLM judge](../guides/llm-judge.md#injection-hardening).

## What it does not protect against

- **A judge can still be fooled.** The hardening reduces the risk; it does not remove it. Use deterministic expectations for anything critical.
- **Side effects in your agent.** The harness sends real messages to the agent you point it at. If that agent books, sends email or deletes data, run the tests against a test deployment.
- **A hostile scenario or config file.** These are trusted input. Review them like code.
- **The demo agent on a network.** It is a local demo; bind it to `127.0.0.1` only, as `composer demo` does.
- **Vulnerabilities in your agent, your LLM provider or PHP itself.**
