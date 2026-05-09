<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;

class SingleTurnResponse implements Arrayable, JsonSerializable
{
    /**
     * @param  ToolCall[]  $toolCalls
     * @param  array<string, mixed>|null  $structured  Parsed structured output from the provider.
     * @param  string|null  $responseId  Provider-specific response ID for stateful continuation (e.g. OpenAI previous_response_id).
     * @param  array<int, array<string, mixed>>  $providerContentBlocks  Raw provider content blocks for verbatim replay (e.g. Anthropic server_tool_use).
     */
    public function __construct(
        public string $text,
        public array $toolCalls,
        public FinishReason $finishReason,
        public Usage $usage,
        public Meta $meta,
        public ?array $structured = null,
        public ?string $responseId = null,
        public array $providerContentBlocks = [],
    ) {}

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'tool_calls' => array_map(fn (ToolCall $tc) => $tc->toArray(), $this->toolCalls),
            'finish_reason' => $this->finishReason->value,
            'usage' => $this->usage->toArray(),
            'meta' => $this->meta->toArray(),
            'structured' => $this->structured,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
