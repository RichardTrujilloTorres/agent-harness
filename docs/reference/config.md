# harness.yaml

You will be able to look up every key of `harness.yaml`, its type, default and constraints.

The config file is optional. `run` reads `./harness.yaml` from the current directory when it exists, or the file passed with `--config`. Every key has a default. Any other key is an error, with a suggestion for close misspellings. The JSON Schema `schema/harness.schema.json` describes the same rules for editors.

This file sets every key to its default, except `agent.headers` and `llm`, which have none:

```yaml
agent:
  url: http://127.0.0.1:8787/chat
  timeout_ms: 10000
  headers:
    Authorization: Bearer replace-me
  follow_redirects: false
  max_response_bytes: 1048576
request:
  session_field: session_id
  message_field: message
response:
  reply: reply
  tool_calls: tool_calls
  tool_name: name
  tool_arguments: arguments
  handoff: handoff
llm:
  base_url: https://llm.example.com/v1
  model: your-model-name
  api_key_env: HARNESS_LLM_API_KEY
  timeout_ms: 30000
  max_tokens: 400
  max_response_bytes: 1048576
```

## `agent`

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `url` | string | none | The agent endpoint. An `http://` or `https://` URL with a host. `--url` overrides it. `run` stops with exit code 2 when neither is set |
| `timeout_ms` | integer, at least 1 | `10000` | Timeout of each request to the agent |
| `headers` | map of string to string | none | Sent with every request. Values are redacted from all output |
| `follow_redirects` | boolean | `false` | Follow up to 5 redirects. When `false`, a redirect is an ERROR |
| `max_response_bytes` | integer, at least 1 | `1048576` | Larger response bodies are an ERROR |

## `request`

The harness sends `POST` with a JSON body built from two dot-paths.

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `session_field` | dot-path | `session_id` | Where the session id goes. Each scenario gets a new random id |
| `message_field` | dot-path | `message` | Where the user message goes. Must differ from `session_field` |

## `response`

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `reply` | dot-path | `reply` | The reply text. Required in every response: a missing field is an ERROR |
| `tool_calls` | dot-path | `tool_calls` | A list of tool calls. Optional |
| `tool_name` | dot-path | `name` | The tool name inside each tool call |
| `tool_arguments` | dot-path | `arguments` | The arguments inside each tool call: an object, or a string that contains a JSON object |
| `handoff` | dot-path | `handoff` | A boolean. Optional; missing means `false` |

A dot-path is a list of keys separated by dots, with no spaces and no empty segment. Numeric segments index lists: `choices.0.message.content`. [Connecting your agent](../guides/connecting-agent.md) has a worked example.

## `llm`

Optional. Enables the LLM-played user and `judge` expectations. Without it, both are skipped.

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `base_url` | string | required | The harness sends `POST {base_url}/chat/completions` in the OpenAI-compatible format |
| `model` | string | required | The model name sent with every request |
| `api_key_env` | string | `HARNESS_LLM_API_KEY` | The name of the environment variable that holds the API key. Letters, digits and underscores, not starting with a digit |
| `timeout_ms` | integer, at least 1 | `30000` | Timeout of each LLM request |
| `max_tokens` | integer, at least 1 | none | Cap on the length of each LLM answer |
| `max_response_bytes` | integer, at least 1 | `1048576` | Larger LLM response bodies are an error |

The API key is never read from the file. `run` asks for it only when a scenario needs the LLM; see [Environment variables](environment.md).

## Errors

An invalid config stops `run` with exit code 2 and lists every error. This is the output for `agent: {timout_ms: 5}`:

```text
Invalid config harness.yaml
  - agent.timout_ms: unknown field "timout_ms"; did you mean "timeout_ms"? allowed: url, timeout_ms, headers, follow_redirects, max_response_bytes
```
