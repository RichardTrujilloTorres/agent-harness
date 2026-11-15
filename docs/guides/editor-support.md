# Editor support

You will be able to get completion and inline validation for scenario files and `harness.yaml` in your editor.

The package ships two JSON Schemas (draft 2020-12):

| File | Describes |
| --- | --- |
| `schema/scenario.schema.json` | Scenario files |
| `schema/harness.schema.json` | `harness.yaml` |

A test in this repository checks every example and test fixture against both the JSON Schemas and the PHP validator, so the two cannot drift apart. The validator remains the reference: `validate` and `run` decide, and their messages are more precise.

## The `yaml-language-server` line

Editors that use the YAML language server read a schema from a comment on the first line of the file. `init` writes this line for you. The path is relative to the file.

In `scenarios/example.yaml` of a project that installed the package:

```yaml
# yaml-language-server: $schema=../vendor/desemax/agent-harness/schema/scenario.schema.json
name: Example conversation
user:
  mode: scripted
  turns:
    - Hello
expect:
  - said: hello
```

In `harness.yaml` at the project root:

```yaml
# yaml-language-server: $schema=vendor/desemax/agent-harness/schema/harness.schema.json
agent:
  url: http://127.0.0.1:8787/chat
```

For files in deeper directories, add one `../` per level. The examples in this repository use `../../schema/scenario.schema.json`, relative to the clone.

## Without the comment

Most editors with a YAML extension can also map a schema to a file pattern in their settings, for example all `*.yaml` files under `scenarios/`. Use the same schema files.
