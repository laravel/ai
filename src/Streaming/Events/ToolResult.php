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
            'type' => 'tool_result',
            'tool_id' => $this->toolResult->id,
            'tool_name' => $this->toolResult->name,
            'result' => $this->toolResult->result,
            'successful' => $this->successful,
            'error' => $this->error,
            'denied' => $this->denied,
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function toVercelProtocolArray(): ?array
    {
        if ($this->denied) {
            return [
                'type' => 'tool-output-denied',
                'toolCallId' => $this->toolResult->id,
            ];
        }

        if (! $this->successful) {
            return [
                'type' => 'tool-output-error',
                'toolCallId' => $this->toolResult->id,
                'errorText' => $this->error ?? 'The tool call failed.',
            ];
        }

        return [
            'type' => 'tool-output-available',
            'toolCallId' => $this->toolResult->id,
            'output' => $this->toolResult->result,
        ];
    }
}
