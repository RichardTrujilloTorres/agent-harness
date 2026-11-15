# CLI

You will be able to look up every command, argument, option, default and exit code of `agent-harness`.

From a clone, run the tool as `bin/agent-harness`. In a project that installed the package, run `vendor/bin/agent-harness`. This page writes `agent-harness` for both. A test in `tests/Docs/` compares this page with the real `--help` output, so it lists exactly what the tool accepts.

Run the tool without a command to see the command overview:

```bash
bin/agent-harness
```

```text
agent-harness 0.1.0

Usage:
  command [options] [arguments]

Options:
  -h, --help            Display help for the given command. When no command is given display help for the list command
      --silent          Do not output any message
  -q, --quiet           Only errors are displayed. All other output is suppressed
  -V, --version         Display this application version
      --ansi|--no-ansi  Force (or disable --no-ansi) ANSI output
  -n, --no-interaction  Do not ask any interactive question
  -v|vv|vvv, --verbose  Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug

Available commands:
  completion  Dump the shell completion script
  help        Display help for a command
  init        Create a commented harness.yaml and an example scenario in the current directory
  list        List scenarios with their user mode and expectation count
  run         Play scenarios against an agent over HTTP and report pass or fail
  validate    Validate scenario files (a file or a directory, recursive)
```

## `init`

Creates `harness.yaml` and `scenarios/example.yaml` in the current directory.

```text
agent-harness init
```

It takes no arguments and no options of its own. It refuses to overwrite either file: if one exists, it prints `Refusing to overwrite <file>` and creates nothing.

| Exit code | Meaning |
| --- | --- |
| 0 | Both files were created |
| 1 | A file already exists, or a file could not be written |

## `validate`

Checks scenario files without contacting any agent.

```text
agent-harness validate [--format=FORMAT] <path>
```

| Argument | Meaning |
| --- | --- |
| `path` | A scenario file, or a directory searched recursively for `.yaml` and `.yml` files |

| Option | Default | Meaning |
| --- | --- | --- |
| `--format=FORMAT` | `text` | `text`, or `json` for one JSON document on stdout without colours. See [JSON output](json-output.md) |

| Exit code | Meaning |
| --- | --- |
| 0 | Every file is valid |
| 1 | At least one file is invalid, or the directory has no `.yaml` or `.yml` file |
| 2 | The path does not exist, or a bad option |

```bash
bin/agent-harness validate examples/scenarios
```

```text
OK  examples/scenarios/allergen-question.yaml
OK  examples/scenarios/happy-booking.yaml
OK  examples/scenarios/human-handoff.yaml
OK  examples/scenarios/large-party.yaml
OK  examples/scenarios/missing-details.yaml
OK  examples/scenarios/opening-hours.yaml

6 file(s), 6 valid, 0 invalid
```

## `list`

Prints the scenarios in a file or directory without running them.

```text
agent-harness list <path>
```

| Argument | Meaning |
| --- | --- |
| `path` | A scenario file, or a directory searched recursively for `.yaml` and `.yml` files |

It has no options of its own. It validates first: invalid files are printed as by `validate` and nothing is listed.

| Exit code | Meaning |
| --- | --- |
| 0 | The scenarios were listed |
| 1 | At least one file is invalid, or the directory has no `.yaml` or `.yml` file |
| 2 | The path does not exist |

```bash
bin/agent-harness list examples/scenarios
```

```text
NAME                                                         MODE     EXPECTATIONS FILE
Allergen question is not answered with invented claims       scripted 2            examples/scenarios/allergen-question.yaml
Happy path booking                                           scripted 5            examples/scenarios/happy-booking.yaml
Request for a human                                          scripted 2            examples/scenarios/human-handoff.yaml
Large party goes to staff                                    scripted 2            examples/scenarios/large-party.yaml
Missing details, agent asks for the name                     scripted 3            examples/scenarios/missing-details.yaml
Opening hours question                                       scripted 3            examples/scenarios/opening-hours.yaml
6 scenario(s)
```

## `run`

Plays scenarios against an agent over HTTP and reports pass or fail.

```text
agent-harness run [options] <path>
```

| Argument | Meaning |
| --- | --- |
| `path` | A scenario file, or a directory searched recursively for `.yaml` and `.yml` files |

| Option | Default | Meaning |
| --- | --- | --- |
| `--url=URL` | `agent.url` | Agent URL. Overrides `agent.url` from the config. Must be an `http://` or `https://` URL with a host |
| `--config=CONFIG` | `./harness.yaml` if present | Config file. See [harness.yaml](config.md) |
| `--filter=FILTER` | none | Only run scenarios whose name contains this text, case-insensitive |
| `--seed=SEED` | none | Integer passed to the LLM for the LLM-played user and the judge |
| `--report-html=REPORT-HTML` | none | Write a self-contained HTML report to this file |
| `--report-junit=REPORT-JUNIT` | none | Write a JUnit XML report to this file |
| `--transcripts=TRANSCRIPTS` | none | Write one JSON transcript per scenario into this directory |
| `--retries=RETRIES` | `0` | Re-run a failed or errored scenario up to this many times. Passing on a retry is reported FLAKY |
| `--fail-fast` | off | Stop after the first scenario that fails or errors |
| `--format=FORMAT` | `text` | `text`, or `json` for one JSON document on stdout without colours |

`run` validates every file before it contacts the agent. With `-v` it also prints every expectation result and the full transcript.

| Exit code | Meaning |
| --- | --- |
| 0 | No failures. Skipped and flaky scenarios are allowed |
| 1 | At least one scenario failed |
| 2 | Bad path, bad config, bad option, invalid scenarios, LLM key variable not set, or a report could not be written |
| 3 | At least one scenario errored. Takes precedence over 1 |

```bash
bin/agent-harness run examples/scenarios --url http://127.0.0.1:8787/chat --filter=hours
```

```text
PASS   Opening hours question  (1 turn, 4 ms)

1 scenario(s): 1 passed, 0 failed, 0 skipped, 0 errors in 5 ms
```

## `help`

Prints the help of a command. `agent-harness help run` is the same as `agent-harness run --help`.

```text
agent-harness help [--format=FORMAT] [--raw] [<command_name>]
```

| Argument | Meaning |
| --- | --- |
| `command_name` | The command to describe. Default: `help` |

| Option | Default | Meaning |
| --- | --- | --- |
| `--format=FORMAT` | `txt` | `txt`, `xml`, `json` or `md` |
| `--raw` | off | Output the raw command help |

## `completion`

Prints a shell completion script. This command comes from Symfony Console.

```text
agent-harness completion [--debug] [<shell>]
```

| Argument | Meaning |
| --- | --- |
| `shell` | The shell type, for example `bash`. Default: the value of the `SHELL` environment variable |

| Option | Default | Meaning |
| --- | --- | --- |
| `--debug` | off | Tail the completion debug log |

## Global options

Every command accepts these options.

| Option | Meaning |
| --- | --- |
| `--help`, `-h` | Display help for the given command |
| `--silent` | Do not output any message |
| `--quiet`, `-q` | Only errors are displayed |
| `--version`, `-V` | Display the application version |
| `--ansi`, `--no-ansi` | Force or disable ANSI output |
| `--no-interaction`, `-n` | Do not ask any interactive question |
| `--verbose`, `-v`, `-vv`, `-vvv` | Increase verbosity. For `run`, `-v` prints every expectation result and the full transcript |

```bash
bin/agent-harness --version
```

```text
agent-harness 0.1.0
```
