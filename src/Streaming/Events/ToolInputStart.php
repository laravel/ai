<?php

namespace Laravel\Ai\Streaming\Events;

class ToolInputStart extends StreamEvent
{
    public function __construct(
        public string $id,
        public string $toolId,
        public string $toolName,
        public int $timestamp,
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'tool_input_start',
            'tool_id' => $this->toolId,
            'tool_name' => $this->toolName,
            'timestamp' => $this->timestamp,
        ];
    }
}
