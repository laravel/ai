<?php

namespace Laravel\Ai\Contracts;

interface HasConversationMiddleware
{
    /**
     * Get the middleware that should transform the conversation before it is sent.
     *
     * @return array<int, ConversationMiddleware>
     */
    public function conversationMiddleware(): array;
}
