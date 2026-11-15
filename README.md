# agent-harness

A test runner for conversational agents, written in PHP. You describe a conversation in a YAML scenario (who the user is, what they say or want, what must happen), the harness talks to your agent over HTTP and reports pass or fail, so agent behaviour can be checked in CI like any other code.

- Scripted users, or an **LLM-played user** that works towards a goal in character
- Deterministic checks (`tool_called`, `said`, `never_said`, `handoff`, `max_turns`, `latency_ms_under`) and an **LLM judge** for rubrics
- Works with any HTTP agent: request and response fields are remapped by dot-path, so the agent needs no changes
- **HTML, JUnit and JSON transcript reports**, `--retries` with FLAKY detection, `--seed`
- A **Pest plugin** to write conversation tests in your own test suite
- A demo agent with no dependencies and no API key, to try it in two minutes

Status: v0.1. Text conversations over HTTP only. See the [roadmap](#roadmap) for what is planned.

## Contents

- [Install](#install)
- [Quickstart (2 minutes)](#quickstart-2-minutes)
- [Scenarios](#scenarios)
- [harness.yaml](#harnessyaml)
- [LLM-played user and LLM judge](#llm-played-user-and-llm-judge)
- [The run command](#the-run-command)
- [Reports](#reports)
- [Pest plugin](#pest-plugin)
- [CI](#ci)
- [Demo agent](#demo-agent)
- [Development](#development)
- [Roadmap](#roadmap)

## Install

PHP 8.3 or later.

```bash
composer require --dev desemax/agent-harness
```

Or clone this repository and run `composer install`; the commands below then use `bin/agent-harness` instead of `vendor/bin/agent-harness`.

## Quickstart (2 minutes)

From a clone of this repository, start the demo agent in one terminal:

```bash
composer demo        # same as: php -S 127.0.0.1:8787 -t examples/demo-agent/public
```

Run the example scenarios against it in another:

```bash
bin/agent-harness run examples/scenarios --url http://127.0.0.1:8787/chat
```

```
FAIL   Allergen question is not answered with invented claims  (1 turn, 4 ms)
       ✗ never_said "gluten-free": said in turn 1: "Yes, all our dishes are gluten-free."
PASS   Happy path booking  (3 turns, 2 ms)
PASS   Request for a human  (1 turn, 1 ms)
FAIL   Large party goes to staff  (2 turns, 1 ms)
       ✗ handoff true: no handoff in 2 turn(s)
       ✗ never_said "confirmed": said in turn 2: "...turday at 20:00 under the name Marco is confirmed."
PASS   Missing details, agent asks for the name  (1 turn, 1 ms)
PASS   Opening hours question  (1 turn, 1 ms)

6 scenario(s): 4 passed, 2 failed, 0 skipped, 0 errors in 10 ms
```

The two failures are intended: the demo agent claims every dish is gluten-free, and it books a party of 12 instead of handing off to staff. The exit code is 1.

Add `-v` to see every expectation and the full transcript:

```
FAIL   Allergen question is not answered with invented claims  (1 turn, 3 ms)
       ✗ never_said "gluten-free": said in turn 1: "Yes, all our dishes are gluten-free."
       ~ judge: skipped (no llm configured in harness.yaml)
       Transcript:
         1. user:  Are your dishes safe for someone with coeliac disease?
            agent: Yes, all our dishes are gluten-free. (3 ms)
```

Add `--report-html=report.html` and open the file for a readable report of the same run.

## Scenarios

```yaml
name: Happy path booking
persona: A friendly customer who answers every question
goal: Book a table for 4 on Saturday at 20:00
user:
  mode: scripted          # scripted | llm
  turns:
    - "Hi, I'd like to book a table for 4"
    - "Saturday at 8pm"
    - "My name is Anna"
max_turns: 5
expect:
  - tool_called:
      name: create_booking
      args: { party_size: 4, day: saturday, time: "20:00", name: Anna }
  - said: confirmed
  - handoff: false
  - max_turns: 3
  - latency_ms_under: 2000
```

### Keys

| Key | Required | Meaning |
| --- | --- | --- |
| `name` | yes | Non-empty string, shown in output and reports |
| `persona` | no | Who the user is. Used by the LLM-played user |
| `goal` | no | What the user wants. Used by the LLM-played user |
| `user.mode` | yes | `scripted` (fixed turns) or `llm` (an LLM plays the user) |
| `user.turns` | scripted | Non-empty list of user messages, sent in order |
| `user.opening` | no | `llm` mode only: fixed first message; the LLM writes the rest |
| `max_turns` | no | Integer, at least 1, default 10. The conversation stops here |
| `expect` | yes | Non-empty list; each item has exactly one key (below) |

A conversation also stops early when the agent hands off, and in `llm` mode when the user writes `[[DONE]]`. Unknown keys and wrong types are reported with the file, the field path and the reason.

### Expectations

| Key | Value | Passes when |
| --- | --- | --- |
| `tool_called` | `{name, args?}` | Some tool call has that name and, if `args` is given, matches them: subset match (extra actual args are ignored), recursive for nested maps, lists element by element, `2` equals `"2"`, strings case-insensitive after trim |
| `said` | string | Some agent reply contains the text (case-insensitive) |
| `never_said` | string | No agent reply contains the text (case-insensitive) |
| `handoff` | bool | Whether any turn handed off equals the value |
| `max_turns` | int ≥ 1 | The conversation used at most n turns |
| `latency_ms_under` | int ≥ 1 | Every turn answered in less than n ms |
| `judge` | string | An LLM reads the transcript and decides the rubric is met. Skipped when no LLM is configured |

Scenario status:

- **FAIL** when any expectation failed
- **ERROR** when the agent or the LLM could not be used (unreachable, timeout, non-2xx, invalid JSON, missing reply field), or the judge gave no usable answer
- **SKIP** when nothing could be checked (only `judge` without an LLM, or `user.mode: llm` without an LLM)
- **PASS** otherwise; skipped expectations never fail a scenario
- **FLAKY** with `--retries`, when it failed or errored first and passed on a retry

Failure reasons say what happened:

```
✗ tool_called create_booking: called with party_size=10, expected 2
✗ never_said "gluten-free": said in turn 1: "Yes, all our dishes are gluten-free."
```

With an LLM configured, a judge expectation reports the judge's own reason in the same way: `judge "<rubric>": fail: <reason>`.

### Validate

```bash
bin/agent-harness validate examples/scenarios
```

```
OK  examples/scenarios/allergen-question.yaml
...
FAIL path/to/broken.yaml
  - expect[2].tool_called.name: required field is missing
```

Exit code 0 when all files are valid, 1 when any is invalid, 2 when the path does not exist. `run` validates first and stops with exit 2 if anything is invalid.

## harness.yaml

Optional: `run` reads `./harness.yaml` when it exists, or the file given with `--config`. Every key has a default; this shows them all.

```yaml
agent:
  url: http://127.0.0.1:8787/chat   # or pass --url
  timeout_ms: 10000                 # per request
  headers:                          # sent with every request
    Authorization: Bearer my-token
request:
  session_field: session_id         # dot-path in the request body
  message_field: message
response:
  reply: reply                      # dot-path to the reply text (required in every response)
  tool_calls: tool_calls            # dot-path to the list of tool calls (optional)
  tool_name: name                   # path inside each tool call
  tool_arguments: arguments         # path inside each tool call; an object or a JSON string
  handoff: handoff                  # dot-path to a boolean (optional, default false)
llm:                                # optional; enables the LLM-played user and the judge
  base_url: https://llm.example.com/v1   # required: POST {base_url}/chat/completions
  model: your-model-name                 # required
  api_key_env: HARNESS_LLM_API_KEY       # name of the env var holding the key (default shown)
  timeout_ms: 30000                      # per LLM request (default shown)
  max_tokens: 400                        # optional cap per LLM answer
```

Paths are dot-paths, so an agent that answers `{"data": {"output": {"text": "..."}, "actions": [...]}}` is configured with `reply: data.output.text` and `tool_calls: data.actions`. Numeric segments index lists (`choices.0.message.content`). Each scenario gets a new random session id.

The API key is read from the environment variable named in `llm.api_key_env` and never written to a file. If that variable is not set and a scenario needs the LLM, `run` stops with exit 2 and says which variable to set. Runs that need no LLM never ask for a key.

## LLM-played user and LLM judge

Both go through one interface (`LlmClient`) with one implementation for servers that speak the OpenAI-compatible chat completions API, hosted or on your own machine. Configure the `llm` section above, export the key, and run:

```bash
export HARNESS_LLM_API_KEY=...
bin/agent-harness run examples/scenarios-llm/scenarios --config examples/scenarios-llm/harness.example.yaml
```

`examples/scenarios-llm/` holds two scenarios and a commented `harness.example.yaml`. They are not part of the default demo run and are not run by the test suite.

**LLM-played user** (`user.mode: llm`): the LLM gets a system prompt built from `persona` and `goal`, and is told to write only the next user message, stay in character, and write `[[DONE]]` alone when the goal is reached or clearly impossible. The loop is: the LLM writes a user message, the harness sends it to the agent, the reply goes back to the LLM, until `[[DONE]]`, a handoff, or `max_turns`. With `user.opening` the first message is fixed and the LLM takes over from the second.

```yaml
name: LLM user books a table
persona: A regular customer who writes short messages and only gives details when asked. Their name is Sam.
goal: Book a table for 2 people on Friday at 20:00
user:
  mode: llm
  opening: Hi, can I book a table?
max_turns: 6
expect:
  - tool_called: { name: create_booking, args: { party_size: 2, day: friday } }
  - judge: The agent collects party size, day, time and name before confirming.
```

**Judge** (`judge: <rubric>`): the LLM receives the rubric and the transcript (user and agent turns, tool calls with arguments, handoff) and must answer JSON `{"pass": bool, "reason": string}` (JSON mode, temperature 0). Its reason is shown in the output. If the answer is not valid JSON of that shape it is asked once more; after a second invalid answer the expectation is an error showing an excerpt of what the judge wrote.

**Reproducibility**: `--seed=<int>` is passed to the LLM for both the user and the judge. Whether it makes answers repeatable depends on the server. The user plays at temperature 0.7, the judge at 0.

**Without an `llm` section**, `user.mode: llm` scenarios and `judge` expectations are skipped with the reason `no llm configured in harness.yaml`; everything else runs as usual.

## The run command

```bash
bin/agent-harness run <file-or-directory> [options]
```

| Option | Meaning |
| --- | --- |
| `--url=<url>` | Agent URL, overrides `agent.url` |
| `--config=<file>` | Config file (default: `./harness.yaml` if present) |
| `--filter=<text>` | Only scenarios whose name contains the text (case-insensitive) |
| `--seed=<int>` | Seed passed to the LLM |
| `--retries=<n>` | Re-run a failed or errored scenario up to n times (default 0). Passing on a retry is reported FLAKY and counts as a pass for the exit code |
| `--report-html=<file>` | Write the HTML report |
| `--report-junit=<file>` | Write the JUnit XML report |
| `--transcripts=<dir>` | Write one JSON transcript per scenario |
| `-v` | Print every expectation result and the full transcript |

### Exit codes

| Code | Meaning |
| --- | --- |
| 0 | No failures (skipped and flaky scenarios allowed) |
| 1 | At least one scenario failed |
| 2 | Bad path, bad config, missing URL, invalid scenarios, LLM key variable not set, or a report could not be written |
| 3 | At least one scenario errored. Takes precedence over 1, because errored scenarios could not be judged |

## Reports

- `--report-html=<file>`: one self-contained HTML file (inline CSS, no scripts, no external assets) with the summary counts and duration, and per scenario its status, every expectation with its reason, and the full transcript: user and agent turns, tool calls with arguments, handoff, latency. Light and dark follow the system setting. All content is escaped.
- `--report-junit=<file>`: JUnit XML with one testsuite and one testcase per scenario. FAIL is a `<failure>` listing the reasons, ERROR an `<error>`, SKIP `<skipped>`; the transcript is in `<system-out>`. Readable by the usual CI test report parsers.
- `--transcripts=<dir>`: one JSON file per scenario, named after the scenario (`Happy path booking` → `happy-path-booking.json`), with status, attempts, duration, turns (user, agent, tool calls, handoff, latency) and expectation results.

Missing parent directories are created. Reports are written after the run, whatever its result.

## Pest plugin

With Pest installed in your project, `conversation()` and four expectations are available in your tests (registered through Composer's autoloader, nothing to configure):

```php
it('books a table', function () {
    $result = conversation(__DIR__.'/scenarios/happy-booking.yaml')
        ->against('http://127.0.0.1:8787/chat')   // or an AgentAdapter instance
        ->run();

    expect($result)->toPassScenario();
});
```

- `conversation(string|Scenario $scenario)`: one scenario file, or a `Scenario` object built in code
- `->against(string|AgentAdapter $agent)`: the agent URL, or any `AgentAdapter` (for example an in-process fake)
- `->withConfig(string $path)`: a `harness.yaml` for field mapping, headers, timeout and the `llm` section
- `->withLlm(LlmClient $llm)`, `->withSeed(int $seed)`: optional, for the LLM-played user and judge
- `->run()`: plays the scenario and returns a `ScenarioResult`

Expectations on the result:

| Expectation | Passes when |
| --- | --- |
| `toPassScenario()` | The scenario passed. On failure the message lists the failed expectations with reasons and the transcript. A skipped scenario fails, so a missing LLM is not mistaken for a pass |
| `toHaveCalledTool(name, ?args)` | Same rules as `tool_called` |
| `toHaveSaid(text)` | Same rules as `said` |
| `toHaveHandedOff()` | Some turn handed off |

They chain and work with `not`:

```php
expect($result)
    ->toHaveCalledTool('create_booking', ['party_size' => 4])
    ->toHaveSaid('confirmed')
    ->not->toHaveHandedOff();
```

A failing `toPassScenario()` reads like this:

```
Scenario "Large party goes to staff" did not pass: FAIL
Failed expectations:
  ✗ handoff true: no handoff in 2 turn(s)
  ✗ never_said "confirmed": said in turn 2: "...turday at 20:00 under the name Marco is confirmed."
Transcript:
  1. user:  I'd like a table for 12 on Saturday at 20:00
     agent: What name should I put the booking under? (5 ms)
  2. user:  Name is Marco
     agent: Your table for 12 on Saturday at 20:00 under the name Marco is confirmed. (1 ms)
     tool:  create_booking {"party_size":12,"day":"saturday","time":"20:00","name":"Marco"}
```

Without Pest installed, nothing is registered and the CLI works as usual.

## CI

A GitHub Actions job that starts your agent, runs the scenarios and keeps the reports:

```yaml
- name: Start the agent
  run: php -S 127.0.0.1:8787 -t public > /dev/null 2>&1 &

- name: Conversation tests
  run: |
    vendor/bin/agent-harness run tests/scenarios \
      --url http://127.0.0.1:8787/chat \
      --report-junit build/agent-harness.xml \
      --report-html build/agent-harness.html \
      --retries 1

- name: Keep the reports
  if: always()
  uses: actions/upload-artifact@v4
  with:
    name: agent-harness-reports
    path: build/
```

For LLM scenarios, store the key as a repository secret and expose it as the variable named in `llm.api_key_env`:

```yaml
  env:
    HARNESS_LLM_API_KEY: ${{ secrets.HARNESS_LLM_API_KEY }}
```

## Demo agent

`examples/demo-agent/public/index.php` is a small rule-based restaurant booking bot in plain PHP: no dependencies, no API key, deterministic. It has two deliberate bugs so that two example scenarios fail.

It speaks the default HTTP contract:

- Request: `POST /chat` with `{"session_id": "...", "message": "..."}`
- Response: `{"reply": "...", "tool_calls": [{"name": "...", "arguments": {...}}], "handoff": false}`

```bash
curl -s -X POST http://127.0.0.1:8787/chat \
  -H 'Content-Type: application/json' \
  -d '{"session_id": "s1", "message": "I would like a table for 2 on Friday at 19:30"}'
```

## Development

```bash
composer test     # Pest
composer stan     # PHPStan, level max
composer demo     # start the demo agent
```

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Roadmap

- Voice mode: text-to-speech for the user, speech-to-text for the agent, barge-in
- More adapters beyond HTTP JSON
- Parallel runs

## License

MIT
