<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;

/**
 * The outcome of a single provider turn, before {@see TextGenerationLoop} decides whether
 * to continue, dispatch tool calls, or terminate.
 */
class SingleTurnResponse implements Arrayable, JsonSerializable
{
    /**
     * @param  ToolCall[]  $toolCalls
     * @param  array<string, mixed>|null  $structured
     * @param  string|null  $responseId  Provider handle for stateful continuation (e.g. OpenAI's `previous_response_id`).
     * @param  array<int, array<string, mixed>>  $providerContentBlocks  Raw provider content blocks for verbatim replay.
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
