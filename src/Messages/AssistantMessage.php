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
     * Create a new text conversation message instance.
     *
     * @param  Collection<int, ToolCall>|null  $toolCalls
     * @param  array<int, array<string, mixed>>  $providerContentBlocks
     */
    public function __construct(string $content, ?Collection $toolCalls = null, array $providerContentBlocks = [])
    {
        parent::__construct('assistant', $content);

        $this->toolCalls = $toolCalls ?: new Collection;
        $this->providerContentBlocks = $providerContentBlocks;
    }
}
