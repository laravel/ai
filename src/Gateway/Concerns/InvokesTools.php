<?php

namespace Laravel\Ai\Gateway\Concerns;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\RunContext;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;

trait InvokesTools
{
    /**
     * Execute the given tool with the given arguments.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function executeTool(Tool $tool, array $arguments, ?string $toolCallId = null, ?RunContext $context = null): string
    {
        $toolInvocationId = (string) Str::uuid7();

        $context?->invokingTool($tool, $arguments, $toolInvocationId);

        $result = $tool->handle(new Request($arguments, $toolCallId, $toolInvocationId));

        $context?->toolInvoked($tool, $arguments, $result, $toolInvocationId);

        return (string) $result;
    }

    /**
     * Find a tool by its name from the given tools array.
     */
    protected function findTool(string $name, array $tools): ?Tool
    {
        foreach ($tools as $tool) {
            if ($tool instanceof ToolSearch) {
                if ($nested = $this->findTool($name, $tool->tools)) {
                    return $nested;
                }

                continue;
            }

            if ($tool instanceof Tool && ToolNameResolver::resolve($tool) === $name) {
                return $tool;
            }
        }

        return null;
    }
}
