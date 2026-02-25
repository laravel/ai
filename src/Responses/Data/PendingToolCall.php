<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;

class PendingToolCall implements Arrayable
{
    public readonly string $id;

    public function __construct(
        public readonly Tool $tool,
        public readonly array $arguments,
        public readonly string $agentClass,
        public readonly string $invocationId,
        ?string $id = null,
    ) {
        $this->id = $id ?? (string) Str::uuid7();
    }

    /**
     * Get the tool's name.
     */
    public function toolName(): string
    {
        return method_exists($this->tool, 'name')
            ? $this->tool->name()
            : class_basename($this->tool);
    }

    /**
     * Get the tool's class name.
     */
    public function toolClass(): string
    {
        return $this->tool::class;
    }

    /**
     * Get the array representation of the pending tool call.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tool_name' => $this->toolName(),
            'tool_class' => $this->toolClass(),
            'arguments' => $this->arguments,
            'agent_class' => $this->agentClass,
            'invocation_id' => $this->invocationId,
        ];
    }
}
