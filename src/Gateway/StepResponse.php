<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\Concerns\HasRawResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ProviderToolCall;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;

class StepResponse implements Arrayable, JsonSerializable
{
    use HasRawResponse;

    /**
     * @param  ToolCall[]  $toolCalls
     * @param  array<string, mixed>|null  $structured
     * @param  array<array-key, mixed>  $replayBlocks
     * @param  PendingApproval[]  $pendingApprovals
     * @param  ProviderToolCall[]  $providerToolCalls
     */
    public function __construct(
        public string $text,
        public array $toolCalls,
        public FinishReason $finishReason,
        public TextUsage $usage,
        public Meta $meta,
        public ?array $structured = null,
        public ?string $continuationToken = null,
        public array $replayBlocks = [],
        public array $pendingApprovals = [],
        public string $reasoning = '',
        public array $providerToolCalls = [],
    ) {}

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'structured' => $this->structured,
            'tool_calls' => array_map(fn (ToolCall $tc): array => $tc->toArray(), $this->toolCalls),
            'provider_tool_calls' => array_map(fn (ProviderToolCall $call): array => $call->toArray(), $this->providerToolCalls),
            'replay_blocks' => $this->replayBlocks,
            'finish_reason' => $this->finishReason->value,
            'usage' => $this->usage->toArray(),
            'meta' => $this->meta->toArray(),
            'continuation_token' => $this->continuationToken,
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
