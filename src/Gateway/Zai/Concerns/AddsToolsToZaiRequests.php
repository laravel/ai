<?php

namespace Laravel\Ai\Gateway\Zai\Concerns;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Zai\ZaiTool;
use Laravel\Ai\Tools\Request as ToolRequest;

trait AddsToolsToZaiRequests
{
    protected $invokingToolCallback;

    protected $toolInvokedCallback;

    /**
     * Add tools to Z.AI request.
     * Converts Laravel Tool array to Z.AI format and adds tool_choice.
     */
    protected function addTools(array $request, array $tools): array
    {
        if (empty($tools)) {
            return $request;
        }

        $request['tools'] = new Collection($tools)
            ->map(fn ($tool) => ZaiTool::toZaiFormat($tool))
            ->values()
            ->all();

        $request['tool_choice'] = 'auto';

        return $request;
    }

    /**
     * Invoke a tool with given arguments.
     * Triggers invokingToolCallback before execution and toolInvokedCallback after.
     */
    protected function invokeTool(Tool $tool, array $arguments): string
    {
        call_user_func($this->invokingToolCallback, $tool, $arguments);

        return (string) tap(
            $tool->handle(new ToolRequest($arguments)),
            fn ($result) => call_user_func($this->toolInvokedCallback, $tool, $arguments, $result)
        );
    }

    /**
     * Execute multiple tools and collect results.
     */
    protected function executeTools(array $toolCallData): array
    {
        $results = [];

        foreach ($toolCallData as $toolData) {
            $tool = $this->findToolByName($toolData['name']);

            if (! $tool) {
                continue;
            }

            $arguments = is_string($toolData['arguments'])
                ? json_decode($toolData['arguments'], true)
                : $toolData['arguments'];

            if (! is_array($arguments)) {
                $arguments = [];
            }

            $result = $this->invokeTool($tool, $arguments);

            $results[] = [
                'toolCallId' => $toolData['id'],
                'toolName' => $toolData['name'],
                'args' => $arguments,
                'result' => $result,
            ];
        }

        return $results;
    }

    /**
     * Find a tool by name from current tools array.
     */
    protected function findToolByName(string $name): ?Tool
    {
        foreach ($this->currentTools ?? [] as $tool) {
            $toolName = class_basename($tool);

            if ($toolName === $name) {
                return $tool;
            }
        }

        return null;
    }
}
