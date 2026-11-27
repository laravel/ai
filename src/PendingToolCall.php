<?php

namespace Laravel\Ai;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * A tool call handed to agent tool middleware before the tool runs.
 */
class PendingToolCall
{
    public function __construct(
        public readonly Tool $tool,
        public readonly Request $request,
    ) {}

    /**
     * Get the name the model called the tool by.
     */
    public function name(): string
    {
        return ToolNameResolver::resolve($this->tool);
    }

    /**
     * Get the arguments the model passed to the tool.
     *
     * @return array<string, mixed>
     */
    public function arguments(): array
    {
        return $this->request->all();
    }
}
