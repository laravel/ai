<?php

namespace Laravel\Ai\Gateway\Concerns;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\ParentInvocation;
use Laravel\Ai\Gateway\RunContext;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Throwable;

trait InvokesTools
{
    /**
     * Execute the given tool with the given arguments.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function executeTool(Tool $tool, array $arguments, ?string $toolCallId = null, ?RunContext $context = null): string
    {
        // Minted here so that nested invocations, such as a sub-agent invoked as a tool, each carry their own ID...
        $toolInvocationId = (string) Str::uuid7();

        // Any agent prompted while this tool runs, however it was reached, is a child of this tool call...
        return ParentInvocation::within($context?->invocationId, $toolInvocationId, function () use ($tool, $arguments, $toolCallId, $toolInvocationId, $context): string {
            $context?->invokingTool($tool, $arguments, $toolInvocationId);

            $startedAt = hrtime(true);

            // Only the handler itself may fail the tool call, so a listener that throws is never reported as a tool failure...
            try {
                $result = $tool->handle(new Request($arguments, $toolCallId, $toolInvocationId));
            } catch (Throwable $exception) {
                $context?->toolFailed($tool, $arguments, $exception, $toolInvocationId, $this->elapsedMilliseconds($startedAt));

                throw $exception;
            }

            $context?->toolInvoked($tool, $arguments, $result, $toolInvocationId, $this->elapsedMilliseconds($startedAt));

            return (string) $result;
        });
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
     * Get the milliseconds elapsed since the given monotonic nanosecond reading.
     */
    protected function elapsedMilliseconds(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1e6;
    }
}
