<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class Step implements Arrayable, JsonSerializable
{
    /**
     * @param  array<int, ToolCall>  $toolCalls
     * @param  array<int, ToolResult>  $toolResults
     */
    public function __construct(
        public string $text,
        public array $toolCalls,
        public array $toolResults,
        public FinishReason $finishReason,
        public Usage $usage,
        public Meta $meta,
    ) {}

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'tool_calls' => $this->toolCalls,
            'tool_results' => $this->toolResults,
            'finish_reason' => $this->finishReason->value,
            'usage' => $this->usage,
            'meta' => $this->meta,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
