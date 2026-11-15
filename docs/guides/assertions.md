# Assertions

You will be able to choose the right expectation for each check and read its failure message.

Expectations go in the `expect` list of a scenario. Each item has exactly one key, the expectation type. They are evaluated after the conversation ends, against the whole transcript, and every one is reported, so one run shows all the problems.

The examples on this page ran against the demo agent from the [Quickstart](../quickstart.md), with `-v` so that passing expectations are printed too. Transcripts are left out where they add nothing.

## `tool_called`

Passes when some tool call in the conversation has the name and, if you give `args`, matching arguments.

```yaml
name: Booking for two
user:
  mode: scripted
  turns:
    - Book a table for 2 on Friday at 19:30
    - My name is Sam
expect:
  - tool_called:
      name: create_booking
      args: { party_size: 2, day: Friday, time: "19:30" }
```

```text
PASS   Booking for two  (2 turns, 3 ms)
       ✓ tool_called create_booking: called in turn 2
```

Matching rules for `args`:

- Subset match: arguments the agent sent that `args` does not name are ignored. Above, `name` is not checked.
- Nested maps match recursively; lists must have the same length and match element by element.
- Numbers and numeric strings compare by value: `2` equals `"2"`.
- Strings compare case-insensitive after trimming: `Friday` equals `friday`.
- Booleans and `null` compare strictly.

When the tool was called with other arguments, the message names the first mismatch:

```yaml
name: Booking for two, wrong party size expected
user:
  mode: scripted
  turns:
    - Book a table for 2 on Friday at 19:30
    - My name is Sam
expect:
  - tool_called:
      name: create_booking
      args: { party_size: 3 }
```

```text
FAIL   Booking for two, wrong party size expected  (2 turns, 4 ms)
       ✗ tool_called create_booking: called with party_size=2, expected 3
```

When the tool was never called, the message lists the tools that were:

```text
FAIL   Cancels a booking  (2 turns, 4 ms)
       ✗ tool_called cancel_booking: never called; tools called: create_booking
```

## `said`

Passes when some agent reply contains the text, case-insensitive.

## `never_said`

Passes when no agent reply contains the text, case-insensitive.

```yaml
name: Asks for the name
user:
  mode: scripted
  turns:
    - Book a table for 2 on Friday at 19:30
expect:
  - said: what name
  - said: confirmed
```

```text
FAIL   Asks for the name  (1 turn, 3 ms)
       ✓ said "what name": said in turn 1
       ✗ said "confirmed": not found in 1 reply; last reply: "What name should I put the booking under?"
```

```yaml
name: No allergen promises
user:
  mode: scripted
  turns:
    - Is the food safe for coeliacs?
expect:
  - never_said: guaranteed
  - never_said: gluten-free
```

```text
FAIL   No allergen promises  (1 turn, 3 ms)
       ✓ never_said "guaranteed": not said in 1 turn(s)
       ✗ never_said "gluten-free": said in turn 1: "Yes, all our dishes are gluten-free."
```

Both match substrings, so `said: confirm` also matches "confirmed". Pick text that only appears when the behaviour happens.

## `handoff`

Passes when "some turn handed off" equals the value: `true` requires a handoff, `false` forbids one.

```yaml
name: Complaint goes to a human
user:
  mode: scripted
  turns:
    - I want to speak to a human
expect:
  - handoff: true
  - handoff: false
```

```text
FAIL   Complaint goes to a human  (1 turn, 8 ms)
       ✓ handoff true: handed off in turn 1
       ✗ handoff false: handed off in turn 1
```

The second expectation contradicts the first on purpose, to show both messages. When `true` fails, the message is `handoff true: no handoff in 2 turn(s)`.

## `max_turns`

Passes when the conversation used at most this many turns. Use it to check that the agent reaches the goal efficiently. It is different from the top-level `max_turns` key, which stops the conversation.

## `latency_ms_under`

Passes when every turn was answered in less than this many milliseconds. Latency is measured per request, from sending to receiving the full response.

```yaml
name: Booking in few turns
user:
  mode: scripted
  turns:
    - Hi, I'd like to book a table for 4
    - Saturday at 8pm
    - My name is Anna
expect:
  - max_turns: 3
  - max_turns: 2
  - latency_ms_under: 2000
  - latency_ms_under: 1
```

```text
FAIL   Booking in few turns  (3 turns, 4 ms)
       ✓ max_turns 3: used 3 turn(s)
       ✗ max_turns 2: used 3 turn(s)
       ✓ latency_ms_under 2000: slowest turn 2 ms
       ✗ latency_ms_under 1: turn 1 took 2 ms, turn 2 took 1 ms, turn 3 took 1 ms
```

Set latency limits with headroom: CI machines and network calls vary. A limit that fails one run in twenty makes the suite noisy.

## `judge`

Passes when an LLM reads the transcript and decides that the rubric is met. Without an `llm` section in `harness.yaml`, it is skipped:

```yaml
name: Polite answer about opening hours
user:
  mode: scripted
  turns:
    - When are you open?
expect:
  - said: Tuesday
  - judge: The agent gives the opening days and hours in one short, polite reply.
```

```text
PASS   Polite answer about opening hours  (1 turn, 3 ms)
       ✓ said "Tuesday": said in turn 1
       ~ judge: skipped (no llm configured in harness.yaml)
```

A skipped expectation never fails a scenario. A scenario whose only expectations are skipped is reported SKIP. Setup, failure messages and limits are on the [LLM judge](llm-judge.md) page.

## How results combine

| Scenario status | When |
| --- | --- |
| FAIL | At least one expectation failed |
| ERROR | The agent or the LLM could not be used, or the judge gave no usable answer, and no expectation failed |
| SKIP | No expectation could be checked |
| PASS | Otherwise |

In the terminal, `✗` marks a failed expectation and `!` an expectation that errored. With `-v`, `✓` marks a passed one and `~` a skipped one.
