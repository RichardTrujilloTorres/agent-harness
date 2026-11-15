# Troubleshooting

You will be able to match an error message to its cause and fix it.

Every message on this page was produced by the tool. Paths, ports and timings come from the run that produced them.

## Scenario files

Messages from `validate`, and from `run` and `list`, which validate first. `validate` exits with 1, `run` with 2.

| Message | Cause | Fix |
| --- | --- | --- |
| `unknown field "nmae"; did you mean "name"? allowed: ...` | A key that the format does not have, often a typo | Use one of the listed keys |
| `unknown expectation "toolcalled"; did you mean "tool_called"? allowed: ...` | A misspelled expectation type | Use one of the listed types |
| `must have exactly one key, found 2: said, handoff; put each expectation in its own list item` | Two expectations in one list item, usually a missing `-` | Start each expectation with its own `-` |
| `required when mode is scripted; add the user messages as a list of strings` | `user.turns` is missing in a scripted scenario | Add `turns`, or use `mode: llm` |
| `user.turns[0]: must be a non-empty string` | YAML read the turn as a map, because it contains a colon followed by a space, as in `- Note: this breaks` | Quote the turn: `- "Note: this breaks"` |
| `invalid YAML: Indentation problem at line 4 (near " turns: [Hi]").` | The file is not valid YAML | Fix the indentation at the line shown; use spaces, not tabs |
| `PHP tags such as !php/object and !php/const are not allowed (line 1). Write plain values instead.` | A PHP tag in the file | Write a plain value |
| `invalid YAML: Tags support is not enabled. ...` | A custom YAML tag such as `!foo` | Write a plain value |

When `run` finds an invalid file, it prints the validation result and stops before the first request:

```text
FAIL scenarios/broken.yaml
  - user.turns: required when mode is scripted; add the user messages as a list of strings
  - expect[0]: must have exactly one key, found 2: said, handoff; put each expectation in its own list item
OK  scenarios/example.yaml
OK  scenarios/hello.yaml

3 file(s), 2 valid, 1 invalid
```

## Command input

These stop `run` with exit code 2 before any request.

| Message | Fix |
| --- | --- |
| `No agent URL: pass --url or set agent.url in harness.yaml.` | Pass `--url`, or set `agent.url`. Check that you run from the directory that contains `harness.yaml`, or pass `--config` |
| `Path does not exist: scenaros` | Check the path. It is relative to the current directory |
| `No .yaml or .yml files found in empty. Scenario files need a .yaml or .yml extension.` | Rename the files, or point at the right directory |
| `No scenario name contains "booking". Run "agent-harness list scenarios" to see the names.` | `--filter` matches scenario names, not file names. Run `list` to see them |
| `--url must be an http:// or https:// URL with a host, got "127.0.0.1:8787/chat".` | Add the scheme: `http://127.0.0.1:8787/chat` |
| `--retries must be an integer >= 0, got "-1".` | Pass 0 or more |
| `--seed must be an integer, got "abc".` | Pass a whole number |
| `--format must be "text" or "json".` | Use one of the two |
| `Invalid config harness.yaml` followed by the errors | See [harness.yaml](reference/config.md#errors) |
| `Environment variable HARNESS_LLM_API_KEY is not set. Export your LLM API key in it, or name another variable in llm.api_key_env.` | Export the key, or remove the `llm` section to skip LLM scenarios |
| `Cannot create directory ro/sub` | A report path is not writable. The scenarios ran and their results were printed; fix the path and run again |

`init` exits with 1 and creates nothing when a file exists:

```text
Refusing to overwrite /private/tmp/oh/ts/harness.yaml. Move it aside or run init in an empty project directory.
```

## Agent errors

These make the scenario an ERROR, and the run exits with 3. The message replaces the expectation results, because the conversation could not be completed.

| Message | Cause | Fix |
| --- | --- | --- |
| `Agent at http://127.0.0.1:8799/chat is unreachable: Failed to connect to 127.0.0.1:8799 after 0 ms: ...` | Nothing listens at the URL | Start the agent, check the port, wait for it to be ready in CI |
| `Agent at http://127.0.0.1:8794/slow timed out after 500 ms` | The agent did not answer within `agent.timeout_ms` | Raise `agent.timeout_ms`, or find out why the agent is slow |
| `Agent at http://127.0.0.1:8794/500 answered HTTP 500: {"error":"internal"}` | A non-2xx status. The start of the body follows | Check the agent's logs |
| `Agent at http://127.0.0.1:8794/html answered invalid JSON (Syntax error): <html><body>Not an API</body></html>` | The URL serves a page, not the chat API | Check the URL path |
| `Agent response has no "reply" field (response.reply): {"text":"Hello"}` | The reply is under another name | Set `response.reply`; see [Connecting your agent](guides/connecting-agent.md) |
| `Agent response field "handoff" must be a boolean, got string` | A mapped field has the wrong type | Map the right field, or fix the agent's response |
| `Agent at http://127.0.0.1:8794/redirect answered a redirect (HTTP 302); redirects are not followed unless agent.follow_redirects is true` | The URL redirects, for example from `http` to `https` | Use the final URL, or set `agent.follow_redirects: true` |
| `Agent at http://127.0.0.1:8794/big answered a response body is larger than 1048576 bytes; raise agent.max_response_bytes if this is expected` | The body is larger than `agent.max_response_bytes` | Raise the limit if the size is expected |

## LLM errors

| Message | Cause | Fix |
| --- | --- | --- |
| `judge "...": judge did not answer {"pass": bool, "reason": string} after a retry; last answer: ...` | The model did not follow the required JSON format twice | Use a model that supports JSON output, or simplify the rubric |
| `LLM-played user: ...` followed by the LLM error | The LLM server failed while playing the user | The rest of the message names the cause: unreachable, HTTP status, invalid JSON |
| `~ judge: skipped (no llm configured in harness.yaml)` | No `llm` section | Expected without an LLM. Add the section to run judges |

## Warnings

```text
⚠ suspicious_reply: turn 1 looks like prompt injection (ignore previous instructions, verdict injection)
```

An agent reply contains text that looks like an attempt to steer the LLM user or the judge. The warning does not change the status. Read the transcript: it can point to a real prompt-injection weakness in your agent, or to a harmless reply that happens to match.

## Local Composer crash

On some macOS machines with Homebrew PHP 8.4, Composer exits with code 11 and no output during network calls, for example in `composer install`. It is a crash of PHP's curl-multi code, not a problem with this package. Run the command with the curl-multi functions disabled:

```bash
php -d disable_functions=curl_multi_exec,curl_multi_init,curl_share_init "$(command -v composer)" install
```

The repository's `composer deps:audit` script retries an empty-output exit 11 or 139 in the same way; see [Contributing](project/contributing.md).

## FAQ

**Can I test a voice agent?** Not yet. Text over HTTP only; see the [Roadmap](project/roadmap.md).

**Do scenarios run in parallel?** No. They run one after another, sorted by file path.

**Why did my scenario pass when the judge was skipped?** Skipped expectations never fail a scenario. If the judge is the only check that matters, add a deterministic expectation, or use the Pest plugin, where `toPassScenario()` fails a skipped scenario.

**Does the harness keep data between runs?** No. Each scenario gets a new session id, and nothing is stored unless you ask for a report.
