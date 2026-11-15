# agent-harness

agent-harness runs YAML conversation scenarios against an HTTP agent and reports whether each expectation passed. It is for developers who want to test scripted or LLM-played conversations locally and in CI.

```yaml
name: Opening hours
user:
  mode: scripted
  turns: [When are you open?]
expect:
  - said: Tuesday to Sunday
```

[Quickstart](quickstart.md) · [How it works](concepts/how-it-works.md) · [Reference](reference/cli.md)
