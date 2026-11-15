# Environment variables

You will be able to set the environment variables that agent-harness and its documentation build read.

## The LLM API key

| Variable | Read by | Meaning |
| --- | --- | --- |
| `HARNESS_LLM_API_KEY` | `run`, `conversation()` in Pest | The LLM API key. This is the default name; `llm.api_key_env` in `harness.yaml` can name another variable |

The key is read only when the config has an `llm` section and a selected scenario needs the LLM: a `user.mode: llm` scenario or a `judge` expectation. When the variable is not set or empty in that case, `run` stops with exit code 2:

```text
Environment variable HARNESS_LLM_API_KEY is not set. Export your LLM API key in it, or name another variable in llm.api_key_env.
```

The value is sent only in the `Authorization` header to `llm.base_url`, and it is replaced with `[REDACTED]` in every output. See [Security](../security/index.md).

Runs without an `llm` section, or without LLM scenarios and `judge` expectations, never read the variable.

## Documentation build

| Variable | Read by | Meaning |
| --- | --- | --- |
| `DOCS_BASE` | `npm --prefix docs run build` | The base path of the documentation site. Default `/`. The publishing workflow sets `/agent-harness/` |

## Agent headers

The tool does not expand variables inside `harness.yaml`. To keep a token out of the file, generate the file in your CI step from a secret, or put a placeholder in the committed file and replace it before the run.
