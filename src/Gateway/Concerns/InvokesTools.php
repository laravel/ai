<?php

namespace Laravel\Ai\Gateway\Concerns;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;

trait InvokesTools
{
    protected Closure $invokingToolCallback;

    protected Closure $toolInvokedCallback;

    /**
     * @var array<int, array{invoking: Closure, invoked: Closure}>
     */
    protected array $toolInvocationCallbackStack = [];

    /**
     * Specify callbacks that should be invoked when tools are invoking / invoked.
     */
    public function onToolInvocation(Closure $invoking, Closure $invoked): self
    {
        $this->invokingToolCallback = $invoking;
        $this->toolInvokedCallback = $invoked;

        return $this;
    }

    /**
     * Execute the given tool with the given arguments.
     */
    protected function executeTool(Tool $tool, array $arguments): string
    {
        $callbacks = $this->pushToolInvocationCallbacks();

        try {
            call_user_func($callbacks['invoking'], $tool, $arguments);

            return (string) tap(
                $tool->handle(new Request($arguments)),
                fn ($result) => call_user_func($callbacks['invoked'], $tool, $arguments, $result)
            );
        } finally {
            $this->popToolInvocationCallbacks();
        }
    }

    /**
     * Drop tool calls the model requested on the final turn but that were never executed because the step limit was reached, keeping persisted tool calls and results balanced.
     */
    protected function dropUnfulfilledToolCalls(Collection $steps, Collection $messages): void
    {
        $lastStep = $steps->last();

        if (! $lastStep instanceof Step || blank($lastStep->toolCalls) || filled($lastStep->toolResults)) {
            return;
        }

        $steps->pop();

        $steps->push(new Step(
            $lastStep->text, [], [], $lastStep->finishReason, $lastStep->usage, $lastStep->meta,
        ));

        $lastMessage = $messages->last();

        if ($lastMessage instanceof AssistantMessage && $lastMessage->toolCalls->isNotEmpty()) {
            $messages->pop();

            $messages->push(new AssistantMessage(
                $lastMessage->content ?? '', null, $lastMessage->providerContentBlocks,
            ));
        }
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
    }

    /**
     * Snapshot the current callbacks for the duration of a single tool invocation.
     *
     * @return array{invoking: Closure, invoked: Closure}
     */
    protected function pushToolInvocationCallbacks(): array
    {
        $this->initializeToolCallbacks();

        return $this->toolInvocationCallbackStack[] = [
            'invoking' => $this->invokingToolCallback,
            'invoked' => $this->toolInvokedCallback,
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
    }
}
