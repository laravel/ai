<?php

namespace Laravel\Ai\Messages;

use Illuminate\Support\Collection;

class AssistantMessage extends Message
{
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
     * @param  array<int, array<string, mixed>>  $providerContentBlocks
     */
    public function __construct(string $content, ?Collection $toolCalls = null, array $providerContentBlocks = [])
    {
        parent::__construct('assistant', $content);

        $this->toolCalls = $toolCalls ?: new Collection;
        $this->providerContentBlocks = $providerContentBlocks;
    }
}
