<?php

namespace Laravel\Ai\Gateway\Concerns;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\ParentInvocation;
use Laravel\Ai\Gateway\RunObservers;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Throwable;

trait InvokesTools
{
    /**
     * Execute the given tool with the given arguments.
     */
    protected function executeTool(Tool $tool, array $arguments, ?string $toolCallId = null, ?RunObservers $observers = null, ?string $invocationId = null): string
    {
        $observers ??= new RunObservers;

        // Minted here so that nested invocations, such as a sub-agent invoked as a tool, each carry their own ID...
        $toolInvocationId = (string) Str::uuid7();

        // Any agent prompted while this tool runs, however it was reached, is a child of this tool call...
        return ParentInvocation::within($invocationId, $toolInvocationId, function () use ($tool, $arguments, $toolCallId, $toolInvocationId, $observers): string {
            $observers->invokingTool($tool, $arguments, $toolInvocationId);

            // Only the handler itself may fail the tool call, so an observer that throws is never reported as a tool failure...
            try {
                $result = $tool->handle(new Request($arguments, $toolCallId, $toolInvocationId));
            } catch (Throwable $exception) {
                $observers->toolFailed($tool, $arguments, $exception, $toolInvocationId);

                throw $exception;
            }

            $observers->toolInvoked($tool, $arguments, $result, $toolInvocationId);

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
}
