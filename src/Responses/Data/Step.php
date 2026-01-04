<?php

namespace Laravel\Ai\Responses\Data;

class Step
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
}
