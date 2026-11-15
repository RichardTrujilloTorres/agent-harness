# Connecting your agent

You will be able to point the harness at your own HTTP agent, whatever JSON shape it uses.

## The HTTP contract

For every user message, the harness sends one `POST` request with a JSON body to the agent URL, and expects a JSON object back with status 2xx.

By default the request is:

```json
{"session_id": "harness-1f3a9c0b2d4e6f81", "message": "When are you open?"}
```

and the response is read as:

```json
{"reply": "We are open Tuesday to Sunday, 19:00 to 23:00.", "tool_calls": [], "handoff": false}
```

- `session_id` is new and random for each scenario, and stays the same for all turns of that scenario. Your agent uses it to keep the conversation state.
- `reply` is required. A response without it is an ERROR.
- `tool_calls` is optional: a list of objects with `name` and `arguments`. `arguments` is an object, or a string that contains a JSON object.
- `handoff` is optional: `true` when the agent passes the conversation to a human. A handoff ends the conversation.

The request has the headers `Content-Type: application/json` and `Accept: application/json`, plus any header you configure.

Set the URL in `harness.yaml`, or pass it with `--url`:

```yaml
agent:
  url: http://127.0.0.1:8787/chat
```

## Field remapping: a worked example

Your agent does not need to change. If it uses other field names or nests them, map them with dot-paths in `harness.yaml`.

Take an agent that expects this request:

```json
{"conversation": {"id": "..."}, "input": {"text": "..."}}
```

and answers like this:

```json
{"data": {"output": {"text": "..."}, "actions": [{"tool": "create_booking", "params": "{\"party_size\":2}"}], "escalate": false}}
```

The tool arguments arrive as a JSON string in `params`, and the handoff flag is called `escalate`. This config maps every field:

```yaml
agent:
  url: http://127.0.0.1:8789/chat
request:
  session_field: conversation.id
  message_field: input.text
response:
  reply: data.output.text
  tool_calls: data.actions
  tool_name: tool
  tool_arguments: params
  handoff: data.escalate
```

- `request.*` paths say where the harness writes the session id and the message. Intermediate objects are created.
- `response.reply`, `response.tool_calls` and `response.handoff` are read from the top of the response.
- `response.tool_name` and `response.tool_arguments` are read inside each item of the tool call list.
- A numeric segment indexes a list: `choices.0.message.content`.

With that config, a scenario written for the default contract runs unchanged. This run used a small local agent with exactly the shape above:

```text
PASS   Booking for two  (2 turns, 8 ms)
       ✓ tool_called create_booking: called in turn 2
       Transcript:
         1. user:  Book a table for 2 on Friday at 19:30
            agent: What name should I put the booking under? (5 ms)
         2. user:  My name is Sam
            agent: Your table for 2 on Friday at 19:30 under the name Sam is confirmed. (1 ms)
            tool:  create_booking {"party_size":2,"day":"friday","time":"19:30","name":"Sam"}

1 scenario(s): 1 passed, 0 failed, 0 skipped, 0 errors in 8 ms
```

## Headers

Add headers for authentication or routing. They are sent with every request to the agent, and their values are replaced with `[REDACTED]` in every output and report.

```yaml
agent:
  url: https://agent.example.com/chat
  headers:
    Authorization: Bearer replace-me
    X-Test-Run: "true"
```

The config file is plain YAML and does not expand environment variables. Keep real tokens out of the repository: write the file in your CI step from a secret, or replace a placeholder before the run.

## Timeouts and limits

| Key | Default | Effect |
| --- | --- | --- |
| `agent.timeout_ms` | `10000` | A request that takes longer is an ERROR: `Agent at <url> timed out after <n> ms` |
| `agent.max_response_bytes` | `1048576` | A larger body is an ERROR. Raise it if your agent sends large responses |
| `agent.follow_redirects` | `false` | A 3xx answer is an ERROR unless set to `true`; then up to 5 redirects are followed |

An agent that is down, answers a non-2xx status, or answers invalid JSON makes the scenario an ERROR, and the run exits with code 3. [Troubleshooting](../troubleshooting.md) lists each message.

## Testing an agent that is not an HTTP service

The CLI talks HTTP only. If your agent is a PHP object, use the [Pest plugin](pest-plugin.md): `->against()` accepts any implementation of the `AgentAdapter` interface, so you can call your code in process.
