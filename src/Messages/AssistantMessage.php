<?php

namespace Laravel\Ai\Messages;

use Illuminate\Support\Collection;

class AssistantMessage extends Message
{
    public Collection $toolCalls;

    /**
     * Raw provider content blocks in original order. When populated, gateways
     * replay these verbatim on follow-up requests instead of rebuilding
     * assistant content from text + tool calls — preserving provider-specific
     * blocks (server_tool_use, server_tool_result, thinking, ...) that the
     * rebuild would drop. When populated, this array is the source of truth
     * for outgoing assistant content; `content` and `toolCalls` are ignored
     * by the mapper.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $contentBlocks = [];

    /**
     * Create a new text conversation message instance.
     *
     * @param  array<int, array<string, mixed>>  $contentBlocks
     */
    public function __construct(string $content, ?Collection $toolCalls = null, array $contentBlocks = [])
    {
        parent::__construct('assistant', $content);

        $this->toolCalls = $toolCalls ?: new Collection;
        $this->contentBlocks = $contentBlocks;
    }
}
