# How it works

You will be able to follow a run from the YAML files to the exit code, and know what happens at each step.

<figure class="lifecycle">
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 720 300" role="img" aria-labelledby="lifecycle-title lifecycle-desc" style="max-width:100%;height:auto">
  <title id="lifecycle-title">Run lifecycle</title>
  <desc id="lifecycle-desc">Files are validated, then each scenario plays turns against the agent until it stops, the expectations are evaluated, and the results are printed and written to reports before the exit code is returned.</desc>
  <defs>
    <marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
      <path d="M0,0 L10,5 L0,10 z" fill="#57606a"/>
    </marker>
  </defs>
  <g font-family="system-ui, sans-serif" font-size="13" fill="#1f2328">
    <rect x="10" y="20" width="150" height="54" rx="6" fill="#ddf4ff" stroke="#54aeff"/>
    <text x="85" y="43" text-anchor="middle">1. Load and validate</text>
    <text x="85" y="61" text-anchor="middle" font-size="11">scenarios, harness.yaml</text>

    <rect x="200" y="20" width="150" height="54" rx="6" fill="#ddf4ff" stroke="#54aeff"/>
    <text x="275" y="43" text-anchor="middle">2. Next user message</text>
    <text x="275" y="61" text-anchor="middle" font-size="11">scripted turn or LLM</text>

    <rect x="390" y="20" width="150" height="54" rx="6" fill="#ddf4ff" stroke="#54aeff"/>
    <text x="465" y="43" text-anchor="middle">3. POST to the agent</text>
    <text x="465" y="61" text-anchor="middle" font-size="11">map request and response</text>

    <rect x="580" y="20" width="130" height="54" rx="6" fill="#fff8c5" stroke="#d4a72c"/>
    <text x="645" y="43" text-anchor="middle">4. Stop?</text>
    <text x="645" y="61" text-anchor="middle" font-size="11">turns, handoff, [[DONE]]</text>

    <rect x="390" y="150" width="150" height="54" rx="6" fill="#ddf4ff" stroke="#54aeff"/>
    <text x="465" y="173" text-anchor="middle">5. Evaluate</text>
    <text x="465" y="191" text-anchor="middle" font-size="11">every expectation</text>

    <rect x="200" y="150" width="150" height="54" rx="6" fill="#ddf4ff" stroke="#54aeff"/>
    <text x="275" y="173" text-anchor="middle">6. Status</text>
    <text x="275" y="191" text-anchor="middle" font-size="11">retry if --retries</text>

    <rect x="10" y="150" width="150" height="54" rx="6" fill="#dafbe1" stroke="#4ac26b"/>
    <text x="85" y="173" text-anchor="middle">7. Output and reports</text>
    <text x="85" y="191" text-anchor="middle" font-size="11">then the exit code</text>

    <text x="645" y="112" text-anchor="middle" font-size="11">no: next turn</text>
    <text x="575" y="150" text-anchor="middle" font-size="11">yes</text>
    <text x="275" y="250" text-anchor="middle" font-size="11">next scenario</text>
  </g>
  <g stroke="#57606a" stroke-width="1.5" fill="none" marker-end="url(#arrow)">
    <line x1="160" y1="47" x2="198" y2="47"/>
    <line x1="350" y1="47" x2="388" y2="47"/>
    <line x1="540" y1="47" x2="578" y2="47"/>
    <path d="M645,74 L645,100 L275,100 L275,76"/>
    <path d="M610,74 L610,177 L542,177"/>
    <line x1="390" y1="177" x2="352" y2="177"/>
    <line x1="200" y1="177" x2="162" y2="177"/>
    <path d="M275,204 L275,236 L180,236 L180,100 L240,100 L240,76"/>
  </g>
</svg>
<figcaption>The run lifecycle of <code>agent-harness run</code>.</figcaption>
</figure>

## 1. Load and validate

`run` collects the `.yaml` and `.yml` files under the path, sorted by path, and validates every one. If any is invalid, it prints the errors and exits with code 2 before the first request. It then reads the config: the `--config` file, or `./harness.yaml` when it exists, or the built-in defaults. `--url` overrides `agent.url`. `--filter` keeps the scenarios whose name contains the text.

If a selected scenario needs an LLM and the config has an `llm` section, the API key is read from the environment now. A missing key stops the run with code 2.

## 2. Next user message

Each scenario starts with a new random session id. The next user message is the next scripted turn, or, for `user.mode: llm`, a message that the LLM writes from the persona, the goal and the conversation so far. A scenario that needs an LLM when none is configured is skipped here.

## 3. Send it to the agent

The harness sends one HTTP `POST` with the session id and the message at the configured dot-paths, then reads the reply, the tool calls and the handoff flag from the response. It measures the latency of the request. An unreachable agent, a timeout, a non-2xx status, invalid JSON or a missing reply field ends the scenario as ERROR.

## 4. Stop or continue

The conversation stops when the scripted turns run out, when `max_turns` is reached, when the agent hands off, or when the LLM-played user writes `[[DONE]]`. Otherwise it goes back to step 2.

## 5. Evaluate the expectations

Every expectation is evaluated against the whole transcript: deterministic ones in the harness, `judge` ones by one LLM request each. Agent replies are also scanned for text that looks like prompt injection, which adds `suspicious_reply` warnings.

## 6. Status and retries

The scenario is FAIL if an expectation failed, ERROR if one errored, SKIP if none could be checked, and PASS otherwise. With `--retries`, a failed or errored scenario is played again from step 2; a later pass is FLAKY. With `--fail-fast`, the run stops after the first FAIL or ERROR.

## 7. Output, reports and exit code

The result of each scenario is printed as soon as it is known. After the last scenario, the run prints the summary, writes the requested reports, and exits with 3 if any scenario errored, 1 if any failed, and 0 otherwise. Secrets are redacted from everything printed or written.

## The pieces

| Part | Role |
| --- | --- |
| Scenario loader and validator | Read YAML safely and report every error with its path |
| Config loader | Read `harness.yaml` and apply defaults |
| HTTP agent adapter | Build requests and read responses through the dot-path mapping |
| LLM client | Talk to an OpenAI-compatible server for the LLM-played user and the judge |
| Scenario runner | Play one conversation and evaluate it |
| Suite runner | Run the scenarios in order, with retries and `--fail-fast` |
| Evaluators | One per expectation type |
| Reports | Text, JSON, HTML, JUnit and transcripts |
