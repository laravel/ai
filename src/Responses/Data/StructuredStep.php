<?php

namespace Laravel\Ai\Responses\Data;

class StructuredStep extends Step
{
    /**
     * @param  array<string, mixed>  $structured
     * @param  array<int, ToolCall>  $toolCalls
     * @param  array<int, ToolResult>  $toolResults
     * @param  array<string, mixed>  $additionalContent
     */
    public function __construct(
        string $text,
        public array $structured,
        array $toolCalls,
        array $toolResults,
        FinishReason $finishReason,
        Usage $usage,
        Meta $meta,
        array $additionalContent = [],
    ) {
        parent::__construct($text, $toolCalls, $toolResults, $finishReason, $usage, $meta, $additionalContent);
    }
}
