# agent-harness

A test harness for conversational agents, written in PHP. You describe a conversation in a YAML scenario (who the user is, what they say, what must happen), the harness talks to your agent over HTTP and reports pass or fail, so agent behaviour can be checked in CI like any other code.

**Status: early, v0.1 in progress.** The runner does not exist yet: today you can write and validate scenarios and try them against a bundled demo agent by hand.

## What works today

- **Scenario schema**: YAML format for scripted conversations and their expectations.
- **`validate` command**: checks one file or a directory of scenarios and reports every error with file, field path and reason. Exit code 0 when all are valid, 1 when any is invalid, 2 when the path does not exist.
- **Demo agent**: a small rule-based restaurant booking bot ("Trattoria Demo") in plain PHP with no dependencies and no API key, plus six example scenarios. Two of them fail on purpose (the bot has two deliberate bugs) so you can see what a failure looks like once the runner exists.

## Requirements

PHP 8.3 or later, Composer.

```bash
composer install
```

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

## Run the demo agent

```bash
php -S 127.0.0.1:8787 -t examples/demo-agent/public
```

```bash
curl -s -X POST http://127.0.0.1:8787/chat \
  -H 'Content-Type: application/json' \
  -d '{"session_id": "s1", "message": "I would like a table for 2 on Friday at 19:30"}'
```

The agent speaks the default HTTP contract the harness will use:

- Request: `POST /chat` with `{"session_id": "...", "message": "..."}`
- Response: `{"reply": "...", "tool_calls": [{"name": "...", "arguments": {...}}], "handoff": false}`

## Development

```bash
vendor/bin/pest
vendor/bin/phpstan analyse
```

## Planned

- Runner and HTTP adapter (play a scenario against an agent)
- Deterministic assertions for every `expect` key
- LLM-played user (`user.mode: llm`)
- LLM judge for `judge` expectations
- HTML and JUnit reports
- Pest plugin
- Voice mode

## License

MIT
