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
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'tool_result',
            'tool_id' => $this->toolResult->id,
            'tool_name' => $this->toolResult->name,
            'result' => $this->toolResult->result,
            'successful' => $this->successful,
            'error' => $this->error,
            'timestamp' => $this->timestamp,
        ];

        if (! is_null($this->toolResult->meta)) {
            $array['meta'] = $this->toolResult->meta;
        }

        return $array;
    }

    /**
     * {@inheritdoc}
     */
    public function toVercelProtocolArray(): ?array
    {
        $array = [
            'type' => 'tool-output-available',
            'toolCallId' => $this->toolResult->id,
            'output' => $this->toolResult->result,
        ];

        if (! is_null($this->toolResult->meta)) {
            $array['meta'] = $this->toolResult->meta;
        }

        return $array;
    }
}
