# Pest plugin

You will be able to write conversation tests in your own Pest suite.

## Install

With Pest installed in your project, the plugin is registered through Composer's autoloader when you install agent-harness. There is nothing to configure. Without Pest, nothing is registered and the CLI works as usual.

The examples on this page ran in the empty project from the [Quickstart](../quickstart.md), after `composer require --dev pestphp/pest`, with the demo agent running.

## `conversation()`

```php
it('books a table', function () {
    $result = conversation(__DIR__ . '/../guide/tool-called.yaml')
        ->against('http://127.0.0.1:8787/chat')
        ->run();

    expect($result)->toPassScenario()
        ->toHaveCalledTool('create_booking', ['party_size' => 2])
        ->toHaveSaid('confirmed')
        ->not->toHaveHandedOff();
});
```

| Call | Meaning |
| --- | --- |
| `conversation(string\|Scenario $scenario)` | One scenario file, or a `Scenario` object built in code |
| `->against(string\|AgentAdapter $agent)` | The agent URL, or any `AgentAdapter`, for example an in-process fake of your agent |
| `->withConfig(string $path)` | A `harness.yaml` for field mapping, headers, timeout and the `llm` section |
| `->withLlm(LlmClient $llm)` | An LLM client for the LLM-played user and the judge |
| `->withSeed(int $seed)` | The seed passed to the LLM |
| `->run()` | Plays the scenario and returns a `ScenarioResult` |

## Expectations

| Expectation | Passes when |
| --- | --- |
| `toPassScenario()` | The scenario passed. A skipped scenario fails, so a missing LLM is not mistaken for a pass |
| `toHaveCalledTool(name, ?args)` | Same rules as `tool_called` |
| `toHaveSaid(text)` | Same rules as `said` |
| `toHaveHandedOff()` | Some turn handed off |

They chain, and work with `not`.

## Failure output

A failing `toPassScenario()` lists the failed expectations and the transcript. This test runs the demo's large-party scenario:

```php
it('hands a large party to staff', function () {
    $result = conversation(__DIR__ . '/../scenarios/large-party.yaml')
        ->against('http://127.0.0.1:8787/chat')
        ->run();

    expect($result)->toPassScenario();
});
```

The start of the failure, as printed by `vendor/bin/pest` (Pest 5.3 in this run):

```text
  FAILED  Tests\ConversationTest > it hands a large party to staff
  Scenario "Large party goes to staff" did not pass: FAIL
Failed expectations:
  ✗ handoff true: no handoff in 2 turn(s)
  ✗ never_said "confirmed": said in turn 2: "...turday at 20:00 under the name Marco is confirmed."
Transcript:
  1. user:  I'd like a table for 12 on Saturday at 20:00
     agent: What name should I put the booking under? (1 ms)
  2. user:  Name is Marco
     agent: Your table for 12 on Saturday at 20:00 under the name Marco is confirmed. (1 ms)
     tool:  create_booking {"party_size":12,"day":"saturday","time":"20:00","name":"Marco"}
...
  Tests:    1 failed, 1 passed (5 assertions)
```

## Testing an agent in process

`->against()` accepts any object that implements `Desemax\AgentHarness\Agent\AgentAdapter`:

```php
interface AgentAdapter
{
    public function send(string $sessionId, string $message): AgentReply;
}
```

Return an `AgentReply` with the reply text, tool calls, handoff flag and latency. Throw `AgentException` when the agent cannot answer; the scenario becomes ERROR. This lets you test an agent class directly, without an HTTP server.
