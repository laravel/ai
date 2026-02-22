<?php

namespace Laravel\Ai;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\DeferredToolQueued;
use Laravel\Ai\Jobs\RunDeferredTool;
use Laravel\Ai\Tools\Request;
use RuntimeException;

class DeferredToolManager
{
    public function __construct(protected Dispatcher $events) {}

    public function defer(Tool $tool, array $arguments): array
    {
        $toolCallId = (string) Str::uuid7();
        $toolClass = $this->toolClass($tool);

        RunDeferredTool::dispatch($toolClass, $arguments, $toolCallId);

        $this->events->dispatch(new DeferredToolQueued($toolClass, $arguments, $toolCallId));

        return [
            'status' => 'pending',
            'tool_call_id' => $toolCallId,
        ];
    }

    public function resume(Tool|string $tool, array $arguments, string $toolCallId): mixed
    {
        if (is_string($tool)) {
            $tool = app($tool);
        }

        if (! $tool instanceof Tool) {
            throw new RuntimeException('Deferred tool class must implement '.Tool::class.'.');
        }

        return $tool->handle(new Request($arguments));
    }

    /**
     * Resolve the tool class used for deferred execution.
     */
    protected function toolClass(Tool $tool): string
    {
        $class = $tool::class;

        if (str_contains($class, '@anonymous')) {
            throw new RuntimeException('Deferred tools must be concrete classes resolvable by the container.');
        }

        return $class;
    }
}
