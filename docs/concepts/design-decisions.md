# Design decisions

You will be able to judge whether the trade-offs behind agent-harness fit your project.

## Why YAML scenarios

A scenario is mostly data: messages and expected outcomes. YAML keeps it readable for people who do not write PHP, such as product owners and support staff who know what the agent must and must not say. Files are easy to review in a pull request, and one file per scenario keeps the diffs small.

The cost is that YAML has sharp edges, such as unquoted colons and indentation. The validator reports every error with the file and the field path, suggests corrections for misspelled keys, and the JSON Schemas give editors completion and inline errors. When you need code, for example to build scenarios in a loop or to test an agent in process, the Pest plugin accepts `Scenario` objects built in PHP.

## Why HTTP only in v0.1

Almost every conversational agent can be reached over HTTP with JSON, whatever language it is written in. One adapter with dot-path remapping covers many response shapes without code, and keeps the harness independent of any framework or vendor SDK. Testing the agent through its real endpoint also tests the code around the model: routing, session handling, tool calls.

Other transports and voice are on the [Roadmap](../project/roadmap.md) and are not built. In PHP you can already plug in any transport through the `AgentAdapter` interface with the Pest plugin.

## Why deterministic checks first

A test is useful when its verdict can be trusted and repeated. `tool_called`, `said`, `never_said`, `handoff`, `max_turns` and `latency_ms_under` are evaluated in the harness, cost nothing, and give the same result for the same transcript. They also cannot be talked out of a verdict by the agent under test.

The LLM judge exists for what these checks cannot express, such as tone or whether a question was really answered. It is optional, it is skipped without an `llm` section, and the docs recommend it only next to deterministic checks for anything critical. The [LLM judge](../guides/llm-judge.md#limits-prefer-deterministic-checks) page explains why.

## Why scripted users by default

A scripted user gives the same conversation on every run, so a failure points at a change in the agent. LLM-played users find paths you did not script, at the cost of variation between runs and an LLM bill. Both use the same scenario format, so a project can start scripted and add LLM-played scenarios where exploration pays off.

## Why few runtime dependencies

The runtime depends on PHP 8.3 and three Symfony components: Console, Yaml and HttpClient. Fewer dependencies mean fewer conflicts in your project, a smaller PHAR and a smaller attack surface. Development tools stay in `require-dev`.

## Why the output is strict

Exit codes separate a failed expectation (1) from bad input (2) and an agent or LLM that could not be used (3), so CI can treat an outage differently from a regression. The JSON output is one document with a `schema_version`, and the text output is stripped of terminal control sequences from the agent. Reports are self-contained files with no remote assets, so they can be opened from a CI artifact.
