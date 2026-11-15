# agent-harness

A test harness for conversational agents, written in PHP. You describe a conversation in a YAML scenario (who the user is, what they say, what must happen), the harness talks to your agent over HTTP and reports pass or fail, so agent behaviour can be checked in CI like any other code.

**Status: early, v0.1 in progress.** Scripted scenarios run against any HTTP agent with deterministic checks. The LLM-played user and the LLM judge are not built yet.

## What works today

- **Scenario schema**: YAML format for scripted conversations and their expectations.
- **`validate` command**: checks one file or a directory of scenarios and reports every error with file, field path and reason. Exit code 0 when all are valid, 1 when any is invalid, 2 when the path does not exist.
- **`run` command**: plays each scripted scenario against your agent over HTTP (new session id per scenario, turns in order, stops early on handoff or at `max_turns`) and prints PASS, FAIL, SKIP or ERROR with the reason for every failed expectation.
- **HTTP adapter**: POSTs JSON to your agent. Request and response field names are remappable by dot-path in `harness.yaml`, so an existing agent needs no changes.
- **Deterministic assertions** for `tool_called`, `said`, `never_said`, `handoff`, `max_turns` and `latency_ms_under`. `judge` expectations are reported as skipped and never fail a scenario; scenarios with `user.mode: llm` are skipped.
- **Demo agent**: a small rule-based restaurant booking bot ("Trattoria Demo") in plain PHP with no dependencies and no API key, plus six example scenarios. Two of them fail on purpose (the bot has two deliberate bugs) so you can see what a failing run looks like.

## Requirements

PHP 8.3 or later, Composer.

```bash
composer install
```

## Quickstart (2 minutes)

Start the demo agent in one terminal:

```bash
php -S 127.0.0.1:8787 -t examples/demo-agent/public
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

Options:

- `--url=<url>` agent URL, overrides `agent.url`
- `--config=<file>` config file (default: `./harness.yaml` if present)
- `--filter=<text>` only scenarios whose name contains the text (case-insensitive), e.g. `--filter happy`
- `-v` also prints every expectation result and the full transcript

Scenarios are validated first; if any is invalid, `run` prints the same report as `validate` and exits 2.

### Exit codes

| Code | Meaning |
| --- | --- |
| 0 | No failures (skipped scenarios are allowed) |
| 1 | At least one scenario failed |
| 2 | Bad path, bad config, missing URL or invalid scenarios |
| 3 | At least one scenario errored: agent unreachable, timeout, non-2xx, invalid JSON or missing reply field. Takes precedence over 1, because errored scenarios could not be judged |

## Scenario example

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
max_turns: 5              # optional, default 10
expect:
  - tool_called:
      name: create_booking
      args: { party_size: 4, day: saturday, time: "20:00", name: Anna }
  - said: confirmed
  - handoff: false
  - max_turns: 3
  - latency_ms_under: 2000
```

Fields: `name` (required), `persona`, `goal`, `user.mode` (required), `user.turns` (required and non-empty when `mode` is `scripted`), `max_turns` (integer, at least 1), `expect` (required, non-empty).

Each `expect` item has exactly one key:

| Key | Value |
| --- | --- |
| `tool_called` | `{name: string, args: map (optional)}` |
| `said` | string |
| `never_said` | string |
| `handoff` | bool |
| `max_turns` | int, at least 1 |
| `latency_ms_under` | int, at least 1 |
| `judge` | string (a criterion for an LLM judge) |

Unknown fields are reported as errors.

How each expectation is checked:

| Key | Passes when |
| --- | --- |
| `tool_called` | some tool call has that name and, if `args` is given, every given arg equals the actual one. Subset match (extra actual args are ignored), recursive for nested maps, lists compared element by element, `2` equals `"2"`, strings compared case-insensitively after trim |
| `said` | some agent reply contains the text (case-insensitive) |
| `never_said` | no agent reply contains the text (case-insensitive) |
| `handoff` | whether any turn handed off equals the value |
| `max_turns` | the conversation used at most n turns |
| `latency_ms_under` | every turn answered in less than n ms |
| `judge` | not checked yet: reported as skipped, never fails a scenario |

A scenario passes when no expectation failed and at least one passed; when nothing could be checked (only `judge`), it is skipped.

Failure reasons say what happened, for example:

```
✗ tool_called create_booking: called with party_size=10, expected 2
✗ never_said "gluten-free": said in turn 1: "Yes, all our dishes are gluten-free."
```

## harness.yaml

Optional. Every key has a default; the file below shows them all.

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
```

Paths are dot-paths, so an agent that answers `{"data": {"output": {"text": "..."}, "actions": [...]}}` is configured with `reply: data.output.text` and `tool_calls: data.actions`. Numeric segments index lists (`choices.0.message.content`). Unknown keys and malformed values are reported with their field path, like scenario errors.

## Validate scenarios

```bash
bin/agent-harness validate examples/scenarios
```

```
OK  examples/scenarios/allergen-question.yaml
...
FAIL path/to/broken.yaml
  - expect[2].tool_called.name: required field is missing
```

## Talk to the demo agent by hand

```bash
curl -s -X POST http://127.0.0.1:8787/chat \
  -H 'Content-Type: application/json' \
  -d '{"session_id": "s1", "message": "I would like a table for 2 on Friday at 19:30"}'
```

The agent speaks the default HTTP contract:

- Request: `POST /chat` with `{"session_id": "...", "message": "..."}`
- Response: `{"reply": "...", "tool_calls": [{"name": "...", "arguments": {...}}], "handoff": false}`

## Development

```bash
vendor/bin/pest
vendor/bin/phpstan analyse
```

## Planned

- LLM-played user (`user.mode: llm`)
- LLM judge for `judge` expectations
- HTML and JUnit reports
- Pest plugin
- Voice mode

## License

MIT
