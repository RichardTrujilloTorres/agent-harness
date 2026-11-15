# agent-harness

You will be able to decide in a minute whether agent-harness fits your project, and where to start.

agent-harness is a test runner for conversational agents, written in PHP. You describe a conversation in a YAML scenario, the harness plays it against your agent over HTTP, and it reports which expectations passed and which failed.

It is for developers who build chatbots or LLM agents and want to check their behaviour locally and in CI like any other code: which tools the agent calls with which arguments, what it must never say, when it hands off to a human.

```yaml
name: Opening hours
user:
  mode: scripted
  turns: [When are you open?]
expect:
  - said: Tuesday to Sunday
```

- [Quickstart](quickstart.md): a first passing and a first failing scenario in about a minute, with a demo agent that needs no API key.
- [How it works](concepts/how-it-works.md): what happens during a run.
- [Reference](reference/cli.md): every command, option, file key and exit code.
