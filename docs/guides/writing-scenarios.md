# Writing scenarios

You will be able to write scripted scenarios, and scenarios in which an LLM plays the user.

A scenario describes one conversation: who the user is, what they say or want, and what must be true at the end. Put each scenario in its own `.yaml` file. `run` and `validate` accept a file or a directory, and search directories recursively.

## A scripted scenario

A scripted user sends fixed messages, one per turn, in order. Use scripted users for anything you want to check the same way on every run.

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
```

- `name` identifies the scenario in the output and in reports. Keep names unique: `--filter` matches on them and transcript files are named after them.
- `persona` and `goal` are optional for scripted users. They document the intent of the scenario for the next reader.
- The conversation stops after the last scripted turn, when the agent hands off, or after `max_turns` turns (default 10), whichever comes first.
- `expect` lists the checks. They run after the conversation ends, against the whole transcript. See [Assertions](assertions.md).

Quote a turn when it contains a colon followed by a space, starts with a special YAML character, or contains an apostrophe inside single quotes. Double quotes are the safest choice.

## Check it before you run it

`validate` reads the files without contacting the agent and reports every problem with the file, the field path and the reason:

```bash
vendor/bin/agent-harness validate scenarios
```

```text
OK  scenarios/example.yaml
OK  scenarios/opening-hours.yaml

2 file(s), 2 valid, 0 invalid
```

`run` validates too, and stops with exit code 2 before the first request if any file is invalid.

## An LLM-played user

With `mode: llm`, an LLM writes the user's messages. It plays the `persona` and works towards the `goal`, so the conversation can take a path that you did not script. This needs an `llm` section in `harness.yaml`; see [LLM judge](llm-judge.md#configure-an-llm) for the configuration.

```yaml
name: LLM user books a table
persona: A regular customer who writes short messages and only gives details when asked. Their name is Sam.
goal: Book a table for 2 people on Friday at 19:30
user:
  mode: llm
max_turns: 6
expect:
  - tool_called: { name: create_booking, args: { party_size: 2, day: friday, name: Sam } }
```

The loop is:

1. The LLM writes the next user message, from the persona, the goal and the conversation so far.
2. The harness sends it to your agent.
3. The agent's reply goes back to the LLM.

It ends when the LLM writes `[[DONE]]` (the goal is reached or clearly impossible), when the agent hands off, or after `max_turns`. The `[[DONE]]` token counts only when the LLM writes it: the same text in an agent reply is defused and cannot end the conversation.

Set `user.opening` to fix the first message and let the LLM take over from the second:

```yaml
name: LLM user with a fixed opening
persona: A customer in a hurry
goal: Book a table for 2 tonight
user:
  mode: llm
  opening: Hi, can I book a table?
max_turns: 6
expect:
  - said: name
```

This is the output of the first scenario above with `-v`, against the demo agent. The LLM was replaced by a local stub server that always plays the same three messages, so the output shows the shape of a run, not the behaviour of a real model:

```text
PASS   LLM user books a table  (2 turns, 3 ms)
       ✓ tool_called create_booking: called in turn 2
       Transcript:
         1. user:  Hi, can I book a table for 2 on Friday at 19:30?
            agent: What name should I put the booking under? (1 ms)
         2. user:  Sam
            agent: Your table for 2 on Friday at 19:30 under the name Sam is confirmed. (1 ms)
            tool:  create_booking {"party_size":2,"day":"friday","time":"19:30","name":"Sam"}
```

Without an `llm` section, LLM-played scenarios are reported as SKIP with the reason `no llm configured in harness.yaml`, and the rest of the run continues.

## Choosing between scripted and LLM-played users

- Use scripted users for regressions and for anything that must hold every time. They are fast, free and give the same conversation on every run.
- Use LLM-played users to explore: different wording, details given in a different order, users who only answer what they are asked. Expect some variation between runs, and see [Flaky runs](flaky-runs.md).
- Check LLM-played conversations with deterministic expectations where you can. A `tool_called` with the right arguments says more than a judge rubric.

## Organising files

- One scenario per file, named after what it checks: `large-party.yaml`, `allergen-question.yaml`.
- Group them in directories by feature, and run a directory or a single file.
- Add the `yaml-language-server` line from [Editor support](editor-support.md) for completion and inline validation.
