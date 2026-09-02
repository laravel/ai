<?php

namespace Laravel\Ai\Streaming\Events;

use Laravel\Ai\Responses\Data;

class ToolResult extends StreamEvent
{
    public function __construct(
        public string $id,
        public Data\ToolResult $toolResult,
        public bool $successful,
        public ?string $error,
        public int $timestamp,
        public bool $denied = false,
        public ?string $preliminaryOutput = null,
    ) {
        //
    }

    /**
     * Determine if this is a preliminary result for a tool that is still running.
     */
    public function preliminary(): bool
    {
        return $this->preliminaryOutput !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'tool_result',
            'tool_id' => $this->toolResult->id,
            'tool_name' => $this->toolResult->name,
            'result' => $this->toolResult->result,
            'successful' => $this->successful,
            'error' => $this->error,
            'denied' => $this->denied,
            ...($this->preliminary() ? ['preliminary' => true] : []),
            'timestamp' => $this->timestamp,
        ];
    }
}
