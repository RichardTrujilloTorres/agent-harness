# Quickstart

You will run a passing and an intentionally failing scenario, then create a scenario in your own project.

## Requirements

You need PHP 8.3 or later, Composer and Git. The package is not on Packagist. Clone the repository and install its dependencies:

```bash
git clone https://github.com/RichardTrujilloTorres/agent-harness
cd agent-harness
composer install
export AGENT_HARNESS_PATH="$PWD"
```

Keep the demo agent running in this terminal:

```bash
composer demo
```

The server listens on `127.0.0.1:8787`. It needs no API key.

## Run the examples

Open a second terminal in the clone. Run one scenario to see a passing result:

```bash
bin/agent-harness run examples/scenarios --url http://127.0.0.1:8787/chat --filter="Opening hours"
```

```text
PASS   Opening hours question  (1 turn, 8 ms)

1 scenario(s): 1 passed, 0 failed, 0 skipped, 0 errors in 8 ms
```

Run all six bundled scenarios:

```bash
bin/agent-harness run examples/scenarios --url http://127.0.0.1:8787/chat
```

```text
FAIL   Allergen question is not answered with invented claims  (1 turn, 3 ms)
       ✗ never_said "gluten-free": said in turn 1: "Yes, all our dishes are gluten-free."
PASS   Happy path booking  (3 turns, 3 ms)
PASS   Request for a human  (1 turn, 1 ms)
FAIL   Large party goes to staff  (2 turns, 2 ms)
       ✗ handoff true: no handoff in 2 turn(s)
       ✗ never_said "confirmed": said in turn 2: "...turday at 20:00 under the name Marco is confirmed."
PASS   Missing details, agent asks for the name  (1 turn, 1 ms)
PASS   Opening hours question  (1 turn, 1 ms)

6 scenario(s): 4 passed, 2 failed, 0 skipped, 0 errors in 12 ms
```

The command exits with code 1. The failures are intentional: Trattoria Demo incorrectly claims that every dish is gluten-free and confirms a party of 12 instead of handing it to staff.

On an Apple M1 Pro on 2026-11-15, the clean-clone path reached the first PASS in 54 seconds and the first intended FAIL in 54 seconds. This includes dependency installation and the local PHP workaround described in [Contributing](project/contributing.md).

## Create your own project

Keep the demo running. In the second terminal, create an empty project next to the clone and install from the local path:

```bash
mkdir ../my-agent-tests
cd ../my-agent-tests
composer init --name=example/agent-tests --no-interaction
composer config repositories.agent-harness path "$AGENT_HARNESS_PATH"
composer require --dev desemax/agent-harness:dev-main
vendor/bin/agent-harness init
```

`init` creates `harness.yaml` and `scenarios/example.yaml` without overwriting existing files. The generated config already points to the demo. Validate and run the example:

```bash
vendor/bin/agent-harness validate scenarios
vendor/bin/agent-harness run scenarios
```

```text
OK  scenarios/example.yaml

1 file(s), 1 valid, 0 invalid
PASS   Example conversation  (1 turn, 4 ms)

1 scenario(s): 1 passed, 0 failed, 0 skipped, 0 errors in 4 ms
```

The empty-project path reached this PASS in 8 seconds on the same machine and date.

## Write a scenario

Create a scenario for the demo's opening-hours response:

```bash
cat > scenarios/opening-hours.yaml <<'YAML'
name: My opening hours check
user:
  mode: scripted
  turns:
    - When are you open?
max_turns: 2
expect:
  - said: Tuesday to Sunday
  - handoff: false
YAML
vendor/bin/agent-harness run scenarios/opening-hours.yaml
```

```text
PASS   My opening hours check  (1 turn, 5 ms)

1 scenario(s): 1 passed, 0 failed, 0 skipped, 0 errors in 6 ms
```

To test your own agent, set `agent.url` in `harness.yaml` to its HTTP chat endpoint. If its JSON fields differ from the demo contract, follow [Connecting your agent](guides/connecting-agent.md). Add the turns and expectations that describe the behaviour you need; [Writing scenarios](guides/writing-scenarios.md) explains scripted and LLM-played users.

After the first release, a project on Packagist can instead use `composer require --dev desemax/agent-harness`.
