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
        public bool $preliminary = false,
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        // A failed call's result can carry the tool's exception message, so the client only learns that it failed...
        $failed = ! $this->successful && ! $this->denied;

        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'tool_result',
            'tool_id' => $this->toolResult->id,
            'tool_name' => $this->toolResult->name,
            'result' => $failed ? 'The tool call failed.' : $this->toolResult->result,
            'successful' => $this->successful,
            'error' => $failed ? 'The tool call failed.' : $this->error,
            'denied' => $this->denied,
            ...($this->preliminary ? ['preliminary' => true] : []),
            'timestamp' => $this->timestamp,
        ];
    }
}
