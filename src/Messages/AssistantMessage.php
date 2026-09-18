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
     * @var array<array-key, mixed>
     */
    public array $replayBlocks = [];

    /**
     * The provider the replay state belongs to, or null when it was produced within the current run.
     */
    public ?string $replayBlocksProvider = null;

    /**
     * Create a new text conversation message instance.
     *
     * @param  Collection<int, ToolCall>|null  $toolCalls
     * @param  array<array-key, mixed>  $replayBlocks
     */
    public function __construct(string $content, ?Collection $toolCalls = null, array $replayBlocks = [], ?string $replayBlocksProvider = null)
    {
        parent::__construct('assistant', $content);

        $this->toolCalls = $toolCalls ?: new Collection;
        $this->replayBlocks = $replayBlocks;
        $this->replayBlocksProvider = $replayBlocksProvider;
    }
}
