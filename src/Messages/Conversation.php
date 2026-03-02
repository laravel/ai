<?php

namespace Laravel\Ai\Messages;

class Conversation
{
    /**
     * The conversation ID.
     */
    public string $id;

    /**
     * The conversation title.
     */
    public string $title;

    /**
     * The conversation user ID.
     */
    public ?int $userId;

    /**
     * Create a new text conversation message instance.
     */
    public function __construct(string $id, string $title, ?int $userId = null)
    {
        $this->id = $id;
        $this->title = $title;
        $this->userId = $userId;
    }
}
