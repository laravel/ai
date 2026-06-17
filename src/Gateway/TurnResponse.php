<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;

/** @internal */
class TurnResponse implements Arrayable, JsonSerializable
{
    /**
     * @param  ToolCall[]  $toolCalls
     * @param  array<string, mixed>|null  $structured
     * @param  string|null  $continuationToken  Provider handle for stateful continuation, e.g. OpenAI's response id; null for stateless providers.
     * @param  array<int, array<string, mixed>>  $providerContentBlocks  Opaque provider blocks the loop replays verbatim on the next {@see AssistantMessage} (e.g. Anthropic signed thinking, Bedrock reasoning); never inspected.
     */
    public function __construct(
        public string $text,
        public array $toolCalls,
        public FinishReason $finishReason,
        public Usage $usage,
        public Meta $meta,
        public ?array $structured = null,
        public ?string $continuationToken = null,
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
            'continuation_token' => $this->continuationToken,
            'provider_content_blocks' => $this->providerContentBlocks,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
