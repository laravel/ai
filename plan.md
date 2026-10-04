# Standalone sandbox SDK

Build a small, provider-neutral sandbox SDK inside `laravel/ai`. A sandbox is a filesystem and command-execution environment with an explicit lifecycle. Application code can use it from a controller, queued job, command, or service without invoking any AI functionality.

This replaces the conversation-scoped sandbox integration. The existing drivers are reusable; their automatic lifecycle integration is removed now and returns as Phase 2, built on the public SDK.

## Precedent and scope

| | Provider API | Sandbox handle | Lifecycle and state | Capabilities | Network and credentials |
|---|---|---|---|---|---|
| [Sandbank core](https://github.com/chekusu/sandbank/tree/main/packages/core) | `create(config)`, `get(id)`, `list(filter)`, `destroy(id)`, `capabilities` set | `id`, `state`, `createdAt`, `exec`, `readFile`, `writeFile`, `uploadArchive`, `downloadArchive` | states `creating`, `running`, `stopped`, `error`, `terminated`; typed errors (`NotFound`, `StateError`, `ExecTimeout`, `RateLimit`, `CapabilityNotSupported`) | optional interfaces: `exec.stream`, `sleep`/`wake`, `snapshot`, `port.expose`, `terminal`, `volumes`, `services` | `env` on create; `autoDestroyMinutes`; `resources {cpu, memory, disk}` |
| [Flue sandbox API](https://flueframework.com/docs/reference/sandbox-api/) | `createSandbox({ id })`; no teardown verbs in the contract | `exec(command, { cwd, env, timeoutMs, signal })`, `readFile`, `writeFile` (creates parents), `stat`, `readdir`, `exists`, `mkdir`, `rm`, `cwd`, `resolvePath` | owned by the application | adapters add their own | per adapter |
| [Docker Sandboxes](https://docs.docker.com/ai/sandboxes/) | `sbx create --name`, `ls`, `stop`, `rm`, `cp`; name-addressed | microVM, one kernel per sandbox | persists across `stop`; cloud sandboxes expire after one hour by default | templates, `--cloud attach` | deny-by-default outbound, `--allow-network host:port`; API keys injected by a host-side proxy, "credential values never enter the VM"; workspace modes: direct mount, `--clone`, mountless |
| [NVIDIA OpenShell](https://docs.nvidia.com/openshell/) | `sandbox create --name --from <image> --policy`, `rule approve` | container or VM | gateway owns lifecycle | policy is a resource | declarative YAML policy: `filesystem_policy` (`read_only`, `read_write`), `process` (`run_as_user`), `network_policies` per binary, host, port, method and path, credentials bound at the proxy, never in the sandbox |
| [Boat](https://docs.boat.dev/api/v1) | `POST /sandboxes`, `GET /sandboxes/{id}`, `DELETE`, `stop`, `resume`, `fork` | VM with native `bx_` id | 11 states; TTL archive | named snapshots | always networked; `noEnv` keeps account secrets out |
| [Vercel harnesses](https://ai-sdk.dev/docs/ai-sdk-harnesses/overview) | execution environment distinct from the runtime using it | | | | |

Borrow Sandbank's compute layer (provider, handle, states, typed errors, optional capabilities as interfaces) and Flue's file contract. Take from Docker and OpenShell the two rules that every newer sandbox shares: outbound network is an allowlist, and credentials should be injected outside the sandbox rather than placed in its environment. This plan implements the environment only; orchestration, relays, policies as resources and proxies are deferred, but the schema leaves room for them.

The Laravel shape is a facade, a multiple-instance manager, configured providers, contracts, value objects, and a fake.

## Decisions

1. Sandboxes are independent resources. No automatic tools, prompt middleware, approvals, conversation IDs, or conversation persistence in the SDK. The agent integration is Phase 2 and consumes only the public API.
2. The SDK lives in this package under `Laravel\Ai\Sandboxes`; `Laravel\Ai\Facades\Sandbox` is the public entry point. "Provider" is the term, as in Sandbank; it does not clash in practice because sandbox providers are only reached through the `Sandbox` facade.
3. Configuration stays under `ai.default_sandbox` and `ai.sandboxes`.
4. Separate creation from attachment. `create()` always creates a new resource; `get($id)` attaches to an existing one and never provisions a replacement.
5. IDs are the backend's native IDs. The application stores the provider name and ID together. No conversation mapping, internal table, or cache-owned identity.
6. Creation, suspension, resumption, restoration and deletion are explicit. No destructor cleanup, request-end cleanup, automatic suspension, or automatic reset after resource loss.
7. The baseline is exec, files, state and delete. Checkpoints and suspension are optional PHP interfaces, as are future capabilities.
8. Network is an allowlist, expressed the same way on every provider. Providers that cannot enforce a value reject it at `create()` rather than ignore it.
9. `env` values are visible inside the sandbox and documented as such. No `secrets` option yet; proxy-based credential injection is deferred and must not be faked with env vars.
10. No automatic failover or cross-provider migration. Images, IDs and checkpoints are not portable.
11. Local execution is for trusted workloads. Write confinement is not isolation from host credentials or host-file reads.
12. First release: local, Docker, Boat, E2B, Daytona, Fly.io Machines, Cloudflare (through the official bridge Worker), Upstash Box, BoxLite and fake providers. Every Sandbank backend except Sandbank Cloud, plus Boat and Upstash. Providers that cannot stream reject `onOutput`; checkpoints exist only where the backend can restore them (local, Docker, Boat, Upstash, BoxLite).

## Public API

```php
use Laravel\Ai\Facades\Sandbox;

$provider = Sandbox::provider('docker');

$sandbox = $provider->create([
    'image' => 'node:22',
    'env' => ['NODE_ENV' => 'test'],
    'network' => ['registry.npmjs.org:443'],
]);

try {
    $sandbox->write('index.js', 'console.log("Hello from Laravel")');

    $result = $sandbox->exec('node index.js', timeout: 30);

    if (! $result->successful()) {
        throw new RuntimeException($result->stderr);
    }

    $output = $result->stdout;
} finally {
    $provider->delete($sandbox->id());
}
```

Default-provider shortcuts:

```php
$sandbox = Sandbox::create();
$sandbox = Sandbox::get($id);
Sandbox::delete($id);
```

Persistent use is owned by the application:

```php
$provider = Sandbox::provider($workspace->sandbox_provider);
$sandbox = $provider->get($workspace->sandbox_id);

$sandbox->write('notes.txt', 'Keep this between requests');
```

Working on an existing directory with the local provider, the "work on this app" case:

```php
$sandbox = Sandbox::provider('local')->get(base_path());
```

A local ID is either a ULID of a workspace under the configured root, or an absolute path to a directory that already exists. `get()` with a path attaches to that directory as the root and never creates it. Only the local provider accepts paths.

Store `$sandbox->id()` and the provider name immediately after creation. `get()` must not replace a lost sandbox with an empty one. A stopped sandbox is not silently resumed; use `Suspendable::resume()` where supported.

### Create options

One documented key set; each provider declares which keys it supports and rejects the rest with `UnsupportedOptionException` before provisioning.

| Key | Meaning | local | docker | boat |
|---|---|---|---|---|
| `image` | backend image or template | no | yes | named snapshot via `from` |
| `env` | variables visible to every command | yes | yes | yes |
| `workdir` | directory the sandbox starts in | no | yes | no (`/home/user`) |
| `cpus`, `memory` | resource limits | no | yes | `type` small/default/large |
| `ttl` | seconds of inactivity before the backend stops it | no | no | yes |
| `network` | `false`, `true`, or an allowlist of `host:port` | `false`/`true` | `false`/`true` | `true` only |
| `isolate` | OS-level write confinement for host commands | yes | no | no |

The allowlist form is the schema Docker Sandboxes and OpenShell converge on. No first-release provider enforces it; they reject it, so adding a proxy later changes behaviour nowhere else.

## Configuration

Keep the current `ai.sandboxes` entries as provider defaults; create options override them per resource.

- Local: root, command timeout, environment allowlist, write confinement, network policy.
- Docker: image, workdir, binary, memory, CPUs, network, env, command timeout.
- Boat: URL, API key, machine type, env, TTL, provisioning timeout, command timeout.

Remove `suspend_after_turn`. Local `create()` always makes a workspace under root; attaching to another directory goes through `get($path)`. Docker stays network-disabled by default. Boat's TTL is a backend setting, not an SDK guarantee.

## Architecture

### Facade and manager

`src/Facades/Sandbox.php` resolves `SandboxManager` independently of `AiManager`.

`src/Sandboxes/SandboxManager.php` extends `MultipleInstanceManager` and exposes `provider(?string $name = null): SandboxProvider`, default-provider forwarding for `create()`, `get()` and `delete()`, `extend()` for custom providers, and `fake(array $files = [])`.

Constructing a manager or provider performs no provisioning. `create()` and `get()` do their backend work immediately; the deferred wrapper and request-bound resolution go.

### Provider contract

Replace `SandboxFactory` with `src/Contracts/Sandbox/SandboxProvider.php`:

```php
interface SandboxProvider
{
    public function create(array $options = []): Sandbox;
    public function get(string $id): Sandbox;
    public function delete(string $id): void;
    public function lock(string $id, int $seconds): Lock;
}
```

`lock()` returns a `Cache::lock("ai:sandbox:{provider}:{id}", $seconds)` from the configured store. The SDK never takes it; it is the one key the application and Phase 2 share, so a caller's lease and the agent layer's lease cannot interleave. Providers inherit it from an abstract `Provider` base.

Rename `LocalFactory`, `DockerFactory`, `BoatFactory`, `FakeFactory` to `LocalProvider`, `DockerProvider`, `BoatProvider`, `FakeProvider`.

- Local: `create()` makes `{root}/{ulid}`; `get()` requires the directory to exist, by ULID under root or by absolute path.
- Docker: `create()` names the container and volume `ai-sandbox-{ulid}`; `get()` inspects without recreating or starting.
- Boat: `create()` returns the `bx_` ID; `get()` queries it. `Idempotency-Key` on create.
- Fake: distinct in-memory resources with the same semantics.

Deletion is part of the baseline, not a `ForgetsSandboxes` capability. Deleting a missing resource succeeds; transport and auth failures throw. Each provider documents what deletion removes. Boat's accepted background deletion is reported as accepted, not completed.

### Sandbox handle and driver

Keep `src/Sandboxes/Sandbox.php` as the final handle: provider name, ID, driver, root, cwd. Keep `SandboxDriver` for exec and files on normalized absolute paths.

Handle operations: `id()`, `state()`, `cwd()`, `withCwd($path)`, `exec($command, timeout:, env:, onOutput:)`, `read()`, `write()`, `stat()`, `exists()`, `readdir()`, `mkdir()`, `rm()`.

`state()` returns `SandboxState`: `Creating`, `Running`, `Stopped`, `Error`, `Terminated`, Sandbank's five, which every backend maps onto (Boat's `archived` is `Stopped`, Docker's exited container is `Stopped`, a local directory is always `Running`). It is a live query, not a cached field.

Root and cwd are separate. `withCwd('repo')` moves command execution and relative-path resolution, not the security boundary. Normalized paths stay inside the root. The file API boundary does not constrain shell commands.

`ShellResult`: stdout, stderr, exitCode, timedOut, `successful()`. `FileStat`: isFile, isDirectory, size, mtime. Command failures return a result; missing resources and transport failures throw.

`write()` creates missing parents by checking `stat(dirname)` first, not by catching every exception. Files are never truncated by the SDK.

### Streaming commands

`?Closure $onOutput = null` on `Sandbox::exec()` and `SandboxDriver::exec()`, receiving `(string $type, string $chunk)` with `stdout` or `stderr`.

```php
$result = $sandbox->exec('npm test', timeout: 120,
    onOutput: fn (string $type, string $chunk) => logger()->debug($chunk, ['stream' => $type]));
```

Synchronous; returns the final `ShellResult`. Ordering is preserved within a stream, not between streams. A backend that cannot stream rejects `onOutput` before running. A callback exception aborts the command and attempts cancellation; document where remote termination cannot be guaranteed. Detached processes, terminals and stdin are deferred.

### Optional capabilities

PHP interfaces, checked with `instanceof`:

```php
if ($provider instanceof Checkpointable) {
    $checkpoint = $provider->checkpoint($sandbox->id());
    $provider->restore($sandbox->id(), $checkpoint);
    $provider->forgetCheckpoint($sandbox->id(), $checkpoint);
}

if ($provider instanceof Suspendable) {
    $provider->suspend($sandbox->id());
    $provider->resume($sandbox->id());
}
```

`Suspendable` replaces `suspendsAfterTurn()` with `resume()`. Checkpoints are opaque and provider-specific; reacquire the handle after `restore()`. Future capabilities follow the same pattern, in Sandbank's order of demand: `ExposesPorts`, `ListsSandboxes`, `Terminal`, `Volumes`. Not added now.

### Errors

`SandboxException` (provider, id) as the base. `SandboxNotFound` for a missing resource on `get()`, `exec()` or files (replaces `SandboxDied`), `SandboxStateException` (current and required state, for `resume()` on a running sandbox and `exec()` on a stopped one), `UnsupportedOptionException`, `SandboxPathException`. `SandboxBusy` goes with the lock. Timeouts stay a `ShellResult`.

## Lifecycle, concurrency, and security

- Creation is not attachment; attachment is not recreation or resume.
- Startup and snapshot waits have explicit deadlines.
- No automatic retry of mutating commands. Use backend idempotency keys where documented.
- The application owns retention, authorization and ID storage. No pruning command yet.
- The SDK takes no locks. `$provider->lock($id, $seconds)` is the shared key for callers that need one; the lease must cover the whole operation.
- Validate IDs and env variable names. Never interpolate paths into shell commands without escaping.
- Local env filtering prevents accidental inheritance, not access to secrets on disk. Local confinement restricts writes only.
- Container and VM confinement is the backend boundary; path checks are an API guard.
- No SDK event bus in the first release; Sandbank's observer is the precedent if one is added.

## Remove the old integration

Delete `src/Attributes/Sandbox.php`, `src/Middleware/ResolveSandbox.php`, `src/Tools/Sandbox/`, `src/Events/SandboxResolved.php`, `ForgetsSandboxes`, `SandboxFactory`, `tests/Feature/Sandboxes/SandboxAgentTest.php` and its fixtures.

Remove the sandbox additions from `AgentPrompt`, `RememberConversation`, `GeneratesText`, `AiManager`, `Ai`, and the deferred and lock helpers from `Sandbox`. Review `StreamableAgentResponse::finally()` separately and keep it if it stands on its own. Register the manager in `AiServiceProvider`.

## Faking

```php
$fake = Sandbox::fake(['README.md' => '# Hello']);
$fake->onExec('npm test', new ShellResult('1 passed', '', 0));

$sandbox = Sandbox::create();
$sandbox->exec('npm test');

$fake->assertExecuted('npm test');
$fake->assertFile('result.txt', fn ($contents) => $contents === 'done');

Sandbox::delete($sandbox->id());
$fake->assertDeleted($sandbox->id());
```

Per-resource files, history, options and state. Assertions scoped by ID: `assertCreated`, `assertExecuted`, `assertNothingExecuted`, `assertWrote`, `assertFile`, `assertDeleted`, `assertSuspended`. Unscripted commands fail loudly. Script chunks, timeouts, resource loss and transport errors. A missing fake resource fails `get()`.

## Phase 1: the SDK

### 1. Decouple and expose

Facade, `SandboxProvider`, manager, service provider, renames, removal of the integration above.

Tests: `tests/Feature/Sandboxes/SandboxManagerTest.php` (default and named providers, `extend()`, facade); existing suites unchanged in behaviour.

### 2. Explicit lifecycle

`LocalProvider`, `DockerProvider`, `BoatProvider`, `FakeProvider`; `state()`, `lock()`, errors, option validation.

Tests: `LocalSandboxTest` (ULID and path attachment, missing directory), `DockerSandboxTest`, `BoatSandboxTest` (idempotency, deletion errors, no cache mapping), new `FakeSandboxTest`. Cover distinct creations, attachment in a fresh request, missing-resource attachment, suspend and resume, repeated deletion, rejected options.

### 3. Files and commands

`Sandbox`, `SandboxDriver`, drivers, results, capabilities, streaming.

Tests: new `SandboxTest` (root and cwd separation, path validation); provider suites for binary round trips, parent creation, exit codes, env, timeout cancellation, streaming; checkpoint and suspension tests as explicit provider calls.

### 4. Fake parity and docs

`FakeProvider`, `FakeDriver`, facade annotations, `config/ai.php`, docs PR.

Done when a Laravel service creates a sandbox, writes and runs a file, stores provider and ID, reattaches in a fresh request, reads the file back and deletes it, and the same passes against the fake.

## Phase 2: agent integration on the SDK

Rebuilt as a consumer of the public API only, after Phase 1 ships.

- `#[Sandbox('docker', approveCommands: true)]` on an agent. On the first tool call of a turn, `Sandbox::provider($name)->create($options)`; the provider name and ID are stored on the conversation (meta on `agent_conversations`, one migration), and later turns call `get()`. Agents without `RemembersConversations` create a sandbox per turn and delete it afterwards.
- `Bash`, `Read`, `Write`, `Edit`, `Grep`, `Glob` tools over the handle, appended by the middleware. `Bash` is `Approvable` by default.
- Middleware holds `$provider->lock($id, $timeout)` for the turn and releases it after any `suspend()`; `SandboxBusy` is thrown on contention. `suspend_after_turn` returns here as middleware policy, defaulting to true for `Suspendable` providers.
- `Sandbox::fake()` drives the agent tests the way `SandboxAgentTest` did.
- Harness providers (`plan-harness.md`) build on this layer: a `Harness` provider reads the handle from the prompt and runs its CLI through `exec()`, and `get(base_path())` is the "work on this app" case.

Tests: `SandboxAgentTest` returns, written against the SDK: first-turn creation, reattachment, approval pause and resume, lock release on finished and abandoned streams, per-turn deletion for forgetful agents.

## Deferred

- More providers: Sprites, Docker Sandboxes (`sbx`), OpenShell, Modal. Verify each against its official API first; Sprites documents POST for create and PUT for update.
- Live runs of the HTTP providers; they are tested against faked responses built from each official spec or SDK.
- E2B and Daytona checkpoints: both snapshot, but restore-by-snapshot and snapshot deletion were not verifiable from their specs.
- Network allowlist enforcement through a proxy, and proxy-injected credentials (Docker and OpenShell's model). The `network` option already carries the shape.
- Policy as a resource (OpenShell's filesystem, process and network rules).
- Port exposure, listing, terminals, volumes, archive upload and download, detached execution.
- Workspace sync or migration between providers.
- Automatic retention or a package-owned registry.
