<?php

namespace Laravel\Ai\Responses;

use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

class AgentResponse extends TextResponse
{
    public string $invocationId;

    public ?string $conversationUuid = null;

    public function __construct(string $invocationId, string $text, Usage $usage, Meta $meta)
    {
        $this->invocationId = $invocationId;

        parent::__construct($text, $usage, $meta);
    }

    /**
     * Set the conversation UUID for this response.
     */
    public function withinConversation(?string $conversationUuid): self
    {
        $this->conversationUuid = $conversationUuid;

        return $this;
    }
}
