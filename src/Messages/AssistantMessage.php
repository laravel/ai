<?php

namespace Laravel\Ai\Messages;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\ToolCall;

class AssistantMessage extends Message
{
    /** @var Collection<int, ToolCall> */
    public Collection $toolCalls;

    /**
     * Raw provider replay state populated by the SDK's response parser.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $providerContentBlocks = [];

    /**
     * The provider the replay state belongs to, or null when it was produced within the current run.
     */
    public ?string $providerContentBlocksProvider = null;

    /**
     * Create a new text conversation message instance.
     *
     * @param  Collection<int, ToolCall>|null  $toolCalls
     * @param  array<int, array<string, mixed>>  $providerContentBlocks
     */
    public function __construct(string $content, ?Collection $toolCalls = null, array $providerContentBlocks = [], ?string $providerContentBlocksProvider = null)
    {
        parent::__construct('assistant', $content);

        $this->toolCalls = $toolCalls ?: new Collection;
        $this->providerContentBlocks = $providerContentBlocks;
        $this->providerContentBlocksProvider = $providerContentBlocksProvider;
    }

    /**
     * Get the per-step replay state entry persisted for this message when a paused turn spans multiple steps.
     *
     * @return array{tool_call_ids: array<int, string>, blocks: array<int|string, mixed>, content: string}
     */
    public function toReplayStep(): array
    {
        return [
            'tool_call_ids' => $this->toolCalls->pluck('id')->values()->all(),
            'blocks' => $this->providerContentBlocks,
            'content' => $this->content,
        ];
    }
}
