<?php

namespace Laravel\Ai\Messages;

use Illuminate\Support\Collection;

class AssistantMessage extends Message
{
    public Collection $toolCalls;

    /**
     * Raw provider replay state. Carries provider-native content blocks
     * (server_tool_use, server_tool_result, thinking, ...) in original
     * order so gateways can replay them verbatim on follow-up requests —
     * blocks the default text + tool_calls rebuild would otherwise drop.
     *
     * Typically populated by the SDK's own response parser, not by user
     * code. When populated, this array is the source of truth for outgoing
     * assistant content; `content` and `toolCalls` are ignored by the
     * mapper.
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
