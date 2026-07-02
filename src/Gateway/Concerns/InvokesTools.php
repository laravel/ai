<?php

namespace Laravel\Ai\Gateway\Concerns;

use Closure;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Throwable;

trait InvokesTools
{
    protected Closure $invokingToolCallback;

    protected Closure $toolInvokedCallback;

    protected Closure $toolFailedCallback;

    /**
     * @var array<int, array{invoking: Closure, invoked: Closure, failed: Closure}>
     */
    protected array $toolInvocationCallbackStack = [];

    /**
     * Specify callbacks that should be invoked when tools are invoking / invoked / failed.
     */
    public function onToolInvocation(Closure $invoking, Closure $invoked, ?Closure $failed = null): self
    {
        $this->invokingToolCallback = $invoking;
        $this->toolInvokedCallback = $invoked;
        $this->toolFailedCallback = $failed ?? fn () => true;

        return $this;
    }

    /**
     * Execute the given tool with the given arguments.
     */
    protected function executeTool(Tool $tool, array $arguments): string
    {
        $callbacks = $this->pushToolInvocationCallbacks();
        $id = (string) Str::uuid7();

        try {
            call_user_func($callbacks['invoking'], $tool, $arguments, $id);

            return (string) tap(
                $tool->handle(new Request($arguments)),
                fn ($result) => call_user_func($callbacks['invoked'], $tool, $arguments, $result, $id)
            );
        } catch (Throwable $e) {
            call_user_func($callbacks['failed'], $tool, $arguments, $e, $id);

            throw $e;
        } finally {
            $this->popToolInvocationCallbacks();
        }
    }

    /**
     * Determine whether the configured concurrency driver can run in the current environment.
     */
    protected function canRunInParallel(): bool
    {
        $driver = config('concurrency.default')
            ?? config('concurrency.driver')
            ?? 'process';

        return match ($driver) {
            'fork' => PHP_SAPI === 'cli'
                && extension_loaded('pcntl')
                && class_exists('Spatie\\Fork\\Fork'),
            default => true,
        };
    }

    /**
     * Find a tool by its name from the given tools array.
     */
    protected function findTool(string $name, array $tools): ?Tool
    {
        foreach ($tools as $tool) {
            if ($tool instanceof Tool && ToolNameResolver::resolve($tool) === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Initialize the tool invocation callbacks.
     */
    protected function initializeToolCallbacks(): void
    {
        $this->invokingToolCallback ??= fn () => true;
        $this->toolInvokedCallback ??= fn () => true;
        $this->toolFailedCallback ??= fn () => true;
    }

    /**
     * Snapshot the current callbacks for the duration of a single tool invocation.
     *
     * @return array{invoking: Closure, invoked: Closure, failed: Closure}
     */
    protected function pushToolInvocationCallbacks(): array
    {
        $this->initializeToolCallbacks();

        return $this->toolInvocationCallbackStack[] = [
            'invoking' => $this->invokingToolCallback,
            'invoked' => $this->toolInvokedCallback,
            'failed' => $this->toolFailedCallback,
        ];
    }

    /**
     * Restore the callbacks that were active before the current tool invocation.
     */
    protected function popToolInvocationCallbacks(): void
    {
        $callbacks = array_pop($this->toolInvocationCallbackStack);

        if ($callbacks === null) {
            return;
        }

        $this->invokingToolCallback = $callbacks['invoking'];
        $this->toolInvokedCallback = $callbacks['invoked'];
        $this->toolFailedCallback = $callbacks['failed'];
    }
}
