# Harness agents

A harness agent runs Claude Code as a separate process. Claude Code owns the tool loop, filesystem access, and session history. A harness agent is separate from a Laravel AI `Agent` and does not use a provider gateway.

## Getting started

Install Claude Code on the machine running PHP. Configure `ANTHROPIC_API_KEY`, or configure Claude Code authentication for the process user. Install `laravel/mcp` when exposing application tools or requesting approvals:

```sh
composer require laravel/mcp
php artisan make:harness-agent CodingAssistant
```

```php

\
```

`instructions()` defaults to an empty string. Working directory, model, and permission mode otherwise come from `ai.harnesses.claude-code`. `#[Timeout]` defaults to 120 seconds. Model names and aliases pass directly to Claude Code.

The configuration defaults to `PermissionMode::BypassPermissions`, which lets Claude Code act without approval. `PermissionMode::AcceptEdits` lets the CLI automatically accept edits; `PermissionMode::Default` uses its normal permission rules. CLI settings can also allow or deny native tools. This is filesystem access by the PHP process user, not an isolated sandbox.

## Prompting and sessions

```php
$agent = new CodingAssistant;
$response = $agent->prompt('Inspect the authentication flow.');

$response->text;
$response->usage;
$response->meta;
$response->toolCalls;
$response->pendingApprovals;
$response->finishReason;
$response->sessionId;

$next = $agent->prompt('Explain the login controller.', $response->sessionId);
```

Responses implement `Stringable`, `Arrayable`, and `JsonSerializable`. Serialized responses contain `session_id`; the same ID is available through `$response->meta->sessionId`.

The first turn allocates a UUID. Pass that UUID back to resume; omitting it starts a new session. The application must persist the ID and authorize access to it. Resume on the same machine, with the same working directory and Claude configuration directory. Only one turn may execute per session at a time. Laravel's conversation tables are not used.

## Streaming

```php
return (new CodingAssistant)
    ->stream('Inspect the authentication flow.')
    ->usingVercelDataProtocol();
```

Alternatively call `usingAgentUserInteractionProtocol()`, return the unmodified stream for native SSE events, or iterate the response:

```php
foreach ($agent->stream('Explain the project.', $sessionId) as $event) {
    logger()->debug($event->type(), $event->toArray());
}
```

Native `StreamEnd` events contain `meta.session_id`. Vercel's terminal `finish` frame contains `messageMetadata.sessionId`; AG-UI's `RUN_FINISHED` contains `metadata.sessionId`, including approval interruptions. Text and reasoning use incremental CLI messages. Tool calls retain native names, except `mcp__laravel__YourTool`, which maps to `YourTool`.

## Application tools

Return Laravel AI `Tool` instances from `tools()`:

```php
public function tools(): iterable
{
    return [new WriteNote];
}
```

The bridge runs `php artisan ai:harness-mcp` in a subprocess. It resolves the named agent through Laravel's container and calls `tools()` again. Agents must therefore be container-resolvable, and tool configuration must be reproducible in that subprocess. Request-local constructor state, anonymous tools, and `AgentTool` are unsupported. Tools need unique names containing letters, digits, underscores, or hyphens; `approve` is reserved. A tool's `name()` method is used when present, otherwise its class basename is used.

`InvokingTool` and `ToolInvoked` fire in the Artisan subprocess. Tool handlers receive a `Tools\Request` with a local tool invocation ID. The permission callback does not provide a stable native tool-call ID to the handler.

## Approvals

Tools implementing `Approvable` require a permission mode other than `BypassPermissions`. Their `shouldRequestApproval()` check also runs inside the bridge, even if CLI settings auto-allow that MCP tool.

When permission is needed, the bridge stores a pending approval and denies execution. Claude receives a message asking it to end the turn. The CLI may continue narrating or attempt other tools before it exits; denial is not a guaranteed immediate process stop. The response contains pending approvals and a `tool_approval` finish reason.

