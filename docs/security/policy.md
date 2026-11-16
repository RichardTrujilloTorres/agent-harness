# Security policy

You will learn how to report a vulnerability privately and what is in scope.

This page mirrors the repository's `SECURITY.md`.

## Reporting a vulnerability

Please report vulnerabilities privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability** (GitHub private vulnerability reporting). Do not open a public issue for a security problem.

Include what you found, how to reproduce it (a scenario file, a config and the agent response are ideal), and the impact you expect. You will get an acknowledgement within 7 days and a plan for a fix or a reasoned answer within 30 days. Once a fix is released, the advisory is published with credit to you unless you prefer otherwise.

## Supported versions

| Version | Supported |
| --- | --- |
| 0.1.x (latest) | Yes |
| Older | No |

Security fixes go into the latest release only.

## Scope

In scope: the `agent-harness` CLI, the library code in `src/`, the Pest plugin, the reports it writes, and the GitHub Actions workflows in this repository. The [threat model](threat-model.md) lists the threats considered, how each is handled and the limits that are accepted.

Out of scope:

- The demo agent in `examples/demo-agent` beyond what the threat model covers. It is a local demo with two intended bugs.
- An LLM judge being persuaded by the agent under test. This is a documented limit; please report it only if one of the mitigations in the threat model can be bypassed.
- Vulnerabilities in the agent under test, in your LLM provider or in PHP itself.
