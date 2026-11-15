# Scenario file

You will be able to look up every key of a scenario file, its type, default and constraints.

A scenario is one YAML file with the extension `.yaml` or `.yml`. `validate` and `run` check it against the rules on this page and report every error with the file, the field path and the reason. The JSON Schema `schema/scenario.schema.json` describes the same rules for editors.

```yaml
name: Happy path booking
persona: A friendly customer who answers every question
goal: Book a table for 4 on Saturday at 20:00
user:
  mode: scripted
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

## Top-level keys

| Key | Type | Required | Default | Constraints and meaning |
| --- | --- | --- | --- | --- |
| `name` | string | yes | | Not empty. Shown in output and reports. `--filter` matches it |
| `persona` | string | no | | Who the user is. Used by the LLM-played user |
| `goal` | string | no | | What the user wants. Used by the LLM-played user |
| `user` | map | yes | | See [user](#user) |
| `max_turns` | integer | no | `10` | At least 1. The conversation stops after this many turns |
| `expect` | list | yes | | At least one item. See [expect](#expect) |

Any other key is an error. A close misspelling gets a suggestion:

```text
FAIL ./typo.yaml
  - nmae: unknown field "nmae"; did you mean "name"? allowed: name, persona, goal, user, max_turns, expect
  - name: required field is missing
```

## `user`

| Key | Type | Required | Constraints and meaning |
| --- | --- | --- | --- |
| `mode` | string | yes | `scripted` or `llm` |
| `turns` | list of strings | when `mode` is `scripted` | At least one item, no empty strings. The user messages, sent in order. Ignored when `mode` is `llm` |
| `opening` | string | no | Only with `mode: llm`. A fixed first message; the LLM writes the following ones |

A turn is one user message and the agent's reply to it. A conversation ends at the first of:

- all scripted turns are sent;
- `max_turns` turns are played;
- the agent hands off;
- in `llm` mode, the LLM-played user writes `[[DONE]]`.

## `expect`

Each item is a map with exactly one key, the expectation type.

| Type | Value | Passes when |
| --- | --- | --- |
| `tool_called` | map with `name` (required, string) and `args` (optional, map) | Some tool call in the conversation has that name and, if `args` is given, matching arguments |
| `said` | non-empty string | Some agent reply contains the text, case-insensitive |
| `never_said` | non-empty string | No agent reply contains the text, case-insensitive |
| `handoff` | boolean | Whether any turn handed off equals the value |
| `max_turns` | integer, at least 1 | The conversation used at most this many turns |
| `latency_ms_under` | integer, at least 1 | Every turn was answered in less than this many milliseconds |
| `judge` | non-empty string | An LLM reads the transcript and decides that the rubric is met. Skipped when no LLM is configured |

`args` matching:

- subset match: arguments the agent sent but `args` does not name are ignored;
- nested maps match recursively, lists element by element;
- numbers and numeric strings compare by value, so `2` equals `"2"`;
- strings compare case-insensitive after trimming, so `Saturday` equals `saturday`;
- booleans and `null` compare strictly.

The [Assertions guide](../guides/assertions.md) shows a passing and a failing example of every type.

## Scenario status

| Status | When |
| --- | --- |
| PASS | No expectation failed or errored, and at least one passed |
| FAIL | At least one expectation failed |
| ERROR | The agent or the LLM could not be used, or the judge gave no usable answer, and no expectation failed |
| SKIP | Nothing could be checked: only `judge` expectations without an LLM, or `user.mode: llm` without an LLM |
| FLAKY | With `--retries`: it failed or errored first and passed on a retry |

## File limits

Scenario and config files are read as plain data. A file is rejected when it:

- is larger than 1,048,576 bytes;
- nests deeper than 32 levels or has more than 100,000 nodes;
- uses PHP tags such as `!php/object` or custom tags such as `!foo`.
