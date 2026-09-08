`captured.jsonl` and `captured-tools.jsonl` were captured from Claude Code 2.1.263 using a local mock Anthropic API. Session UUIDs and the working directory were sanitized; event UUIDs were removed. They exercise the CLI's actual event ordering without a live model call.

`partial.jsonl` is a synthetic stream covering reasoning, host tool calls, and error results. `replay` is an executable PHP fake CLI used for process, timeout, and MCP subprocess tests.
