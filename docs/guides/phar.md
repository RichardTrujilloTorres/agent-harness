# Running as a PHAR

You will be able to build agent-harness as a single executable file and run it without installing it in your project.

A PHAR bundles the tool and its runtime dependencies into one file. Use it when you do not want the package in your project's `composer.json`, for example to test an agent that is not written in PHP. It needs PHP 8.3 or later and the extensions `dom`, `iconv` and `mbstring`.

## Build it

From a clone, with development dependencies installed:

```bash
composer phar
```

This writes `build/agent-harness.phar`. `composer phar:check` validates the examples and runs the demo with the PHAR, as a smoke test:

```bash
composer phar:check
```

```text
OK  examples/scenarios/allergen-question.yaml
...
6 file(s), 6 valid, 0 invalid
Demo run: 4 passed, 2 failed, exit code 1 (expected 4, 2, 1)
```

## Run it

The PHAR takes the same commands, options and files as `bin/agent-harness`:

```bash
php build/agent-harness.phar --version
```

```text
agent-harness 0.1.0
```

```bash
php build/agent-harness.phar run examples/scenarios --url http://127.0.0.1:8787/chat --filter=human
```

```text
PASS   Request for a human  (1 turn, 3 ms)

1 scenario(s): 1 passed, 0 failed, 0 skipped, 0 errors in 3 ms
```

The file is executable, so `build/agent-harness.phar run ...` also works on systems where PHP is on the `PATH`. Copy it anywhere; it reads `harness.yaml` from the current directory, like the installed tool.

The PHAR includes the JSON Schemas in `schema/`, but editors cannot read files inside a PHAR. For [Editor support](editor-support.md), copy the two schema files next to your scenarios.

## The Pest plugin

The Pest plugin needs the package installed with Composer in your project. The PHAR provides only the CLI.
