<?php

namespace Laravel\Ai\Responses;

use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

class AgentResponse extends TextResponse
{
    public string $invocationId;

    public ?string $conversationUuid = null;

    public ?object $conversationUser = null;

    public function __construct(string $invocationId, string $text, Usage $usage, Meta $meta)
    {
        $this->invocationId = $invocationId;

        parent::__construct($text, $usage, $meta);
    }

    /**
     * Set the conversation UUID and participant for this response.
     */
    public function withinConversation(string $conversationUuid, object $conversationUser): self
    {
        $this->conversationUuid = $conversationUuid;
        $this->conversationUser = $conversationUser;

        return $this;
    }

    /**
     * Execute a callback with this response.
     */
    public function then(callable $callback): self
    {
        $callback($this);

        return $this;
    }
}
