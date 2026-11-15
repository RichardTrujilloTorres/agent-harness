# Glossary

You will be able to look up the terms used in scenarios, output and reports.

| Term | Meaning |
| --- | --- |
| Agent | The conversational system under test. The harness reaches it over HTTP, or through an `AgentAdapter` in the Pest plugin. |
| Agent adapter | The code that sends one user message to the agent and returns its reply. The CLI uses the HTTP adapter; the Pest plugin accepts any implementation of `AgentAdapter`. |
| Dot-path | A path to a field in a JSON object, with keys separated by dots, such as `data.output.text`. Numeric segments index lists: `choices.0.message.content`. |
| ERROR | A scenario status: the conversation or an expectation could not be completed because the agent or the LLM could not be used. |
| Expectation | One check in the `expect` list of a scenario, such as `said: confirmed`. |
| FAIL | A scenario status: at least one expectation did not hold. |
| FLAKY | A scenario status with `--retries`: the scenario failed or errored, then passed on a retry. It counts as a pass for the exit code. |
| Handoff | The agent passes the conversation to a human. Read from the `handoff` field of the response; it ends the conversation. |
| Judge | An LLM that reads the transcript and decides whether a rubric is met. Used by `judge` expectations. |
| LLM-played user | A user whose messages an LLM writes, from the scenario's `persona` and `goal`. Set with `user.mode: llm`. |
| PASS | A scenario status: no expectation failed or errored, and at least one passed. |
| Persona | Who the user is, in the scenario's `persona` key. Used by the LLM-played user. |
| Rubric | The statement a judge checks, the value of a `judge` expectation. |
| Scenario | One YAML file that describes a conversation: the user, the turns or goal, and the expectations. |
| Scripted user | A user whose messages are fixed in `user.turns`. Set with `user.mode: scripted`. |
| Session id | A random id that the harness sends with every message of one scenario, so the agent can keep the conversation state. |
| SKIP | A scenario status: nothing could be checked, for example only `judge` expectations without an LLM. |
| Suspicious reply | A warning for an agent reply that looks like prompt injection aimed at the LLM user or the judge. |
| Tool call | An action the agent reports in its response, with a name and arguments, such as `create_booking`. Checked with `tool_called`. |
| Transcript | The record of a conversation: every turn with the user message, the agent reply, tool calls, handoff and latency. |
| Turn | One user message and the agent's reply to it. |
