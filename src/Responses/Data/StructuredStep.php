<?php

namespace Laravel\Ai\Responses\Data;

class StructuredStep extends Step
{
    /**
     * @param  array<string, mixed>  $structured
     * @param  array<int, ToolCall>  $toolCalls
     * @param  array<int, ToolResult>  $toolResults
     * @param  array<int, array<string, mixed>>  $replayBlocks
     */
    public function __construct(
        string $text,
        public array $structured,
        array $toolCalls,
        array $toolResults,
        FinishReason $finishReason,
        Usage $usage,
        Meta $meta,
        string $reasoning,
        array $replayBlocks,
    ) {
        parent::__construct($text, $toolCalls, $toolResults, $finishReason, $usage, $meta, $reasoning, $replayBlocks);
    }

    /**
     * Get the instance as an array.
     */
    #[\Override]
    public function toArray(): array
    {
        return [...parent::toArray(), 'structured' => $this->structured];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    #[\Override]
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