```php
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;

$response = $agent->prompt(
    Decisions::from([
        $approvalId => Decision::approve(),
    ]),
    sessionId: $sessionId,
);
```

Use `Decision::reject('Reason')` to deny a call or `Decision::edit(['text' => 'Replacement'])` to change its arguments. `Decision::approveAll()` and `Decision::rejectAll()` resolve every pending call. Decisions can also be passed to `stream()`.

Resumption adds a user message describing the decisions. Claude must issue the tool call again, so each approval requires another model round trip. Approval matches the agent, runtime, session, tool name, and exact JSON input. Different arguments request approval again, except the explicitly edited input. Grants last for the resumed turn; repeated identical calls in that turn share the grant. Unknown or expired approval IDs are rejected. The `approval_ttl` setting defaults to 3600 seconds.

`ToolApprovalRequested` and `ToolApprovalResolved` fire in the calling PHP process. The latter reports permission decisions, not the later tool execution result. Streaming emits `ToolApprovalRequest`.

## Configuration and deployment

The `ai.harnesses` entry supports `binary`, `key`, `cwd`, `model`, `permission_mode`, `max_turns`, `env`, `approval_ttl`, and `cache_store`.

- `binary` defaults to `claude`; override it with `CLAUDE_CODE_BINARY`.
- `cwd` defaults to the Laravel application directory; override it with `CLAUDE_CODE_CWD`.
- `key` is forwarded as `ANTHROPIC_API_KEY` when set.
- `env` merges additional environment variables for OAuth or gateway configuration.
- `max_turns` defaults to 50 and is passed to Claude Code.
- `cache_store` defaults to the application cache store. MCP requires a shared store such as file, database, or Redis, configured identically in PHP and Artisan. Array and null stores cannot transport runs between processes. Session locking requires atomic cache locks.

PHP must be able to start processes, invoke the CLI PHP binary and application `artisan` file, and write to the workspace and Claude's session directory. Claude normally stores sessions under the process user's home directory. The library does not change `HOME`; configure a writable Claude configuration directory in `env` when needed. Keep session files across deployments if callers will resume them. Application tools execute with the Artisan application's permissions and configuration.

Errors, unsuccessful CLI exits, malformed output, and timeouts emit a stream error and throw `HarnessException`. Its `sessionId` identifies the interrupted session. Timeouts stop the process. Resuming an interrupted session delegates transcript recovery to Claude Code; the library does not silently create a new session or replay potentially completed side effects.

CLI compatibility is checked against Claude Code 2.1.263 using a local mock API. Incremental output, appended instructions, native tool calls, and session resumption have been exercised without live model calls. A transcript truncated after an assistant tool call was also resumed successfully with the same session ID. The parser also has synthetic fixtures for reasoning and tool events. See the [Claude Code CLI reference](https://code.claude.com/docs/en/cli-reference) for deployment flags.

## Testing

```php
use Laravel\Ai\Ai;

$fake = Ai::fakeHarness(['Inspection complete.', 'The login controller validates credentials.']);

$response = (new CodingAssistant)->prompt('Inspect the app.');

$fake->assertRunCount(1);
$fake->assertRan(fn ($run) => $run->prompt === 'Inspect the app.');
```

Each response may be a string, an array of stream events, or a callback receiving `HarnessRun` and returning either. An empty fake returns empty text. Fakes never start Claude Code. Use scripted `ToolApprovalRequest` events when testing approval and resume flows; subprocess approval behavior is tested through the MCP bridge.

## Supported surface

Harness agents support instructions, tools, working directories, permission modes, `prompt()`, and `stream()`. `#[Harness('configured-name')]` selects another entry from `ai.harnesses`; `Enums\Harness::ClaudeCode` is also accepted by the attribute and `Ai::harness()`.

Provider failover, `schema()`, middleware, `RemembersConversations`, `AgentTool` composition, queues, and broadcasting methods are not part of this class. Only `#[Harness]`, `#[Model]`, and `#[Timeout]` configure harness behavior. Regular Laravel AI agents and provider gateways continue to work independently.
