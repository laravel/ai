<?php

namespace Laravel\Ai\Streaming\Events;

use Laravel\Ai\Data;

class ToolCall extends StreamEvent
{
    public function __construct(
        public string $id,
        public Data\ToolCall $toolCall,
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
            'type' => 'tool_call',
            'tool_id' => $this->toolCall->id,
            'tool_name' => $this->toolCall->name,
            'arguments' => $this->toolCall->arguments,
            'reasoning_id' => $this->toolCall->reasoningId,
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function toVercelProtocolArray(): ?array
    {
        return [
            'type' => 'tool-input-available',
            'toolCallId' => $this->toolCall->id,
            'toolName' => $this->toolCall->name,
            'input' => $this->toolCall->arguments,
        ];
    }
}
