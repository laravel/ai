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
     * The "type:id" participant a paused turn was stored under, or null when the message did not come from a store that tracks pauses.
     */
    public ?string $participant = null;

    /**
     * Create a new text conversation message instance.
     *
     * @param  Collection<int, ToolCall>|null  $toolCalls
     * @param  array<int, array<string, mixed>>  $providerContentBlocks
     */
    public function __construct(string $content, ?Collection $toolCalls = null, array $providerContentBlocks = [], ?string $providerContentBlocksProvider = null, ?string $participant = null)
    {
        parent::__construct('assistant', $content);

        $this->toolCalls = $toolCalls ?: new Collection;
        $this->providerContentBlocks = $providerContentBlocks;
        $this->providerContentBlocksProvider = $providerContentBlocksProvider;
        $this->participant = $participant;
    }
}
