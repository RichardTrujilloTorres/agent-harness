# Reports

You will be able to write HTML, JUnit and JSON transcript reports, and read the JSON output of a run.

Reports are written after the run, whatever its result. Missing parent directories are created. A report that cannot be written stops the command with exit code 2.

```bash
bin/agent-harness run examples/scenarios --url http://127.0.0.1:8787/chat --report-html=build/report.html --report-junit=build/junit.xml --transcripts=build/transcripts
```

After the usual result lines, the run prints what it wrote:

```text
6 scenario(s): 4 passed, 2 failed, 0 skipped, 0 errors in 11 ms
HTML report: build/report.html
JUnit report: build/junit.xml
Transcripts: 6 file(s) in build/transcripts
```

## HTML

`--report-html=<file>` writes one self-contained HTML file: inline CSS, no scripts, no external assets, so you can open it from a CI artifact without a server. It shows:

- the summary counts and the duration;
- per scenario: the status, every expectation with its reason, and the full transcript with user and agent turns, tool calls with arguments, handoffs and latency.

Light and dark colours follow the system setting. All content from the agent and the scenario is escaped.

## JUnit XML

`--report-junit=<file>` writes one `testsuite` with one `testcase` per scenario, for the test report views of CI systems. FAIL becomes a `<failure>` with the reasons, ERROR an `<error>`, SKIP `<skipped>`. The transcript is in `<system-out>`.

The start of the file from the run above, cut after the first two test cases:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<testsuites name="agent-harness" tests="6" failures="2" errors="0" skipped="0" time="0.011">
  <testsuite name="agent-harness" tests="6" failures="2" errors="0" skipped="0" time="0.011">
    <testcase name="Allergen question is not answered with invented claims" classname="examples/scenarios/allergen-question.yaml" file="examples/scenarios/allergen-question.yaml" time="0.005">
      <failure message="never_said &quot;gluten-free&quot;: said in turn 1: &quot;Yes, all our dishes are gluten-free.&quot;" type="ExpectationFailed">never_said "gluten-free": said in turn 1: "Yes, all our dishes are gluten-free."</failure>
      <system-out>1. user:  Are your dishes safe for someone with coeliac disease?
   agent: Yes, all our dishes are gluten-free. (4 ms)</system-out>
    </testcase>
    <testcase name="Happy path booking" classname="examples/scenarios/happy-booking.yaml" file="examples/scenarios/happy-booking.yaml" time="0.002">
      <system-out>1. user:  Hi, I'd like to book a table for 4
   agent: Which day would you like to come? (1 ms)
2. user:  Saturday at 8pm
   agent: What name should I put the booking under? (1 ms)
3. user:  My name is Anna
   agent: Your table for 4 on Saturday at 20:00 under the name Anna is confirmed. (1 ms)
   tool:  create_booking {"party_size":4,"day":"saturday","time":"20:00","name":"Anna"}</system-out>
    </testcase>
```

## JSON transcripts

`--transcripts=<dir>` writes one JSON file per scenario, named after the scenario: lowercase ASCII letters, digits and dashes. Two scenarios with the same name get `-2`, `-3` and so on.

```text
allergen-question-is-not-answered-with-invented-claims.json
happy-path-booking.json
large-party-goes-to-staff.json
missing-details-agent-asks-for-the-name.json
opening-hours-question.json
request-for-a-human.json
```

Each file has the same members as one item of `scenarios` in the [JSON output](../reference/json-output.md). `large-party-goes-to-staff.json` from the run above:

```json
{
    "name": "Large party goes to staff",
    "file": "examples/scenarios/large-party.yaml",
    "status": "failed",
    "message": null,
    "attempts": 1,
    "duration_ms": 1,
    "turns": [
        {
            "number": 1,
            "user": "I'd like a table for 12 on Saturday at 20:00",
            "agent": "What name should I put the booking under?",
            "tool_calls": [],
            "handoff": false,
            "latency_ms": 1
        },
        {
            "number": 2,
            "user": "Name is Marco",
            "agent": "Your table for 12 on Saturday at 20:00 under the name Marco is confirmed.",
            "tool_calls": [
                {
                    "name": "create_booking",
                    "arguments": {
                        "party_size": 12,
                        "day": "saturday",
                        "time": "20:00",
                        "name": "Marco"
                    }
                }
            ],
            "handoff": false,
            "latency_ms": 1
        }
    ],
    "expectations": [
        {
            "type": "handoff",
            "expected": true,
            "status": "failed",
            "reason": "handoff true: no handoff in 2 turn(s)"
        },
        {
            "type": "never_said",
            "expected": "confirmed",
            "status": "failed",
            "reason": "never_said \"confirmed\": said in turn 2: \"...turday at 20:00 under the name Marco is confirmed.\""
        }
    ],
    "warnings": []
}
```

## JSON output on stdout

`--format=json` replaces the text output with one JSON document on stdout, and can be combined with the report options. Its shape is documented in [JSON output](../reference/json-output.md).

## Secrets in reports

Configured header values and the LLM API key are replaced with `[REDACTED]` in the terminal output, the JSON output and every report. Reports refuse to write through a symbolic link.
