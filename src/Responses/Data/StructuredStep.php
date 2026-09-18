<?php

namespace Laravel\Ai\Responses\Data;

class StructuredStep extends Step
{
    /**
     * @param  array<string, mixed>  $structured
     * @param  array<int, ToolCall>  $toolCalls
     * @param  array<int, ToolResult>  $toolResults
     * @param  array<int, array<string, mixed>>  $providerContentBlocks
     */
    public function __construct(
        string $text,
        public array $structured,
        array $toolCalls,
        array $toolResults,
        FinishReason $finishReason,
        TextUsage $usage,
        Meta $meta,
        string $reasoning,
        array $providerContentBlocks,
    ) {
        parent::__construct($text, $toolCalls, $toolResults, $finishReason, $usage, $meta, $reasoning, $providerContentBlocks);
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
