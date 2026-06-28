<?php

namespace Laravel\Ai\Responses\Data;

class StructuredStep extends Step
{
    /**
     * @param  array<string, mixed>  $structured
     * @param  array<int, ToolCall>  $toolCalls
     * @param  array<int, ToolResult>  $toolResults
     */
    public function __construct(
        string $text,
        public array $structured,
        array $toolCalls,
        array $toolResults,
        FinishReason $finishReason,
        Usage $usage,
        Meta $meta,
    ) {
        parent::__construct($text, $toolCalls, $toolResults, $finishReason, $usage, $meta);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [...parent::toArray(), 'structured' => $this->structured];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
