<?php

namespace Laravel\Ai\Harness;

use Generator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Attributes\Harness;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolApprovalResolved;
use Laravel\Ai\Harness\Approvals\ApprovalStore;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Tools\AgentTool;
use LogicException;
use ReflectionClass;

use function Laravel\Ai\ulid;

/** Approval resumption requires another model round trip and matches exact tool input. */
abstract class HarnessAgent
{
    public function instructions(): string
    {
        return '';
    }

    /** @return iterable<Tool> */
    public function tools(): iterable
    {
        return [];
    }

    public function workingDirectory(): string
    {
        return config('ai.harnesses.'.$this->harnessName().'.cwd', base_path());
    }

    public function permissionMode(): PermissionMode
    {
        return PermissionMode::from(config('ai.harnesses.'.$this->harnessName().'.permission_mode', 'bypassPermissions'));
    }

    public function prompt(string|Decisions $prompt, ?string $sessionId = null): HarnessResponse
    {
        $run = $this->makeRun($prompt, $sessionId);
        $generator = $this->execute($run, $prompt);

        foreach ($generator as $event) {
        }

        return HarnessResponse::fromResult($generator->getReturn());
    }

    public function stream(string|Decisions $prompt, ?string $sessionId = null): StreamableAgentResponse
    {
        $run = $this->makeRun($prompt, $sessionId);
        $meta = new Meta($run->harness, $run->model, sessionId: $run->sessionId);

        return new StreamableAgentResponse($run->id, function () use ($run, $prompt, $meta) {
            $result = yield from $this->execute($run, $prompt);
            $meta->model = $result->meta->model;
            $meta->sessionId = $result->sessionId;
        }, $meta);
    }

    protected function execute(HarnessRun $run, string|Decisions $prompt): Generator
    {
        $runtime = Ai::harness($run->harness);
        $store = $this->approvalStore($run);
        $lock = $store->lock('ai:harness:session:'.$run->sessionId, $run->timeout + 30);

        if (! $lock->get()) {
            throw new LogicException('This harness session already has an active turn.');
        }

        try {
            if ($prompt instanceof Decisions) {
                $resolved = $store->resolve($run, $prompt);
                $run->prompt = 'Continue the previous task using these tool approval decisions: '.json_encode($resolved->toArray(), JSON_THROW_ON_ERROR);
                event(new ToolApprovalResolved($run->id, $this, $resolved, $run->sessionId));
            }

            $generator = $runtime->run($run);

            foreach ($generator as $event) {
                if ($event instanceof ToolApprovalRequest) {
                    $store->remember($run, $event->pendingApprovals);
                    event(new ToolApprovalRequested($run->id, $this, $event->pendingApprovals, $run->sessionId));
                }

                yield $event->withInvocationId($run->id);
            }

            return $generator->getReturn();
        } finally {
            try {
                $store->clearDecisions($run);
            } finally {
                $lock->release();
            }
        }
    }

    protected function makeRun(string|Decisions $prompt, ?string $sessionId): HarnessRun
    {
        if ($sessionId !== null && ! Str::isUuid($sessionId)) {
            throw new LogicException('Harness session IDs must be UUIDs.');
        }

        if ($prompt instanceof Decisions && $sessionId === null) {
            throw new LogicException('A session ID is required to resume tool approvals.');
        }

        $tools = [];

        foreach ($this->tools() as $tool) {
            if (! $tool instanceof Tool || $tool instanceof AgentTool) {
                throw new LogicException('Harness tools must implement Tool and may not be AgentTool instances.');
            }

            if ($tool instanceof Approvable && $this->permissionMode() === PermissionMode::BypassPermissions) {
                throw new LogicException('Approvable harness tools require a permission mode other than BypassPermissions.');
            }

            $name = method_exists($tool, 'name') ? $tool->name() : class_basename($tool);

            if (! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $name) || $name === 'approve' || isset($tools[$name]) || (new ReflectionClass($tool))->isAnonymous()) {
                throw new LogicException('Harness tools require unique MCP-compatible names and named classes.');
            }

            $tools[$name] = $tool::class;
        }

        if ($tools !== [] && (new ReflectionClass($this))->isAnonymous()) {
            throw new LogicException('Harness agents exposing tools must have a named, container-resolvable class.');
        }

        $name = $this->harnessName();
        $timeout = $this->attribute(Timeout::class)?->value ?? 120;

        if ($timeout < 1) {
            throw new LogicException('Harness timeouts must be positive.');
        }

        return new HarnessRun(
            static::class, $this->instructions(), is_string($prompt) ? $prompt : '',
            $this->attribute(Model::class)?->value ?? config('ai.harnesses.'.$name.'.model', 'sonnet'),
            $sessionId ?? (string) Str::uuid(), $this->workingDirectory(), $this->permissionMode(), $tools,
            $timeout, ulid(), $name, $sessionId !== null,
        );
    }

    protected function harnessName(): string
    {
        $value = $this->attribute(Harness::class)?->value ?? 'claude-code';

        return $value instanceof \Laravel\Ai\Enums\Harness ? $value->value : $value;
    }

    protected function attribute(string $class): ?object
    {
        $attributes = (new ReflectionClass($this))->getAttributes($class);

        return isset($attributes[0]) ? $attributes[0]->newInstance() : null;
    }

    protected function approvalStore(HarnessRun $run): ApprovalStore
    {
        return new ApprovalStore(
            Cache::store(config('ai.harnesses.'.$run->harness.'.cache_store')),
            config('ai.harnesses.'.$run->harness.'.approval_ttl', 3600),
        );
    }
}
