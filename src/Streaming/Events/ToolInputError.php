<?php

namespace Laravel\Ai\Streaming\Events;

class ToolInputError extends StreamEvent
{
    public function __construct(
        public string $id,
        public string $toolId,
        public string $toolName,
        public string $input,
        public string $error,
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
            'type' => 'tool_input_error',
            'tool_id' => $this->toolId,
            'tool_name' => $this->toolName,
            'input' => $this->input,
            'error' => $this->error,
            'timestamp' => $this->timestamp,
        ];
    }
}
