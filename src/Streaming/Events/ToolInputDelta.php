<?php

namespace Laravel\Ai\Streaming\Events;

class ToolInputDelta extends StreamEvent
{
    public function __construct(
        public string $id,
        public string $toolId,
        public string $delta,
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
            'type' => 'tool_input_delta',
            'tool_id' => $this->toolId,
            'delta' => $this->delta,
            'timestamp' => $this->timestamp,
        ];
    }
}
